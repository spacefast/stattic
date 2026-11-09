<?php
/**
 * Plugin Name: Spacefast Content Loader
 * Description: Loads the Spacefast engine this site selects.
 */
declare(strict_types=1);

// The thin loader. Every PHP entry the engine installs into the document root
// is a copy of this file: the provider's auto_prepend (custom-redirects.php),
// index.php, the /__spacefast/*.php entrypoints and the WordPress mu-plugin
// (wp-content/mu-plugins/spacefast-content.php). It does not change with the
// engine. Beyond the guards that must run before any engine can (the PHP
// version, the managed-host HTTPS upgrade, a refused install root), it only
// picks an engine and hands the request to that engine's own loader body,
// engine/loader/request.php or engine/loader/wordpress.php, which holds
// everything that does change: the publication lock, the install journal,
// request classification and the engine's own requires.
//
// The engine is the Spacefast chroot revision that the site's WP Cloud
// persistent data names in SPACEFAST_RUNTIME_ENGINE_SOURCE
// (`chroot/<environment>/<lane>`), or else the site's own release under
// .stattic/releases/. The channel link is resolved to its revision directory
// and the body is required from THAT path, never through the link: FPM runs
// opcache.validate_timestamps=Off, so only a path per revision keeps the
// compiled body in step with the bytes on disk. A chroot move therefore needs
// no work on the site: requests resolve the new revision once PHP's realpath
// cache entry for the link expires (realpath_cache_ttl).
//
// engine/shared/engine-selection.php restates the selection below for the
// control plane's scheduled and SSH commands, which run no loader. Keep the two
// in step; runtime/tests/engine-source.test.ts runs both.
//
// A chroot engine is current on a site only while every copy of this file
// there is byte-identical to that revision's: the engine-update receipt and the
// component stage both compare bytes. So any change here, the generated HTTPS
// apexes included, needs an install on every site, and a chroot move that does
// not change this file needs none. $loaderVersion is the hand-off contract the
// body receives (`loader_version`, which health reports); bump it when the
// hand-off changes, and keep bodies accepting the previous one until every
// site carries the new loader.

(static function (): void {
    $loaderVersion = 1;
    $wordpress = basename(__DIR__) === 'mu-plugins';
    if (!$wordpress) {
        // First thing on every request: PHP reports a multipart form it stopped
        // reading part way (past max_input_vars, max_file_uploads or
        // max_multipart_body_parts) as a request-startup warning, readable only
        // until something else warns or clears it. The relay lanes refuse such
        // a form. engine/init.php captures it too, for the lanes that start
        // there; the first capture wins.
        if (!array_key_exists('SPACEFAST_REQUEST_STARTUP_ERROR', $GLOBALS)) {
            $GLOBALS['SPACEFAST_REQUEST_STARTUP_ERROR'] = error_get_last();
        }
        // The provider owns the error-log destination; the runtime owns whether
        // PHP reports to it. Keep diagnostics out of visitor responses without
        // reducing severity or disabling the provider's log pipeline.
        error_reporting(E_ALL);
        ini_set('log_errors', '1');
        ini_set('display_errors', '0');
        if (PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600) {
            error_log('Spacefast runtime requires PHP 8.5; running PHP ' . PHP_VERSION);
            if (PHP_SAPI !== 'cli') {
                http_response_code(500);
            }
            exit(1);
        }
        // A CLI process with no request to serve is a tool loading WordPress,
        // the purge worker or wp-cli, not a visitor. Classifying it as a
        // request for '/' would serve the space and exit() the tool mid-run.
        // Simulated-request drivers (FPM always, the test harness by contract)
        // carry REQUEST_METHOD and still classify.
        if (PHP_SAPI === 'cli' && !isset($_SERVER['REQUEST_METHOD'])) {
            return;
        }

        // wp.cloud sets HTTPS from the visitor connection before this prepend.
        // Forwarding headers are visitor input here, never a scheme authority.
        // Run before engine selection, route/access decisions and cookie writes,
        // so even a site whose engine is broken or mid-update upgrades.
        if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
            // BEGIN GENERATED managed HTTPS apexes — DO NOT EDIT
            // Source: packages/common/src/config/domains.ts (production current and legacy apexes).
            // Regenerate: bun --filter @spacefast/control-plane runtime:codegen-policy
            $httpsApexes = ['space.fast', 'view.fast'];
            // END GENERATED managed HTTPS apexes
            $authority = (string) ($_SERVER['HTTP_HOST'] ?? '');
            $hostname = strtolower(rtrim(preg_replace('/:[0-9]+\z/', '', trim($authority)), '.'));
            $managed = false;
            foreach ($httpsApexes as $apex) {
                if ($hostname === $apex || str_ends_with($hostname, '.' . $apex)) {
                    $managed = true;
                    break;
                }
            }
            if ($managed) {
                $target = (string) ($_SERVER['REQUEST_URI'] ?? '/');
                // Reflect only a DNS authority and an origin-form request target.
                // Keep encoded path/query bytes intact; never decode into a header.
                $validAuthority = strlen($hostname) <= 253
                    && preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.?(?::([0-9]{1,5}))?\z/i', $authority, $parts) === 1
                    && (!isset($parts[1]) || ((int) $parts[1] >= 1 && (int) $parts[1] <= 65535));
                $validTarget = str_starts_with($target, '/')
                    && preg_match('/[\x00-\x20\x7f\\\\#]/', $target) === 0;
                // A redirect cached without the visitor scheme in its cache key
                // would loop on HTTPS. Do not let an edge or browser store it.
                header('Cache-Control: private, no-store', true);
                if (!$validAuthority || !$validTarget) {
                    http_response_code(400);
                    exit;
                }
                // HTTP ports do not carry over to the managed host's TLS listener.
                header('Location: https://' . $hostname . $target, true, 308);
                exit;
            }
            // Custom domains remain under the provider's certificate-aware HTTPS
            // policy. This loader has no certificate-readiness evidence for them.
            // Local hosts and the development apex also keep plain HTTP working.
        }
    }

    // A request answered here is retried: nothing has run yet.
    $unavailable = static function () use ($wordpress): void {
        if ($wordpress) {
            return;
        }
        http_response_code(503);
        header('Retry-After: 1', true);
        header('Content-Type: application/problem+json', true);
        echo json_encode([
            'type' => 'https://spacefast.com/problems/runtime_engine_update_busy',
            'title' => 'Runtime engine update busy',
            'status' => 503,
            'code' => 'runtime_engine_update_busy',
        ]);
        exit;
    };

    $publicRoot = $wordpress
        ? dirname(__DIR__, 2)
        : (basename(__DIR__) === '__spacefast' ? dirname(__DIR__) : __DIR__);
    $installRoot = $publicRoot . '/.stattic';
    // The install root is the site's own, never a link to somewhere else, and
    // a request never runs before the installer has created its lock.
    if (
        is_link($installRoot)
        || (!$wordpress && (is_link($installRoot . '/publication.lock') || !is_file($installRoot . '/publication.lock')))
    ) {
        $unavailable();
        return;
    }

    $loader = [
        'loader_version' => $loaderVersion,
        'script' => basename(__FILE__),
        'public_root' => $publicRoot,
    ];
    $engineRoot = $GLOBALS['SPACEFAST_RUNTIME_ACTIVE_RELEASE_ROOT'] ?? null;
    if (is_string($engineRoot)) {
        // An earlier pass of this request, or the control plane's command
        // bootstrap, already chose the engine. Every pass runs the same one.
        $loader['install_root'] = $GLOBALS['SPACEFAST_RUNTIME_INSTALL_ROOT'] ?? realpath($installRoot);
        $loader['engine_root'] = $engineRoot;
    } else {
        if (!$wordpress && !defined('DB_PASSWORD')) {
            // The selection is encrypted persistent data, keyed by DB_PASSWORD,
            // which the provider defines only after this prepend returns. It
            // defines it the same way. Outside the CLI the password leaves the
            // process environment before any engine code can read it there.
            $databasePassword = getenv('DB_PASSWORD');
            if (is_string($databasePassword)) {
                define('DB_PASSWORD', $databasePassword);
                if (PHP_SAPI !== 'cli') {
                    unset($_SERVER['DB_PASSWORD'], $_ENV['DB_PASSWORD']);
                    putenv('DB_PASSWORD');
                }
            }
            unset($databasePassword);
        }
        $installReal = realpath($installRoot);
        if (!is_string($installReal)) {
            if (!$wordpress) {
                exit(1);
            }
            return;
        }
        $loader['install_root'] = $installReal;

        // Which engine: the reasons are the ones health reports as
        // engine_source_reason.
        $siteReason = 'unselected';
        $chrootRevision = null;
        $selection = class_exists('Atomic_Persistent_Data')
            ? (new Atomic_Persistent_Data())->SPACEFAST_RUNTIME_ENGINE_SOURCE
            : false;
        if ($selection === false) {
            // The provider defines the class in its prepend. A PHP process
            // without it (a bare CLI, a local stack) has nothing to select with.
            $siteReason = 'no_persistent_data';
        } elseif (preg_match('#^chroot/(staging|production)/(latest|previous)$#D', is_string($selection) ? trim($selection) : '', $selected) === 1) {
            // Tests stand a chroot up under a temporary root; the box has it at `/`.
            $chrootRoot = defined('SPACEFAST_RUNTIME_CHROOT_ROOT') ? (string) SPACEFAST_RUNTIME_CHROOT_ROOT : '';
            $scriptsReal = realpath($chrootRoot . '/scripts');
            $revisionReal = realpath($chrootRoot . '/scripts/sf-engine-' . $selected[1] . '-' . $selected[2]);
            $inventory = null;
            if (
                !is_string($scriptsReal)
                || !is_string($revisionReal)
                || dirname($revisionReal) !== $scriptsReal
                || preg_match('/^sf-engine-([0-9a-f]{40})$/D', basename($revisionReal), $revision) !== 1
            ) {
                $siteReason = 'chroot_missing';
            } elseif (
                // A revision without loader bodies predates this loader.
                !is_file($revisionReal . '/engine/loader/request.php')
                || !is_file($revisionReal . '/engine/loader/wordpress.php')
                || !is_file($revisionReal . '/engine/shared/engine-selection.php')
                || !is_file($revisionReal . '/engine/init.php')
            ) {
                $siteReason = 'chroot_engine_unsupported';
                $chrootRevision = $revision[1];
            } else {
                $chrootRevision = $revision[1];
                // Read the publisher's capability claim as data; never execute
                // candidate PHP to ask whether it is safe.
                $inventory = json_decode((string) @file_get_contents($revisionReal . '/engine/wordpress/components.json'), true);
                $contained = false;
                foreach (is_array($inventory['components'] ?? null) ? $inventory['components'] : [] as $component) {
                    if (
                        is_array($component)
                        && ($component['id'] ?? null) === 'runtime-engine'
                        && is_array($component['provides'] ?? null)
                        && in_array('runtime.security.tenant-containment.v1', $component['provides'], true)
                    ) {
                        $contained = true;
                        break;
                    }
                }
                // The binary is named by the revision the link resolved to,
                // never through its own channel link, so a deploy that moves
                // both links between two reads cannot pair one revision's
                // engine with another's binary.
                $binary = $chrootRoot . '/usr/local/bin/sf-bin-' . $revision[1] . '/stattic-runtime';
                if (!$contained) {
                    $siteReason = 'chroot_containment_missing';
                } elseif (!is_file($binary) || !is_executable($binary)) {
                    $siteReason = 'chroot_binary_missing';
                } else {
                    $loader['engine_root'] = $revisionReal;
                    $loader['native_binary'] = $binary;
                    $loader['engine_source'] = 'chroot';
                    $loader['engine_source_reason'] = 'chroot';
                    $loader['chroot_revision'] = $revision[1];
                }
            }
        }

        if (!isset($loader['engine_root'])) {
            // The site's own release. A missing or incomplete chroot lands
            // here too, so a site switched back to the generic chroot keeps
            // serving.
            $pointer = is_file($installRoot . '/active-release')
                ? file_get_contents($installRoot . '/active-release', false, null, 0, 256)
                : false;
            $target = is_string($pointer) ? trim($pointer) : '';
            $releaseReal = preg_match('#^releases/[A-Za-z0-9._-]+$#', $target) === 1
                ? realpath($installRoot . '/' . $target)
                : false;
            if (!is_string($releaseReal) || !str_starts_with($releaseReal, $installReal . '/releases/')) {
                // A first install lays this loader down before it publishes
                // the release pointer, with its journal open. A request in
                // that window is retried; only a site with no install under
                // way has a broken pointer.
                if (file_exists($installRoot . '/install-transaction.json') || is_link($installRoot . '/install-transaction.json')) {
                    $unavailable();
                }
                if (!$wordpress) {
                    exit(1);
                }
                return;
            }
            $loader['engine_root'] = $releaseReal;
            $loader['native_binary'] = $releaseReal . '/bin/stattic-runtime';
            $loader['engine_source'] = 'site';
            $loader['engine_source_reason'] = $siteReason;
            $loader['chroot_revision'] = $chrootRevision;
        }
    }

    $body = $loader['engine_root'] . '/engine/loader/' . ($wordpress ? 'wordpress.php' : 'request.php');
    if (!is_file($body)) {
        // Only an engine chosen before this loader was installed lacks one.
        $unavailable();
        return;
    }
    (require $body)($loader);
})();
