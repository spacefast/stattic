<?php
declare(strict_types=1);

// Isolated runtime compatibility hooks for older control-plane images,
// pre-migration overlays, and legacy identifiers.
//
// Retirement milestones: S13 / S19 / S23 / authorization projection v3-v4 /
// legacy `anon_` guest ids. Tracked in
// `apps/control-plane/src/compat/old-engine.ts` and
// `GET /superadmin/product-analytics/share-retirement`.

// Legacy guest pseudonym format (`anon_` + 32 hex) issued before `pg1_`
// base64url guest ids. Kept so a guest who commented before this engine stays
// the same guest; new ids are always `pg1_`.
function _stattic_is_legacy_anonymous_id(mixed $value): bool
{
    return is_string($value) && preg_match('/\Aanon_[a-f0-9]{32}\z/', $value) === 1;
}

// old-engine-compat: S19 visitor sessions and visitor-browser key derivation
// under the runtime exchange credential when a Space's overlay predates its
// box-local session key (`runtime.session-keys.v1`).
function _stattic_access_legacy_hmac_key(array $exchange, ?string $label = null): ?string
{
    $credential = is_string($exchange['credential'] ?? null) ? $exchange['credential'] : '';
    if ($label === null) {
        return $credential;
    }
    return strlen($credential) >= 32 ? hash_hmac('sha256', $label, $credential) : null;
}

// old-engine-compat: S13 older Space runtime credentials. An older control
// plane reads only this; a newer one checks the Space token first and refuses
// this once it has registered the box's key.
function _stattic_access_legacy_exchange_header(array $exchange): string
{
    return 'Spacefast-Runtime-Exchange: ' . $exchange['credential'];
}

// old-engine-compat: S23 per-person consent copies
// (`share-consent:<consent>:<Grant>`) projected for engines before
// `runtime.real-authority-sessions.v1`. A consent copied from a public Grant
// does not mean the account belongs to the Space.
function _stattic_grant_index_is_legacy_public_consent(array $grant, array $publicGrantIds): bool
{
    $source = is_string($grant['source']['reference'] ?? null) ? $grant['source']['reference'] : '';
    return preg_match('/\Ashare-consent:[^:]+:(.+)\z/', $source, $match) === 1
        && isset($publicGrantIds[$match[1]]);
}

// Legacy authorization projection on-read upgrade (v3 -> v4 -> v5).
// A Space that never republishes keeps the compiled grant index written by an
// older engine; upgrading v3 and v4 overlays here keeps them serving without a
// recompile.
function _stattic_authorization_projection_upgrade_legacy(array $value): array
{
    if (($value['compiledVersion'] ?? null) === 3 && is_array($value['grantIndex'] ?? null)) {
        $value['compiledVersion'] = 4;
        $value['grantIndex']['hasFrameLinks'] = false;
    }
    if (($value['compiledVersion'] ?? null) === 4 && is_array($value['grantIndex'] ?? null)) {
        $value['compiledVersion'] = STATTIC_AUTHORIZATION_COMPILED_VERSION;
        $value['grantIndex']['teamReference'] = null;
    }
    return $value;
}
