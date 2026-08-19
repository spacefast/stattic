<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/jwt.php';
require_once __DIR__ . '/../shared/response.php';

// The admin lanes are where token refusals are allowed to be loud: the caller is
// the control plane, not a visitor, and a precise code is what makes a minting
// bug debuggable. The verifier itself stays silent (shared/jwt.php) and this
// table is the only thing that turns its rejection into a problem document.
// Lookup order per lane: 'claim:<claim>' → 'claim.<rule>' → 'claim' → reason.
const STATTIC_RUNTIME_JWT_PROBLEMS = [
    'token_missing' => [401, 'runtime_unauthorized', 'Runtime API authentication is required.'],
    'malformed' => [401, 'runtime_token_invalid', 'Runtime token is invalid.'],
    'audience' => [401, 'runtime_token_expired', 'Runtime token is expired or not valid yet.'],
    'expired' => [401, 'runtime_token_expired', 'Runtime token is expired or not valid yet.'],
    'instance' => [403, 'runtime_instance_mismatch', 'Runtime token is not scoped to this runtime.'],
    'api_base_url_missing' => [500, 'runtime_api_base_url_missing', 'Runtime API base URL is not configured.'],
    'jwks_fetch_failed' => [500, 'runtime_jwks_fetch_failed', 'Runtime JWT signing keys could not be fetched.'],
    'key_missing' => [401, 'runtime_jwks_key_missing', 'Runtime JWT signing key is unavailable.'],
    'signature' => [401, 'runtime_token_bad_signature', 'Runtime token signature is invalid.'],
    'claim' => [403, 'runtime_scope_forbidden', 'Runtime token scope does not match this request (claim: {claim}).'],
];

function _stattic_runtime_jwt_refuse(array $rejection, array $vocabulary = []): never
{
    $claim = (string) ($rejection['claim'] ?? '');
    $reason = (string) ($rejection['reason'] ?? 'malformed');
    $keys = [$reason];
    if ($reason === 'claim') {
        array_unshift($keys, 'claim:' . $claim, 'claim.' . (string) ($rejection['rule'] ?? ''));
    }
    foreach ($keys as $key) {
        $entry = $vocabulary[$key] ?? (STATTIC_RUNTIME_JWT_PROBLEMS[$key] ?? null);
        if (is_array($entry)) {
            [$status, $code, $message] = $entry;
            _stattic_problem_response($status, $code, str_replace('{claim}', $claim, $message));
        }
    }
    _stattic_problem_response(401, 'runtime_token_invalid', 'Runtime token is invalid.');
}

function _stattic_runtime_require_management_jwt(string $privateRoot, string $action, array $required = []): array
{
    $claims = ['action' => ['equals' => $action], 'operation_id' => ['present' => true]];
    foreach ($required as $key => $value) {
        $claims[$key] = ['equals' => $value];
    }
    $rejection = null;
    $verified = _stattic_runtime_token_claims(
        $privateRoot,
        _stattic_runtime_bearer_token_from_request() ?? '',
        'stattic-runtime-management',
        ['claims' => $claims, 'allow_jwks_fetch' => true],
        $rejection,
    );
    if ($verified === null) {
        _stattic_runtime_jwt_refuse((array) $rejection, [
            'claim:action' => [403, 'runtime_action_forbidden', 'Runtime action is not allowed by this token (expected action: ' . $action . ').'],
            'claim:operation_id' => [403, 'runtime_operation_missing', 'Runtime management token requires an operation id.'],
        ]);
    }
    _stattic_jwt_reject_replayed_jti($privateRoot, 'stattic-runtime-management', $verified, time());
    return $verified;
}

function _stattic_runtime_require_upload_jwt(string $privateRoot): array
{
    // The upload lane names a missing credential itself: its callers are
    // S3-shaped clients, and `runtime_unauthorized` would not tell them which
    // header they omitted.
    $token = _stattic_runtime_bearer_token_from_request();
    if ($token === null) {
        _stattic_problem_response(401, 'runtime_upload_bearer_required', 'Runtime uploads require Authorization: Bearer.');
    }
    $rejection = null;
    $claims = _stattic_runtime_token_claims(
        $privateRoot,
        $token,
        'stattic-runtime-upload',
        ['allow_jwks_fetch' => true],
        $rejection,
    );
    if ($claims === null) {
        _stattic_runtime_jwt_refuse((array) $rejection);
    }
    return $claims;
}
