<?php
declare(strict_types=1);

// Which version is live, and what a preview may not do.
//
// A Space's live version is the one its `production` route names. Every other
// version (a preview, a version hostname, a channel or branch host) is a
// preview. This file holds the two engine rules every preview lane shares:
//
// - D1. A preview Worker's `env.DB` and a preview finalize use the preview's
//   own tables, ledger and lock (d1-broker.php), never the live tables.
// - Side effects. A preview never charges a live card, delivers a webhook or
//   mails a customer (`_stattic_preview_guard_side_effects`).
//
// Live is decided by the route pointer alone, never by the request host.

const STATTIC_PREVIEW_ROOT = 'previews';
// Runtime variables an app sets to exercise one side effect from its previews
// on purpose. There is deliberately none for payments.
const STATTIC_PREVIEW_ALLOW_MAIL = 'SPACEPRESS_PREVIEW_ALLOW_MAIL';
const STATTIC_PREVIEW_ALLOW_WEBHOOKS = 'SPACEPRESS_PREVIEW_ALLOW_WEBHOOKS';
// Payment APIs a preview may never call with live credentials. Their sandboxes
// live on other hosts (PayPal, Braintree, Adyen) or take test keys (Stripe).
const STATTIC_PREVIEW_LIVE_PAYMENT_HOSTS = [
    'api-m.paypal.com',
    'api.paypal.com',
    'api-3t.paypal.com',
    'api.braintreegateway.com',
    'payments.braintree-api.com',
    'checkout-live.adyen.com',
];
// Signature headers SpacePress (TypedPress\Http) and WooCommerce put on a
// webhook delivery.
const STATTIC_PREVIEW_WEBHOOK_SIGNATURES = ['typedpress-signature', 'spacepress-signature', 'x-wc-webhook-signature', 'webhook-signature'];

/** The version the Space's production route serves, or null before its first promotion. */
function _stattic_runtime_live_version_id(string $privateRoot, string $spaceId): ?string
{
    require_once __DIR__ . '/storage.php';
    $pointer = _stattic_runtime_read_json(_stattic_route_pointer_path($privateRoot, $spaceId, 'production'));
    if ($pointer === false) {
        // An unreadable pointer is no evidence either way: a wrong "preview"
        // hides live data, a wrong "live" hands a preview the live tables.
        throw new RuntimeException('The live route pointer could not be read.');
    }
    $versionId = is_array($pointer) ? ($pointer['version_id'] ?? null) : null;
    return is_string($versionId) && _stattic_id_valid($versionId) ? $versionId : null;
}

/** Whether `$versionId` is a preview: anything the Space's production route does not serve. */
function _stattic_runtime_version_is_preview(string $privateRoot, string $spaceId, string $versionId): bool
{
    return _stattic_runtime_live_version_id($privateRoot, $spaceId) !== $versionId;
}

/** One preview's private directory, shared with the preview database binding. */
function _stattic_preview_root(string $privateRoot, string $spaceId, string $versionId): string
{
    return _stattic_space_root($privateRoot, $spaceId) . '/' . STATTIC_PREVIEW_ROOT . '/' . $versionId;
}

/**
 * Migrate a preview's own D1 scope once. Finalize migrates a version it does
 * not activate; a version that stops being live (a later promotion, a
 * rollback) has never run on its preview scope, so its first Worker query
 * migrates it here. The marker keeps every later query to one stat.
 */
function _stattic_preview_d1_ensure(string $privateRoot, string $spaceId, string $versionId, array $databases): void
{
    if (is_file(_stattic_preview_root($privateRoot, $spaceId, $versionId) . '/d1-ready.json')) {
        return;
    }
    require_once __DIR__ . '/d1-broker.php';
    _sf_d1_migrate($spaceId, $databases, $versionId);
    _stattic_preview_d1_mark_ready($privateRoot, $spaceId, $versionId);
}

/** Record that a preview's D1 scope carries every migration of its version. */
function _stattic_preview_d1_mark_ready(string $privateRoot, string $spaceId, string $versionId): void
{
    $root = _stattic_preview_root($privateRoot, $spaceId, $versionId);
    if (!_stattic_runtime_mkdir_soft($root)) {
        throw new RuntimeException('D1_ERROR: the preview storage could not be created.');
    }
    _stattic_runtime_write_json_atomic($root . '/d1-ready.json', ['version_id' => $versionId], true);
}

/** Whether the app opted one preview side-effect lane in through its runtime variables. */
function _stattic_preview_allows(array $variables, string $name): bool
{
    return in_array(strtolower(trim((string) ($variables[$name] ?? ''))), ['1', 'true', 'yes'], true);
}

/**
 * Install the preview side-effect guards on a WordPress request serving
 * `$versionId`, once WordPress's plugin API exists. A preview database binding
 * keeps rows away from live; these keep a preview's actions away from real
 * people and systems:
 *
 * - Payments. A payment API call with live credentials is refused, and
 *   checkout offers only gateways in test mode. There is no opt-in.
 * - Webhooks. A signed delivery is answered locally with a 202 and never
 *   leaves the box, unless the app sets SPACEPRESS_PREVIEW_ALLOW_WEBHOOKS.
 * - Mail. `wp_mail()` accepts and drops every message, and so does the
 *   engine's own app mail transport (`_stattic_preview_mail_held`), unless
 *   the app sets SPACEPRESS_PREVIEW_ALLOW_MAIL.
 *
 * Each held effect leaves one runtime log line without its content.
 */
function _stattic_preview_guard_side_effects(string $versionId, array $variables): void
{
    if (!function_exists('add_filter')) {
        return;
    }
    $holdMail = !_stattic_preview_allows($variables, STATTIC_PREVIEW_ALLOW_MAIL);
    $GLOBALS['SPACEFAST_PREVIEW_GUARD'] = ['version_id' => $versionId, 'hold_mail' => $holdMail, 'held' => []];
    // Every hold runs first: a `pre_http_request` or `pre_wp_mail` filter that
    // answers earlier would already have sent.
    add_filter('pre_http_request', '_stattic_preview_refuse_live_payment', PHP_INT_MIN, 3);
    add_filter('woocommerce_available_payment_gateways', '_stattic_preview_available_gateways', PHP_INT_MAX);
    if (!_stattic_preview_allows($variables, STATTIC_PREVIEW_ALLOW_WEBHOOKS)) {
        add_filter('pre_http_request', '_stattic_preview_hold_webhook', PHP_INT_MIN, 3);
        add_filter('woocommerce_webhook_should_deliver', '__return_false', PHP_INT_MAX);
    }
    if ($holdMail) {
        add_filter('pre_wp_mail', '_stattic_preview_mail_held', PHP_INT_MIN);
    }
}

/**
 * Whether this request holds outgoing mail, recording the hold. The engine's
 * mail transports ask this before they send: a filter chain runs every
 * callback, so an earlier "accepted" answer would not stop a later sender.
 */
function _stattic_preview_mail_held(): bool
{
    if (($GLOBALS['SPACEFAST_PREVIEW_GUARD']['hold_mail'] ?? false) !== true) {
        return false;
    }
    _stattic_preview_record('mail_held', []);
    return true;
}

/**
 * Refuse a payment API call made with live credentials. Stripe takes test and
 * live keys on one host, so the key decides; the other providers keep live and
 * sandbox on separate hosts, so the host decides.
 */
function _stattic_preview_refuse_live_payment(mixed $response, array $args, string $url): mixed
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $live = in_array($host, STATTIC_PREVIEW_LIVE_PAYMENT_HOSTS, true)
        || str_ends_with($host, '-live.adyenpayments.com');
    if ($host === 'api.stripe.com' || $host === 'files.stripe.com') {
        $authorization = _stattic_preview_header($args['headers'] ?? [], 'authorization');
        // The WooCommerce Stripe gateway sends the key as Basic `key:`.
        if (preg_match('/^\s*basic\s+(\S+)/i', $authorization, $basic) === 1) {
            $authorization .= ' ' . (string) base64_decode($basic[1], true);
        }
        $body = $args['body'] ?? '';
        $credentials = $authorization . ' ' . (is_array($body) ? http_build_query($body) : (string) $body);
        $live = preg_match('/\b(?:sk|rk)_live_/', $credentials) === 1;
    }
    if (!$live) {
        return $response;
    }
    _stattic_preview_record('payment_refused', ['host' => $host]);
    return new WP_Error('spacefast_preview_live_payment', 'Previews never call a payment provider with live credentials. Use test keys.');
}

/** One request header by name, from WordPress's array form or a raw header string. */
function _stattic_preview_header(mixed $headers, string $name): string
{
    if (is_string($headers)) {
        $parsed = [];
        foreach (preg_split('/\r?\n/', $headers) ?: [] as $line) {
            [$key, $value] = array_pad(explode(':', $line, 2), 2, '');
            $parsed[trim($key)] = trim($value);
        }
        $headers = $parsed;
    }
    $headers = array_change_key_case(is_array($headers) ? $headers : [], CASE_LOWER);
    return (string) ($headers[$name] ?? '');
}

/** A gateway proves test mode with WooCommerce's own setting, or moves no money at all. */
function _stattic_preview_gateway_in_test_mode(object $gateway): bool
{
    if (in_array($gateway->id ?? null, ['cod', 'bacs', 'cheque'], true)) {
        return true;
    }
    $settings = property_exists($gateway, 'settings') && is_array($gateway->settings) ? $gateway->settings : [];
    foreach (['testmode', 'sandbox', 'test_mode'] as $key) {
        if (($settings[$key] ?? null) === 'yes') {
            return true;
        }
    }
    return false;
}

/**
 * Checkout offers only gateways in test mode, so a preview order still runs end
 * to end and never charges a real card.
 *
 * @param array<string, mixed> $gateways
 */
function _stattic_preview_available_gateways(array $gateways): array
{
    return array_filter($gateways, static fn ($gateway): bool => is_object($gateway) && _stattic_preview_gateway_in_test_mode($gateway));
}

/**
 * Answer a signed webhook delivery locally with a 202. Other requests the app
 * makes (an API read through `fetch()`) still run under the egress policy.
 */
function _stattic_preview_hold_webhook(mixed $response, array $args, string $url): mixed
{
    if ($response !== false || strtoupper((string) ($args['method'] ?? 'GET')) !== 'POST') {
        return $response;
    }
    $signed = false;
    foreach (STATTIC_PREVIEW_WEBHOOK_SIGNATURES as $name) {
        $signed = $signed || _stattic_preview_header($args['headers'] ?? [], $name) !== '';
    }
    if (!$signed) {
        return $response;
    }
    _stattic_preview_record('webhook_held', ['host' => strtolower((string) parse_url($url, PHP_URL_HOST))]);
    return ['headers' => [], 'body' => '', 'response' => ['code' => 202, 'message' => 'Accepted'], 'cookies' => [], 'filename' => null];
}

/** One runtime log line per held side effect, without its content. */
function _stattic_preview_record(string $event, array $metadata): void
{
    $guard = $GLOBALS['SPACEFAST_PREVIEW_GUARD'] ?? null;
    if (!is_array($guard)) {
        return;
    }
    $GLOBALS['SPACEFAST_PREVIEW_GUARD']['held'][] = $event;
    require_once __DIR__ . '/runtime-log.php';
    _stattic_runtime_log_write([
        'level' => 'info',
        'handlerName' => 'preview',
        'message' => match ($event) {
            'mail_held' => 'Preview mail was not sent. Set ' . STATTIC_PREVIEW_ALLOW_MAIL . ' to send it.',
            'webhook_held' => 'Preview webhook was not delivered. Set ' . STATTIC_PREVIEW_ALLOW_WEBHOOKS . ' to deliver it.',
            default => 'Preview refused a live payment call. Use test keys.',
        },
        'metadata' => ['event' => $event] + $metadata,
    ], (string) $guard['version_id']);
}
