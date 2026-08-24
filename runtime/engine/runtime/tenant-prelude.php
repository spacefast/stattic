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
 * Trust model (owner ruling 2026-08-09): the TEAM is the boundary. Executable
 * PHP counts as runtime code, so the placement default is DEDICATED and a
 * publish carrying it onto a space on a SHARED site is rejected outright (fail
 * closed, no auto-migration), so the only spaces that ever share a box are
 * same-team, and they already trust each other. This function is keyed by
 * spaceId so one mechanism serves both permitted placements identically:
 *   * a DEDICATED SITE:          one space, one box, one engine pool;
 *   * a TEAM-SCOPED SHARED BOX:  N same-team spaces, one engine pool.
 * Each call pins `open_basedir` to THAT space's php-root, so on a shared box
 * space A's dispatch leaves space B's bytes unreadable (proven by the
 * cross-space probe in runtime/tests/tenant-prelude.test.ts). Cross-TEAM PHP
 * never co-locates: that is the PLACEMENT guarantee above, enforced control-
 * plane-side (finalize placement gate), out of this file's scope. The site is
 * the hard boundary the platform offers, and every space owns one.
 *
 * ---------------------------------------------------------------------------
 * What this prelude holds, and what it cannot
 * ---------------------------------------------------------------------------
 * HOLDS (runtime-settable, proven by the probe):
 *   1. FILE JAIL. `open_basedir` is PHP_INI_ALL and may be TIGHTENED at
 *      runtime (never widened: a tightened value refuses every later
 *      `ini_set`/`.user.ini`, so a handler cannot escape its own jail). Pinned
 *      to the space php-root + this request's scratch tmp, it denies the tenant
 *      every path outside its own version: a sibling space's php-root, the
 *      docroot `.atomic-persistent-data.json` (the raw wp.cloud config blob),
 *      the `.stattic/**` runtime namespace (jwks, jti, callbacks), and the
 *      engine sources. It also neutralises `new Atomic_Persistent_Data()`,
 *      whose ctor reads that docroot JSON; that read now fails on the jail.
 *   2. FLEET-SECRET SCRUB. The team owns its box, so a same-team tenant reading
 *      a SITE-scoped secret (DB password, `SMTP_PASS`, `ATOMIC_SITE_API_KEY`, a
 *      WordPress key) is not an escalation: they all belong to the one team the
 *      box is shared by. Only a FLEET-WIDE credential must be denied: the
 *      dispatch token (`SPACEFAST_FUNCTIONS_DISPATCH_TOKEN`) authenticates to the
 *      functions edge for every site, so a tenant that read it could impersonate
 *      another team's site. It and the persistent-data blob that embeds it are
 *      removed from `$_SERVER`, `$_ENV`, and the C environment (`putenv` with no
 *      `=`) before tenant code runs (STATTIC_TENANT_SCRUBBED_ENV). This is a
 *      STOPGAP until that credential is per-site (a JWT bound to the site), after
 *      which no fleet-wide secret is in the box env and this scrub is deleted.
 *
 * CANNOT (needs a wp.cloud provider request, a real per-space fpm pool):
 *   A. FUNCTION REMOVAL. `disable_functions` is PHP_INI_SYSTEM: it is read at
 *      module init and CANNOT be set from `ini_set` at runtime. This prelude
 *      does not remove `exec`/`system`/`popen`/`proc_open`/`pcntl_*`/`dl`, and
 *      must never pretend to. A tenant can still CALL them. The scrub
 *      denies a spawned subprocess every platform credential, and `open_basedir`
 *      bounds PHP's OWN file APIs, but NEITHER bounds a child process's
 *      filesystem view: any on-disk secret a subprocess can reach by path
 *      (a sibling space's blobs, the docroot config, `.stattic/**`, DB
 *      credentials materialised on disk) stays reachable through `exec`. That
 *      residual is exactly why cross-team PHP never co-locates. Real function
 *      removal is a pool `php_admin_value[disable_functions]`, a provider
 *      capability.
 *   B. UID / PROCESS SEPARATION. All spaces on one box share the engine pool's
 *      uid and its opcache SHM (which also carries every space's derived-cache
 *      sidecars); a same-box space cannot be denied at the kernel level, only
 *      at the PHP-API level above. Per-space uid + a disjoint master are
 *      provider capabilities.
 *   C. PER-REQUEST INI RESET is load-bearing. Because `open_basedir` cannot be
 *      widened once tightened, re-pinning per space on a shared box only works
 *      because php-fpm restores ini to the pool baseline at the end of every
 *      request (php_request_shutdown). If tenant `.php` were ever executed in
 *      the SAME request after this prelude for a DIFFERENT space, the first
 *      pin would trap the second. One request serves exactly one space's
 *      tenant file. The dispatch caller must honour that.
 */

/**
 * The FLEET-WIDE secrets tenant code and its subprocesses must never keep (see
 * HOLDS.2 above). `SPACEFAST_FUNCTIONS_DISPATCH_TOKEN` authenticates to the
 * functions edge for every site, so a tenant on site A reading it could
 * impersonate site B, a DIFFERENT team. The raw persistent-data blob embeds it,
 * so it goes too. The individual site-scoped env vars stay intact.
 *
 * STOPGAP: a denylist only until the dispatch credential is per-site (a JWT
 * bound to the site's instance id), after which no fleet-wide secret sits in the
 * box env and this scrub can be deleted outright. Denylist, not allowlist,
 * precisely because that end state is "nothing here needs scrubbing".
 */
const STATTIC_TENANT_SCRUBBED_ENV = [
    'SPACEFAST_FUNCTIONS_DISPATCH_TOKEN',
    'SPACEFAST_ATOMIC_PERSISTENT_DATA_JSON',
];

/**
 * Remove each fleet-wide secret from the three surfaces a PHP handler (and any
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
 * @param string $spaceId     the space whose tenant file is about to run; the
 *                            KEY that selects which php-root gets pinned, so on
 *                            a shared box each space's dispatch pins its own.
 * @param string $phpRoot     absolute path to that space's version php-root,
 *                            the ONLY tenant code the worker may read.
 * @param string $scratchTmp  absolute path to this request's scratch tmp dir
 *                            (uploads, sessions, sys temp). Must be dedicated to
 *                            this space; never the platform's, never a sibling's.
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

    // Scratch surfaces land in this space's own tmp, never the platform's and
    // never a sibling's. These are PHP_INI_ALL and safe to set at runtime.
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
