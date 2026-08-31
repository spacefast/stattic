<?php

declare(strict_types=1);

/**
 * Tenant-PHP containment probe for the in-process hardening prelude
 * (runtime/engine/runtime/tenant-prelude.php). Stands in for a real tenant
 * `.php`: it requires the prelude, runs it for ONE space, then attempts every
 * escape the prelude must deny and reports structured JSON — it never trusts
 * that an attempt threw, it reports what happened so the caller decides.
 *
 * runtime/tests/tenant-prelude.test.ts runs it under the local `php` CLI. The
 * docker fixture also ships it so nginx and fpm can exercise the same proof.
 *
 * Config arrives as environment / fastcgi params so one file serves any space:
 *   STATTIC_PRELUDE_PATH        absolute path to tenant-prelude.php to require
 *   STATTIC_PROBE_SPACE_ID      the space this run pins
 *   STATTIC_PROBE_PHP_ROOT      this space's php-root (the jail head)
 *   STATTIC_PROBE_SCRATCH_TMP   this space's scratch tmp (the jail tail)
 *   STATTIC_PROBE_SELF_SECRET   a file inside this space's php-root  (readable)
 *   STATTIC_PROBE_OUTSIDE_SECRET a file outside the version root (blocked)
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
$outsideSecret = probe_cfg('STATTIC_PROBE_OUTSIDE_SECRET');
$docrootConfig = probe_cfg('STATTIC_PROBE_DOCROOT_CONFIG');
$statticSecret = probe_cfg('STATTIC_PROBE_STATTIC_SECRET');

if (!is_file($preludePath)) {
    http_response_code(500);
    echo json_encode(['fatal' => "prelude not found: {$preludePath}"]);
    exit;
}

// Baseline BEFORE the prelude: the outside file is readable, proving the
// jail (not some ambient filesystem permission) is what closes it afterwards.
$baselineOutside = try_read($outsideSecret);

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
// env before spawning; the caller decides which must survive for application
// execution and which platform control credentials must be gone.
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

// Control credentials must be scrubbed. Application credentials must survive.
$controlEnv = [];
foreach (['SPACEFAST_FUNCTIONS_DISPATCH_TOKEN', 'SPACEFAST_ATOMIC_PERSISTENT_DATA_JSON'] as $name) {
    $surfaces = $env_surfaces($name);
    if ($surfaces !== []) {
        $controlEnv[$name] = $surfaces;
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
    'baseline_read_outside_before_prelude' => $baselineOutside,
    'read_self' => try_read($selfSecret),
    'read_outside' => try_read($outsideSecret),
    'read_docroot_config' => try_read($docrootConfig),
    'read_stattic_secret' => try_read($statticSecret),
    'leaked_control_env' => (object) $controlEnv,
    'surviving_site_env' => (object) $siteEnv,
    'dangerous_functions_still_callable' => $dangerousStillCallable,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
