<?php
declare(strict_types=1);

/**
 * Native scheduled jobs: resolving one declared cron to the request that runs it.
 *
 * A wp.cloud crontab entry runs `entrypoints/cron.php --key=<key>` on the box
 * once per schedule. This file answers the two questions that entry has: WHICH
 * path does that key name, and WHAT does the request have to carry so the
 * handler can tell the platform fired it.
 *
 * The manifest is the compiler's, read out of the ACTIVE version's file catalog
 * — `__spacefast/crons.json`, the same document `sf.jsonc` declared. The catalog
 * (metadata.json, written by finalize) is the version's authoritative file list,
 * so this reads what the version actually shipped rather than a second copy of
 * it. The path is a runtime control path a publish can never write
 * (admin/upload-policy.php reserves the whole `__spacefast` segment, with the
 * bundle prefix and the Zero deploy record as its only exceptions), so a
 * declared cron can only have come through finalize.
 */

require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/storage.php';
require_once __DIR__ . '/../shared/lock.php';
require_once __DIR__ . '/../shared/pointers.php';
require_once __DIR__ . '/../shared/artifacts.php';

const STATTIC_CRONS_MANIFEST_PATH = '__spacefast/crons.json';
const STATTIC_CRONS_MANIFEST_MAX_BYTES = 262144;
// The request param the dispatcher asserts and the front door strips. Named
// once here; context.php reads this constant for the strip.
const STATTIC_CRON_REQUEST_PARAM = 'HTTP_X_SPACEFAST_CRON';
// The tenant's own shared secret, when the space declared one. Vercel's
// contract, and the only half of the proof a handler can actually verify.
const STATTIC_CRON_SECRET_VARIABLE = 'CRON_SECRET';

/**
 * The space and active version one hostname resolves to.
 *
 * The dispatcher needs these BEFORE a request exists — the manifest read is
 * what decides which request to make — so it walks the same pointers
 * runtime/serve.php walks, through the same helpers, rather than carrying its
 * own idea of what "active" means. It stops at the version: access, tombstones
 * and rules are the SERVE path's to apply, and the invocation goes through it.
 *
 * @return array{kind: 'present', space_id: string, version_id: string}|array{kind: 'absent'|'unavailable'}
 */
function _stattic_cron_target(string $privateRoot, string $requestHost): array
{
    $routesRead = _sf_pointer_read('routes', $privateRoot . '/routes/current.json');
    if ($routesRead['kind'] !== 'present' || !is_array($routesRead['value'])) {
        return ['kind' => $routesRead['kind']];
    }
    $host = _stattic_v4_host_lookup(
        $privateRoot,
        $routesRead['value'],
        _stattic_normalize_hostname($requestHost)
    );
    if ($host === false) {
        return ['kind' => 'unavailable'];
    }
    $hostEntry = is_array($host['entry'] ?? null) ? $host['entry'] : null;
    $spaceId = is_string($hostEntry['space_id'] ?? null) ? $hostEntry['space_id'] : '';
    if ($hostEntry === null || $spaceId === '') {
        return ['kind' => 'absent'];
    }
    $spaceRead = _sf_pointer_read(
        'space:' . $spaceId,
        $privateRoot . '/spaces/' . $spaceId . '/space.json'
    );
    if ($spaceRead['kind'] !== 'present') {
        return ['kind' => $spaceRead['kind']];
    }
    $overlay = _stattic_v4_overlay(
        $privateRoot,
        $spaceId,
        $spaceRead['value']
    );
    if ($overlay === false) {
        return ['kind' => 'unavailable'];
    }
    $versionId = $overlay === null ? null : _stattic_v4_version_for_host($hostEntry, $overlay);
    return $versionId === null
        ? ['kind' => 'absent']
        : ['kind' => 'present', 'space_id' => $spaceId, 'version_id' => $versionId];
}

/**
 * The declared crons of one version: cron key => request path. The provider
 * crontab owns timing, so the declared schedule never leaves the document.
 *
 * @return array<string, string>|null
 *         null when the version declared none (or the document is unreadable).
 */
function _stattic_cron_manifest(string $privateRoot, string $spaceId, string $versionId): ?array
{
    $catalog = _stattic_runtime_version_catalog($privateRoot, $spaceId, $versionId);
    $entry = is_array($catalog['paths'][STATTIC_CRONS_MANIFEST_PATH] ?? null)
        ? $catalog['paths'][STATTIC_CRONS_MANIFEST_PATH]
        : null;
    $sha = is_array($entry['source'] ?? null) && is_string($entry['source']['sha256'] ?? null)
        ? $entry['source']['sha256']
        : null;
    if ($sha === null || preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
        return null;
    }

    $absolute = _stattic_runtime_blob_path($privateRoot, $spaceId, $sha);
    if (!is_file($absolute)) {
        // The bytes may have aged out to the cold tier; same promotion every
        // other blob read takes.
        require_once __DIR__ . '/tier.php';
        $absolute = _stattic_tier_promote_blob($privateRoot, $spaceId, $sha) ?? '';
    }
    $raw = is_file($absolute)
        ? file_get_contents($absolute, false, null, 0, STATTIC_CRONS_MANIFEST_MAX_BYTES + 1)
        : false;
    if (!is_string($raw) || strlen($raw) > STATTIC_CRONS_MANIFEST_MAX_BYTES) {
        return null;
    }
    $document = json_decode($raw, true);
    // Version 1 is the only shape this engine reads; a future one is a refusal,
    // never a best-effort parse of fields that may have moved.
    if (!is_array($document) || ($document['version'] ?? null) !== 1) {
        return null;
    }

    $crons = [];
    foreach (is_array($document['crons'] ?? null) ? $document['crons'] : [] as $cron) {
        $key = is_array($cron) && is_string($cron['key'] ?? null) ? $cron['key'] : '';
        $path = is_array($cron) && is_string($cron['path'] ?? null) ? $cron['path'] : '';
        if ($key === '' || $path === '' || $path[0] !== '/') {
            continue;
        }
        $crons[$key] = $path;
    }
    return $crons === [] ? null : $crons;
}

/**
 * The space's own `CRON_SECRET`, when it declared one.
 *
 * Runtime variable VALUES land beside the version at finalize — the same
 * documents the Functions dispatch header and the Zero envelope read them from
 * (functions-dispatch.php `sf-fx-env`, zero.php `variables`). Only names the
 * build declared are present, so a space that never declared CRON_SECRET has no
 * entry here and the request simply carries no Authorization.
 *
 * @return array{kind: 'present', value: string}|array{kind: 'absent'|'unavailable'}
 */
function _stattic_cron_secret(string $privateRoot, string $spaceId, string $versionId): array
{
    $versionDir = _stattic_version_root($privateRoot, $spaceId, $versionId);
    $found = null;
    foreach (['/functions/config.json', '/zero/config.json'] as $relative) {
        $path = $versionDir . $relative;
        $config = _stattic_runtime_read_json($path);
        if ($config === null) {
            if (_sf_path_verifiably_absent($path)) {
                continue;
            }
            return ['kind' => 'unavailable'];
        }
        if (!is_array($config)) {
            return ['kind' => 'unavailable'];
        }
        if (array_key_exists('variableValues', $config) && !is_array($config['variableValues'])) {
            return ['kind' => 'unavailable'];
        }
        $values = is_array($config['variableValues'] ?? null) ? $config['variableValues'] : [];
        $secret = $values[STATTIC_CRON_SECRET_VARIABLE] ?? null;
        if ($secret === null || $secret === '') {
            continue;
        }
        if (!is_string($secret) || ($found !== null && $found !== $secret)) {
            return ['kind' => 'unavailable'];
        }
        $found = $secret;
    }
    return $found === null
        ? ['kind' => 'absent']
        : ['kind' => 'present', 'value' => $found];
}

/**
 * The `x-spacefast-cron` value: `<key>.<minute>.<hmac>`.
 *
 * Two independent properties, and it is worth being exact about which does what.
 *
 * The one that makes the header trustworthy at all is that a CLIENT CANNOT SEND
 * IT: `_stattic_strip_untrusted_edge_headers()` drops the name off every inbound
 * request before any lane reads it, and only this process can re-assert it
 * (`_stattic_platform_asserted_request_params()`), exactly as
 * `Spacefast-Access-*` identity forwarding is treated. Presence is the platform's
 * signature.
 *
 * The signature BINDS that presence to one cron and one minute, so a value that
 * escapes — a tenant log, a relayed request recorded at the execution edge —
 * cannot be replayed as a different cron or in a later minute. The key is
 * box-local and engine-only, so the ENGINE verifies it; a handler that wants a
 * secret of its own compares `authorization: Bearer <CRON_SECRET>`, which is the
 * half the tenant owns.
 *
 * Not the storage read key, though that is the other box-local secret in the
 * private root: that one is deliberately handed to clients inside object URLs,
 * so it is a capability, not a signing key.
 */
function _stattic_cron_signature(string $privateRoot, string $key, int $minute): string
{
    return $key . '.' . $minute . '.'
        . hash_hmac('sha256', $key . "\0" . $minute, _stattic_cron_signing_key($privateRoot));
}

/**
 * The box's cron signing key, minted lazily under the site write lock — the
 * same shape and the same race-free mint `_stattic_storage_read_key()` uses, so
 * provisioning never has to learn about it and two concurrent first crons agree.
 */
function _stattic_cron_signing_key(string $privateRoot): string
{
    static $memo = null;
    if (is_string($memo)) {
        return $memo;
    }
    $read = static function () use ($privateRoot): array {
        $record = _sf_pointer_read('cron-key', $privateRoot . '/runtime/cron-key.json');
        if ($record['kind'] === 'absent') {
            return ['state' => 'absent', 'key' => null];
        }
        $key = is_array($record['value']) ? ($record['value']['key'] ?? null) : null;
        return is_string($key) && preg_match('/^[a-f0-9]{64}$/', $key) === 1
            ? ['state' => 'present', 'key' => $key]
            : ['state' => 'unavailable', 'key' => null];
    };
    $state = $read();
    if ($state['key'] !== null) {
        return $memo = $state['key'];
    }
    if ($state['state'] === 'unavailable') {
        throw new RuntimeException('cron signing key is unavailable');
    }
    _stattic_runtime_mkdir_soft($privateRoot . '/runtime');
    $minted = _stattic_lock_with(
        $privateRoot . '/runtime/write.lock',
        STATTIC_LOCK_WAIT,
        null,
        static function () use ($read, $privateRoot): string {
            $existing = $read();
            if ($existing['key'] !== null) {
                return $existing['key'];
            }
            if ($existing['state'] !== 'absent') {
                throw new RuntimeException('cron signing key is unavailable');
            }
            $key = bin2hex(random_bytes(32));
            _sf_pointer_swap($privateRoot . '/runtime/cron-key.json', [
                'key' => $key,
                'minted_at' => gmdate('c'),
            ]);
            return $key;
        },
    );
    return $memo = (is_string($minted) ? $minted : '');
}
