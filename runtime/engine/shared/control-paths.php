<?php
declare(strict_types=1);

require_once __DIR__ . '/legacy-zero.php';

const SPACEFAST_CONTROL_PATHS = [
    ['path' => STATTIC_SPACEFAST_CLIENT_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'sdk', 'handler' => 'spacefast_client'],
    ['path' => STATTIC_SPACEFAST_CLIENT_TYPES_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'sdk', 'handler' => 'spacefast_client'],
    ['path' => STATTIC_ZERO_V2_CLIENT_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'sdk', 'handler' => 'zero_v2_client'],
    ['path' => STATTIC_ZERO_V2_CLIENT_TYPES_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'sdk', 'handler' => 'zero_v2_client'],
    ['path' => STATTIC_ZERO_V2_REALTIME_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'sdk', 'handler' => 'zero_v2_client'],
    // Shared namespace: authored GET/HEAD entries win; the storage handler owns the remaining requests.
    ['path' => '/storage', 'match' => 'namespace', 'visitor' => true, 'tenant' => true, 'stage' => null, 'handler' => null],
    ['path' => '/identity', 'match' => 'namespace', 'visitor' => true, 'tenant' => false, 'stage' => null, 'handler' => null],
    ['path' => '/__zero/auth/user', 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => null, 'handler' => null],
    ['path' => '/__zero/auth/complete', 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => null, 'handler' => null],
    ['path' => '/__zero/auth/native', 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => null, 'handler' => null],
    ['path' => '/__zero/auth/api/', 'match' => 'prefix', 'visitor' => true, 'tenant' => false, 'stage' => null, 'handler' => null],
    ['path' => '/__stattic_probe', 'match' => 'exact', 'visitor' => true, 'tenant' => true, 'stage' => 'probe', 'handler' => 'probe'],
    ['path' => STATTIC_RUNTIME_VISITOR_NAMESPACE_PATH . '/redeem', 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'access_callback'],
    ['path' => STATTIC_ACCESS_CLIENT_SCRIPT_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'access_client_script'],
    ['path' => STATTIC_ACCESS_ACCOUNT_START_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'access_account_start'],
    ['path' => STATTIC_ACCESS_LOGOUT_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'access_logout'],
    ['path' => STATTIC_ACCESS_PASSWORD_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'access_password'],
    ['path' => STATTIC_ACCESS_EMAIL_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'access_email'],
    ['path' => STATTIC_ACCESS_REQUEST_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'access_request'],
    ['path' => STATTIC_SELL_CHECKOUT_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'sell_exchange'],
    ['path' => STATTIC_SELL_PRODUCT_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'sell_exchange'],
    ['path' => STATTIC_SELL_STATUS_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'sell_status'],
    ['path' => STATTIC_SELL_CLIENT_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'sell_client'],
    ['path' => STATTIC_COMMENTS_TICKET_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'comments_exchange'],
    ['path' => STATTIC_COMMENTS_VERSION_URLS_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'comments_exchange'],
    ...STATTIC_LEGACY_ZERO_TICKET_PATHS,
    ['path' => STATTIC_ACCESS_ENTRY_PREFIX, 'match' => 'prefix', 'admit' => '_stattic_access_entry_token', 'visitor' => true, 'tenant' => true, 'stage' => 'entry', 'handler' => 'access_link_entry'],
    ['path' => STATTIC_SPACEFAST_SDK_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'sdk', 'handler' => 'sdk'],
    ['path' => STATTIC_SPACEFAST_COLLAB_MANIFEST_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'sdk', 'handler' => 'sdk'],
    ['path' => STATTIC_SPACEFAST_COLLAB_THEME_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'sdk', 'handler' => 'sdk'],
    // Answered after the access check, beside sdk.js: whether a Space has
    // analytics is not for a visitor who may not view it.
    ['path' => STATTIC_SPACEFAST_TRACKER_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'sdk', 'handler' => 'tracker'],
    // Each is authorized by its own signed token rather than by being a known path.
    ['path' => '/' . STATTIC_FUNCTIONS_BUNDLE_PREFIX, 'match' => 'prefix', 'fold' => true, 'visitor' => true, 'tenant' => false, 'stage' => 'functions', 'handler' => 'functions_artifact'],
    ['path' => '/' . STATTIC_FUNCTIONS_RELAY_PATH, 'match' => 'exact', 'fold' => true, 'visitor' => true, 'tenant' => false, 'stage' => 'functions', 'handler' => 'functions_relay'],
    ['path' => '/' . STATTIC_FUNCTIONS_LOGS_PATH, 'match' => 'exact', 'fold' => true, 'visitor' => true, 'tenant' => false, 'stage' => 'functions', 'handler' => 'functions_logs'],
    ['path' => '/' . STATTIC_FUNCTIONS_PURGE_PATH, 'match' => 'exact', 'fold' => true, 'visitor' => true, 'tenant' => false, 'stage' => 'functions', 'handler' => 'functions_purge'],
    ...STATTIC_LEGACY_ZERO_CONTROL_PATHS,
    // Unknown `/__zero/*` paths refuse exactly like unknown `/__spacefast/*`:
    // the namespace is private-by-default on both spellings.
    ...STATTIC_LEGACY_ZERO_PRIVATE_PATHS,
    ['path' => STATTIC_RUNTIME_NAMESPACE_PATH, 'match' => 'namespace', 'fold' => true, 'visitor' => false, 'tenant' => false, 'stage' => null, 'handler' => null],
    ['path' => STATTIC_RUNTIME_VISITOR_NAMESPACE_PATH, 'match' => 'namespace', 'fold' => true, 'visitor' => false, 'tenant' => false, 'stage' => null, 'handler' => null],
    // An entry-stage row so the front door admits it and no tenant can publish
    // over it; serve.php dispatches it AFTER the Space access check. Must precede
    // the '/__stattic' catch-all below.
    ['path' => STATTIC_UPLOADS_PUBLIC_URL_PREFIX, 'match' => 'prefix', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'uploads_object'],
    // No visitor route lives here, but a tenant must not own them either.
    ['path' => '/__stattic', 'match' => 'namespace', 'fold' => true, 'visitor' => true, 'tenant' => false, 'stage' => null, 'handler' => null],
    ['path' => '/__span', 'match' => 'namespace', 'fold' => true, 'visitor' => true, 'tenant' => false, 'stage' => null, 'handler' => null],
];
