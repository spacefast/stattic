<?php
declare(strict_types=1);

// Lightweight front-door predicates for legacy Zero 0.8 control paths, kept
// inline here so static / CF-Worker / SpacePress requests never require
// anything under `engine/deprecated/`.
function _stattic_control_path_admit_zero_route(string $path): bool
{
    static $routes = [
        '__zero/config' => true,
        '__zero/run' => true,
        '__zero/realtime/events' => true,
        '__spacefast/zero/config' => true,
        '__spacefast/zero/run' => true,
        '__spacefast/zero/realtime/events' => true,
        '__zero/auth/start' => true,
        '__zero/auth/sign-out' => true,
        '__spacefast/zero/auth/gravatar/start' => true,
        '__spacefast/zero/auth/sign-out' => true,
    ];
    return isset($routes[trim(strtolower($path), '/')]);
}

function _stattic_control_path_admit_legacy_zero_connectors(string $path): bool
{
    return preg_match(
        '~\A' . STATTIC_RUNTIME_NAMESPACE_PATH . '/zero/connectors/([a-z][a-zA-Z0-9]{0,63})(?:/(connect|callback|disconnect))?\z~D',
        $path
    ) === 1;
}

const SPACEFAST_CONTROL_PATHS = [
    // =========================================================================
    // 1. Canonical platform control paths (`/__spacefast/*` and `/__`)
    // =========================================================================

    // SpacePress browser auth routes (`SP_AUTH_PREFIX = '/__spacefast/auth'`).
    ['path' => STATTIC_RUNTIME_NAMESPACE_PATH . '/auth', 'match' => 'namespace', 'visitor' => true, 'tenant' => false, 'stage' => null, 'handler' => null],
    ['path' => STATTIC_SHARE_OAUTH_START_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'access_account_start'],
    ['path' => STATTIC_SHARE_OAUTH_CALLBACK_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'share_oauth_callback'],
    // The Space's own server code answers here, after the access check; no
    // published file can, because uploads refuse every `__spacefast` segment.
    ['path' => STATTIC_SHARE_OAUTH_APP_CALLBACK_PREFIX, 'match' => 'prefix', 'visitor' => true, 'tenant' => true, 'stage' => null, 'handler' => null],
    // Canonical: the unlock card (GET) and its password form (POST), served on
    // the Space's own hostname before any publisher HTML or JS runs.
    ['path' => STATTIC_ACCESS_UNLOCK_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'access_unlock'],
    // Canonical: who is signed in here (GET), and signing out of this Space (DELETE).
    ['path' => STATTIC_SPACE_SESSION_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'access_session'],
    // Canonical: the share-link trampoline. `/__` alone; `/__/<token>` below
    // is the older path form.
    ['path' => STATTIC_ACCESS_LINK_TRAMPOLINE_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'access_link_trampoline'],
    ['path' => STATTIC_ACCESS_CLIENT_SCRIPT_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'access_client_script'],
    ['path' => STATTIC_ACCESS_IN_PREFIX, 'match' => 'prefix', 'admit' => '_stattic_access_in_token', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'access_in'],
    // The door: every way into a private Space on one page.
    ['path' => STATTIC_RUNTIME_NAMESPACE_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'access_door'],
    ['path' => STATTIC_SELL_CHECKOUT_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'sell_exchange'],
    ['path' => STATTIC_SELL_PRODUCT_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'sell_exchange'],
    ['path' => STATTIC_SELL_STATUS_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'sell_status'],
    ['path' => STATTIC_SELL_CLIENT_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'sell_client'],
    ['path' => STATTIC_COMMENTS_TICKET_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'comments_exchange'],
    ['path' => STATTIC_COMMENTS_VERSION_URLS_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'comments_exchange'],
    ['path' => STATTIC_COMMENTS_MENTIONABLE_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'comments_exchange'],
    ['path' => STATTIC_SPACEFAST_SDK_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'sdk', 'handler' => 'sdk'],
    ['path' => STATTIC_SPACEFAST_COLLAB_MANIFEST_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'sdk', 'handler' => 'sdk'],
    ['path' => STATTIC_SPACEFAST_COLLAB_THEME_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'sdk', 'handler' => 'sdk'],
    // Answered after the access check, beside sdk.js: whether a Space has
    // analytics is not for a visitor who may not view it.
    ['path' => STATTIC_SPACEFAST_TRACKER_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'sdk', 'handler' => 'tracker'],
    // Canonical runtime object storage under `/__spacefast/storage/*`.
    // Keyed public reads (`/__spacefast/storage/u/<id>?k=...`) precede the
    // authenticated `/__spacefast/storage` namespace; both dispatch in
    // serve.php after the Space access check.
    ['path' => STATTIC_STORAGE_PUBLIC_URL_PREFIX, 'match' => 'prefix', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'uploads_object'],
    ['path' => STATTIC_STORAGE_PATH, 'match' => 'namespace', 'visitor' => true, 'tenant' => false, 'stage' => null, 'handler' => null],
    // Each is authorized by its own signed token rather than by being a known path.
    ['path' => '/' . STATTIC_FUNCTIONS_BUNDLE_PREFIX, 'match' => 'prefix', 'fold' => true, 'visitor' => true, 'tenant' => false, 'stage' => 'functions', 'handler' => 'functions_artifact'],
    ['path' => '/' . STATTIC_FUNCTIONS_RELAY_PATH, 'match' => 'exact', 'fold' => true, 'visitor' => true, 'tenant' => false, 'stage' => 'functions', 'handler' => 'functions_relay'],
    ['path' => '/' . STATTIC_FUNCTIONS_LOGS_PATH, 'match' => 'exact', 'fold' => true, 'visitor' => true, 'tenant' => false, 'stage' => 'functions', 'handler' => 'functions_logs'],
    ['path' => '/' . STATTIC_FUNCTIONS_PURGE_PATH, 'match' => 'exact', 'fold' => true, 'visitor' => true, 'tenant' => false, 'stage' => 'functions', 'handler' => 'functions_purge'],
    // Admitted at the front door, dispatched by serve.php AFTER the Space
    // access check, so a page's data is exactly as private as the page.
    ['path' => STATTIC_SPACE_CLIENT_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'space_client'],
    ['path' => STATTIC_SPACE_CLIENT_TYPES_URL_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'connectors'],
    ['path' => STATTIC_CONNECTORS_PATH_PREFIX, 'match' => 'prefix', 'admit' => '_stattic_connectors_route', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'connectors'],

    // =========================================================================
    // 2. Legacy compatibility aliases (isolated from canonical routes)
    // =========================================================================

    // Legacy runtime storage aliases: `/storage/*` (shared namespace where
    // authored GET/HEAD entries win) and `/__stattic/u/*` (keyed public reads).
    // Kept for deployed Zero 0.8 capsules, existing Functions isolates, cached
    // comment URLs, and mixed rollouts (`compat/old-engine.ts` S25).
    ['path' => STATTIC_STORAGE_LEGACY_PATH, 'match' => 'namespace', 'visitor' => true, 'tenant' => true, 'stage' => null, 'handler' => null],
    ['path' => STATTIC_UPLOADS_PUBLIC_URL_PREFIX, 'match' => 'prefix', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'uploads_object'],

    // Legacy access aliases under `/__spacefast/access/*`, `/__/<token>`, and
    // `/__sf/redeem` (old-engine compat S12), kept for links, CLIs, and older
    // control-plane images in the wild.
    ['path' => STATTIC_ACCESS_ACCOUNT_START_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'access_account_start'],
    ['path' => STATTIC_ACCESS_SESSION_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'access_session'],
    ['path' => STATTIC_ACCESS_LOGOUT_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'access_logout'],
    ['path' => STATTIC_ACCESS_PASSWORD_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'access_password'],
    ['path' => STATTIC_ACCESS_EMAIL_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'access_email'],
    ['path' => STATTIC_ACCESS_REQUEST_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'access_request'],
    ['path' => STATTIC_ACCESS_ENTRY_PREFIX, 'match' => 'prefix', 'admit' => '_stattic_access_entry_token', 'visitor' => true, 'tenant' => true, 'stage' => 'entry', 'handler' => 'access_link_entry'],
    ['path' => STATTIC_ACCESS_LEGACY_REDEEM_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'access_legacy_redeem'],

    // Legacy Space Users (`deprecated/space-users`).
    ['path' => '/identity', 'match' => 'namespace', 'visitor' => true, 'tenant' => false, 'stage' => null, 'handler' => null],

    // Legacy Zero 0.8 and Zero v2 client/control paths (`/__zero/*` and frozen
    // capsule `/__spacefast/zero/*` aliases).
    ['path' => STATTIC_ZERO_V2_CLIENT_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'sdk', 'handler' => 'zero_v2_client'],
    ['path' => STATTIC_ZERO_V2_CLIENT_TYPES_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'sdk', 'handler' => 'zero_v2_client'],
    ['path' => STATTIC_ZERO_V2_REALTIME_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'sdk', 'handler' => 'zero_v2_client'],
    ['path' => '/__zero/auth/user', 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => null, 'handler' => null],
    ['path' => '/__zero/auth/complete', 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => null, 'handler' => null],
    ['path' => '/__zero/auth/native', 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => null, 'handler' => null],
    ['path' => '/__zero/auth/api/', 'match' => 'prefix', 'visitor' => true, 'tenant' => false, 'stage' => null, 'handler' => null],
    ['path' => STATTIC_ZERO_REALTIME_TICKET_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'comments_exchange'],
    ['path' => STATTIC_ZERO_CANONICAL_REALTIME_TICKET_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'comments_exchange'],
    ['path' => STATTIC_RUNTIME_NAMESPACE_PATH . '/zero/connectors/', 'match' => 'prefix', 'admit' => '_stattic_control_path_admit_legacy_zero_connectors', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'connectors'],
    ['path' => STATTIC_ZERO_CANONICAL_NAMESPACE_PATH, 'match' => 'namespace', 'fold' => true, 'admit' => '_stattic_control_path_admit_zero_route', 'visitor' => true, 'tenant' => false, 'stage' => 'route-index', 'handler' => 'invoke_zero'],
    ['path' => STATTIC_RUNTIME_NAMESPACE_PATH . '/zero', 'match' => 'namespace', 'fold' => true, 'admit' => '_stattic_control_path_admit_zero_route', 'visitor' => true, 'tenant' => false, 'stage' => 'route-index', 'handler' => 'invoke_zero'],

    // Legacy readiness probe fallback for engines older than
    // `runtime.health.serving.v1` (`compat/old-engine.ts` S26). Remove after
    // `LEGACY_PROBE_PATH` is deleted from control-plane `runtime-health.ts` and
    // no older control-plane image can reach a box.
    ['path' => '/__stattic_probe', 'match' => 'exact', 'visitor' => true, 'tenant' => true, 'stage' => 'probe', 'handler' => 'probe'],

    // =========================================================================
    // 3. Namespace catch-alls (private-by-default & reserved from tenant code)
    // =========================================================================
    // Must stay after every specific route inside each namespace above.

    ['path' => STATTIC_RUNTIME_NAMESPACE_PATH, 'match' => 'namespace', 'fold' => true, 'visitor' => false, 'tenant' => false, 'stage' => null, 'handler' => null],
    // `destination`: First's `/__first/api/v1` is a public API other frontends
    // call, so a proxy upstream or redirect may point into it. Tenant files and
    // tenant routes still may not claim it.
    ['path' => STATTIC_FIRST_NAMESPACE_PATH, 'match' => 'namespace', 'fold' => true, 'visitor' => false, 'tenant' => false, 'destination' => true, 'stage' => null, 'handler' => null],
    ['path' => STATTIC_ZERO_CANONICAL_NAMESPACE_PATH, 'match' => 'namespace', 'fold' => true, 'visitor' => false, 'tenant' => false, 'stage' => null, 'handler' => null],
    // The old handoff namespace: only `/__sf/redeem` answers (above), and no
    // tenant may claim the rest while an old control plane can still send there.
    ['path' => '/__sf', 'match' => 'namespace', 'fold' => true, 'visitor' => false, 'tenant' => false, 'stage' => null, 'handler' => null],
    // Legacy namespace (holds `/__stattic/blob/` and `/__stattic/u/`): no other
    // visitor route lives here, and no tenant may claim it.
    ['path' => '/__stattic', 'match' => 'namespace', 'fold' => true, 'visitor' => true, 'tenant' => false, 'stage' => null, 'handler' => null],
];
