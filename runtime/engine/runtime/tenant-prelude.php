<?php

declare(strict_types=1);

/**
 * In-process tenant hardening prelude: containment before capability
 * (owner ruling 2026-08-09).
 *
 * ---------------------------------------------------------------------------
 * The corrected containment model
 * ---------------------------------------------------------------------------
 * Tenant PHP runs in the engine's OWN php-fpm pool, the only pool a wp.cloud
 * site is given. A site owns file content under /srv/htdocs and its persistent
 * data. It owns NONE of the OS-level isolation knobs: no second fpm master,
 * no per-space uid, no site-level `disable_functions`, `open_basedir`,
 * `clear_env`, and no accel-ignoring nginx location (verified:
 * .agents/skills/wpcloud-runtime-behavior, private-notes:internal-docs/platform.md). The
 * only OS-level isolation primitive wp.cloud offers is a SEPARATE SITE.
 *
 * So until a provider request buys those knobs, containment is IN-PROCESS: the
 * engine hardens its own worker before it hands control to any tenant `.php`.
 * `custom-redirects.php` is already the `auto_prepend` front controller that
 * runs before every request; the committed-`.php` dispatch path
 * (runtime/engine/runtime/serve.php `_stattic_v4_dispatch_action`, action
 * `ACTION_PHP`) hands off to runtime/engine/runtime/php-functions.php, which
 * calls this prelude immediately before including the tenant file.
 *
 * Each Space owns one site, box, and engine pool. This function pins
 * `open_basedir` to the active version's php-root before tenant code runs. The
 * site is the OS isolation boundary; this prelude narrows the filesystem and
 * environment inside that boundary.
 *
 * ---------------------------------------------------------------------------
 * What this prelude holds, and what it cannot
 * ---------------------------------------------------------------------------
 * HOLDS (runtime-settable, proven by the probe):
 *   1. FILE JAIL. `open_basedir` is PHP_INI_ALL and may be TIGHTENED at
 *      runtime (never widened: a tightened value refuses every later
 *      `ini_set`/`.user.ini`, so a handler cannot escape its own jail). Pinned
 *      to the space php-root + this request's scratch tmp, it denies the tenant
 *      every path outside its own version: the docroot
 *      `.atomic-persistent-data.json` (the raw wp.cloud config blob), the
 *      `.stattic/**` runtime namespace (jwks, jti, callbacks), and the engine
 *      sources. It also neutralises `new Atomic_Persistent_Data()`, whose ctor
 *      reads that docroot JSON; that read now fails on the jail.
 *   2. CONTROL-CREDENTIAL SCRUB. The per-box functions dispatch token and the
 *      persistent-data blob that embeds it are platform control credentials,
 *      not tenant inputs. They are removed from `$_SERVER`, `$_ENV`, and the C
 *      environment (`putenv` with no `=`) before tenant code runs.
 *
 * CANNOT (needs a wp.cloud provider request and a separate tenant fpm pool):
 *   A. FUNCTION REMOVAL. `disable_functions` is PHP_INI_SYSTEM: it is read at
 *      module init and CANNOT be set from `ini_set` at runtime. This prelude
 *      does not remove `exec`/`system`/`popen`/`proc_open`/`pcntl_*`/`dl`, and
 *      must never pretend to. A tenant can still CALL them. The scrub
 *      denies a spawned subprocess every platform credential, and `open_basedir`
 *      bounds PHP's OWN file APIs, but NEITHER bounds a child process's
 *      filesystem view: any on-disk secret a subprocess can reach by path (the
 *      docroot config, `.stattic/**`, credentials materialised on disk) stays
 *      reachable through `exec`. Real function removal is a pool
 *      `php_admin_value[disable_functions]`, a provider capability.
 *   B. UID / PROCESS SEPARATION. Tenant code and the engine use the site's pool
 *      uid and opcache SHM. A per-tenant uid and disjoint master are provider
 *      capabilities.
 *   C. PER-REQUEST INI RESET is load-bearing. Because `open_basedir` cannot be
 *      widened once tightened, php-fpm must restore ini to the pool baseline at
 *      the end of every request (`php_request_shutdown`). The next request can
 *      then pin the active version's current php-root.
 */

/**
 * Platform control credentials tenant code and its subprocesses must never
 * keep. The raw persistent-data blob embeds the dispatch token, so both go.
 * Application credentials used by the site's PHP runtime stay intact.
 */
const STATTIC_TENANT_SCRUBBED_ENV = [
    'SPACEFAST_FUNCTIONS_DISPATCH_TOKEN',
    'SPACEFAST_ATOMIC_PERSISTENT_DATA_JSON',
];

/**
 * Remove each control credential from the three surfaces a PHP handler (and any
 * subprocess it spawns) can read: the C environment (`getenv`), `$_ENV`, and
 * the `$_SERVER` copy php-fpm makes of the environment when `clear_env` is off.
 * FastCGI request params also live in `$_SERVER`, but none of these names is a
 * request param, so nothing tenant code legitimately needs is touched.
 */
function _stattic_tenant_scrub_secret_env(): void
{
    foreach (STATTIC_TENANT_SCRUBBED_ENV as $name) {
        unset($_SERVER[$name]);
        unset($_ENV[$name]);
        // `putenv` with a bare name (no `=`) removes the variable from the C
        // environment, so `getenv` returns false and a child process inherits
        // nothing. `putenv` is available here because this runs as the ENGINE,
        // before any tenant code. It is exactly what a provider `clear_env`
        // would do for us if we had a pool of our own.
        putenv($name);
    }
}

/**
 * Harden the current php-fpm worker in place before it executes a tenant
 * `.php` for `$spaceId`. Fail-closed: on any bad input or a jail that does not
 * take effect it throws, and the dispatch caller must abort the request (a 500
 * platform-invariant response) rather than run tenant code unhardened.
 *
 * @param string $spaceId     the space whose tenant file is about to run.
 * @param string $phpRoot     absolute path to that space's version php-root,
 *                            the ONLY tenant code the worker may read.
 * @param string $scratchTmp  absolute path to this request's scratch tmp dir
 *                            (uploads, sessions, sys temp). Reserved for this
 *                            space, never the platform's.
 */
function _stattic_tenant_harden(string $spaceId, string $phpRoot, string $scratchTmp): void
{
    if ($spaceId === '') {
        throw new \RuntimeException('tenant harden: empty spaceId');
    }

    // Scrub first: even if the jail step below throws, the secrets are already
    // gone from the process, so an error path can never leak them to a handler.
    _stattic_tenant_scrub_secret_env();

    $rootReal = realpath($phpRoot);
    $tmpReal = realpath($scratchTmp);
    if ($rootReal === false || !is_dir($rootReal)) {
        throw new \RuntimeException("tenant harden: php-root missing for {$spaceId}: {$phpRoot}");
    }
    if ($tmpReal === false || !is_dir($tmpReal)) {
        throw new \RuntimeException("tenant harden: scratch tmp missing for {$spaceId}: {$scratchTmp}");
    }

    // Scratch surfaces land in this space's own tmp, never the platform's.
    // These are PHP_INI_ALL and safe to set at runtime.
    ini_set('upload_tmp_dir', $tmpReal);
    ini_set('sys_temp_dir', $tmpReal);
    ini_set('session.save_path', $tmpReal);

    // The jail (see HOLDS.1). A pool `open_basedir` that does not already
    // contain the target rejects this tighten and leaves the value unchanged.
    // The verify catches that and fails closed rather than run tenant code
    // outside a jail it believes is in force.
    $jail = $rootReal . PATH_SEPARATOR . $tmpReal;
    ini_set('open_basedir', $jail);
    if (ini_get('open_basedir') !== $jail) {
        throw new \RuntimeException(
            "tenant harden: open_basedir did not take effect for {$spaceId} "
            . '(expected ' . $jail . ', got ' . (string) ini_get('open_basedir') . ')',
        );
    }

    // We deliberately do NOT touch `disable_functions` (see CANNOT.A): it is
    // PHP_INI_SYSTEM, so `ini_set` would silently no-op and fake a boundary that
    // does not exist.
}
