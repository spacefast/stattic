<?php

declare(strict_types=1);

/**
 * Tenant-PHP containment probe for the in-process hardening prelude
 * (runtime/engine/runtime/tenant-prelude.php). Stands in for a real tenant
 * `.php`: it requires the prelude, runs it for ONE space, then attempts every
 * escape the prelude must deny and reports structured JSON — it never trusts
 * that an attempt threw, it reports what happened so the caller decides.
 *
 * Two lanes drive this one file:
 *   * runtime/tests/tenant-prelude.test.ts runs it under the local `php` CLI,
 *     two spaces on one simulated box, and asserts the cross-space boundary;
 *   * the docker fixture (this directory) ships it so the future `t=php`
 *     routing slice can exercise it through real nginx + fpm, where the accel
 *     and pool halves of the provider request also get proven.
 *
 * Config arrives as environment / fastcgi params so one file serves any space:
 *   STATTIC_PRELUDE_PATH        absolute path to tenant-prelude.php to require
 *   STATTIC_PROBE_SPACE_ID      the space this run pins
 *   STATTIC_PROBE_PHP_ROOT      this space's php-root (the jail head)
 *   STATTIC_PROBE_SCRATCH_TMP   this space's scratch tmp (the jail tail)
 *   STATTIC_PROBE_SELF_SECRET   a file inside this space's php-root  (readable)
 *   STATTIC_PROBE_SIBLING_SECRET a file inside ANOTHER space's php-root (blocked)
 *   STATTIC_PROBE_DOCROOT_CONFIG the docroot .atomic-persistent-data.json (blocked)
 *   STATTIC_PROBE_STATTIC_SECRET a file under docroot .stattic/**        (blocked)
 */

header('Content-Type: application/json');

/** A config value from a fastcgi param ($_SERVER) or the CLI env. */
function probe_cfg(string $key): string
{
    if (is_string($_SERVER[$key] ?? null) && $_SERVER[$key] !== '') {
        return $_SERVER[$key];
    }
    $env = getenv($key);
    return is_string($env) ? $env : '';
}

/** Try to read a path; report the bytes on success, the error class on failure. */
function try_read(string $path): array
{
    if ($path === '') {
        return ['ok' => false, 'bytes' => null, 'error' => 'no-path'];
    }
    $error = null;
    set_error_handler(static function (int $_no, string $msg) use (&$error): bool {
        $error = $msg;
        return true;
    });
    $contents = file_get_contents($path);
    restore_error_handler();
    return [
        'ok' => is_string($contents),
        'bytes' => is_string($contents) ? trim($contents) : null,
        'error' => $error,
    ];
}

$preludePath = probe_cfg('STATTIC_PRELUDE_PATH');
$spaceId = probe_cfg('STATTIC_PROBE_SPACE_ID');
$phpRoot = probe_cfg('STATTIC_PROBE_PHP_ROOT');
$scratchTmp = probe_cfg('STATTIC_PROBE_SCRATCH_TMP');
$selfSecret = probe_cfg('STATTIC_PROBE_SELF_SECRET');
$siblingSecret = probe_cfg('STATTIC_PROBE_SIBLING_SECRET');
$docrootConfig = probe_cfg('STATTIC_PROBE_DOCROOT_CONFIG');
$statticSecret = probe_cfg('STATTIC_PROBE_STATTIC_SECRET');

if (!is_file($preludePath)) {
    http_response_code(500);
    echo json_encode(['fatal' => "prelude not found: {$preludePath}"]);
    exit;
}

// Baseline BEFORE the prelude: the sibling secret is readable, proving the
// jail (not some ambient filesystem permission) is what closes it afterwards.
$baselineSibling = try_read($siblingSecret);

require $preludePath;
_stattic_tenant_harden($spaceId, $phpRoot, $scratchTmp);

// The exec family stays callable — `disable_functions` is PHP_INI_SYSTEM and
// cannot be removed at runtime. Reported so the proof records the known gap
// rather than hiding it.
$dangerousStillCallable = [];
foreach (['exec', 'system', 'shell_exec', 'passthru', 'popen', 'proc_open'] as $fn) {
    $dangerousStillCallable[$fn] = function_exists($fn);
}

// Per-name env surfaces after the prelude. The test seeds each into the process
// env before spawning; the caller decides which must survive (site-scoped, the
// team owns the box) and which must be gone from EVERY surface a handler or
// subprocess reads (fleet-wide credentials).
$env_surfaces = static function (string $name): array {
    $surfaces = [];
    if (($_SERVER[$name] ?? null) !== null) {
        $surfaces[] = 'SERVER';
    }
    if (($_ENV[$name] ?? null) !== null) {
        $surfaces[] = 'ENV';
    }
    if (getenv($name) !== false) {
        $surfaces[] = 'getenv';
    }
    return $surfaces;
};

// The fleet-wide credentials that MUST be scrubbed (empty surfaces) and the
// site-scoped secrets that MUST survive (the team owns the box).
$fleetEnv = [];
foreach (['SPACEFAST_FUNCTIONS_DISPATCH_TOKEN', 'SPACEFAST_ATOMIC_PERSISTENT_DATA_JSON'] as $name) {
    $surfaces = $env_surfaces($name);
    if ($surfaces !== []) {
        $fleetEnv[$name] = $surfaces;
    }
}
$siteEnv = [];
foreach (['DB_PASSWORD', 'DB_USER', 'AUTH_KEY', 'SPACEFAST_ACCESS_JWT'] as $name) {
    $surfaces = $env_surfaces($name);
    if ($surfaces !== []) {
        $siteEnv[$name] = $surfaces;
    }
}

echo json_encode([
    'space_id' => $spaceId,
    'open_basedir' => ini_get('open_basedir'),
    'baseline_read_sibling_before_prelude' => $baselineSibling,
    'read_self' => try_read($selfSecret),
    'read_sibling_space' => try_read($siblingSecret),
    'read_docroot_config' => try_read($docrootConfig),
    'read_stattic_secret' => try_read($statticSecret),
    'leaked_fleet_env' => (object) $fleetEnv,
    'surviving_site_env' => (object) $siteEnv,
    'dangerous_functions_still_callable' => $dangerousStillCallable,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
