<?php
declare(strict_types=1);

// Driver for runtime/tests/preview-side-effects.test.ts: real WordPress core,
// loaded only to SHORTINIT plus its HTTP and mail APIs, with the engine's
// preview guards installed for one version. Every request that gets past the
// guards is answered here and recorded, so nothing leaves the machine.
//
// argv[1]: {core, storage, space, version, variables}. stdout: one JSON line.

$request = json_decode((string) ($argv[1] ?? ''), true, 16, JSON_THROW_ON_ERROR);
define('ABSPATH', rtrim((string) $request['core'], '/') . '/');
// WordPress to SHORTINIT on a database drop-in that answers every query empty,
// then the HTTP and mail APIs wp-settings.php loads after it.
define('SHORTINIT', true);
define('WP_CONTENT_DIR', (string) $request['content']);
define('DB_NAME', 'none'); define('DB_USER', ''); define('DB_PASSWORD', ''); define('DB_HOST', '');
$table_prefix = 'wp_';
$_SERVER['HTTP_HOST'] = 'preview.invalid';
$_SERVER['REQUEST_URI'] = '/';
require ABSPATH . 'wp-settings.php';
// In wp-settings.php's own order; `class-wp-http.php` registers the Requests autoloader.
foreach (['class-wp-http.php', 'class-wp-http-streams.php', 'class-wp-http-curl.php', 'class-wp-http-proxy.php',
    'class-wp-http-cookie.php', 'class-wp-http-encoding.php', 'class-wp-http-response.php',
    'class-wp-http-requests-response.php', 'class-wp-http-requests-hooks.php', 'http.php',
    'general-template.php', 'link-template.php', 'kses.php', 'pluggable.php'] as $file) {
    require_once ABSPATH . WPINC . '/' . $file;
}

$engine = dirname(__DIR__, 2) . '/engine';
require_once $engine . '/shared/context.php';
require_once $engine . '/shared/storage.php';
require_once $engine . '/shared/spacepress.php';

$storage = (string) $request['storage'];
$space = (string) $request['space'];
$version = (string) $request['version'];
// The version's SpacePress build with the app's runtime variables, selected
// and bound the way a SpacePress request is (spacepress.php).
$compiled = $storage . '/spaces/' . $space . '/versions/' . $version . '/.spacepress-compiled';
@mkdir($compiled, 0755, true);
file_put_contents($compiled . '/manifest.json', json_encode(['format' => 'spacepress.hosted.v1', 'hostContract' => 1]));
file_put_contents($compiled . '/ready.json', '{}');
file_put_contents($compiled . '/variables.json', json_encode((object) $request['variables']));
_stattic_spacepress_select($storage, $space, $version, ['zero_generation' => 'spacepress']);
_stattic_spacepress_bind_wordpress();
$preview = isset($GLOBALS['SPACEFAST_PREVIEW_GUARD']);

$GLOBALS['preview_outbound'] = [];
$GLOBALS['preview_mailed'] = [];
add_filter('pre_http_request', static function ($pre, $args, $target) {
    if ($pre !== false) {
        return $pre;
    }
    $GLOBALS['preview_outbound'][] = strtolower((string) parse_url($target, PHP_URL_HOST));
    return ['headers' => [], 'body' => '{}', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
}, 0, 3);
add_filter('pre_wp_mail', static function ($pre, $atts) {
    if ($pre !== null) {
        return $pre;
    }
    $GLOBALS['preview_mailed'][] = 'wp:' . $atts['to'];
    return true;
}, 0, 2);

$code = static fn ($response) => is_wp_error($response) ? $response->get_error_code() : wp_remote_retrieve_response_code($response);
$stripe = static fn (string $key) => wp_remote_post('https://api.stripe.com/v1/payment_intents', [
    'headers' => ['Authorization' => 'Bearer ' . $key],
    'body' => ['amount' => 500, 'currency' => 'usd'],
]);
// The WooCommerce Stripe gateway's form: Basic auth over `key:`, headers as a string.
$stripeBasic = static fn (string $key) => wp_remote_post('https://api.stripe.com/v1/refunds', [
    'headers' => 'Authorization: Basic ' . base64_encode($key . ':') . "\r\nStripe-Version: 2024-06-20",
    'body' => ['charge' => 'ch_1'],
]);
$gateway = static fn (string $id, array $settings) => (object) ['id' => $id, 'settings' => $settings];

echo json_encode([
    'preview' => $preview,
    'stripeLive' => $code($stripe('sk_live_' . str_repeat('0', 24))),
    'stripeTest' => $code($stripe('sk_test_' . str_repeat('0', 24))),
    'stripeBasicLive' => $code($stripeBasic('sk_live_' . str_repeat('1', 24))),
    'paypalLive' => $code(wp_remote_post('https://api-m.paypal.com/v2/checkout/orders', ['body' => '{}'])),
    'paypalSandbox' => $code(wp_remote_post('https://api-m.sandbox.paypal.com/v2/checkout/orders', ['body' => '{}'])),
    'braintreeLive' => $code(wp_remote_post('https://payments.braintree-api.com/graphql', ['body' => '{}'])),
    'braintreeSandbox' => $code(wp_remote_post('https://payments.sandbox.braintree-api.com/graphql', ['body' => '{}'])),
    'adyenLive' => $code(wp_remote_post('https://example-merchant-checkout-live.adyenpayments.com/checkout/v72/payments', ['body' => '{}'])),
    'adyenTest' => $code(wp_remote_post('https://checkout-test.adyen.com/v72/payments', ['body' => '{}'])),
    'webhook' => $code(wp_remote_post('https://hooks.example.test/orders', [
        'headers' => ['Content-Type' => 'application/json', 'TypedPress-Signature' => 't=1,v1=abc'],
        'body' => '{"event":"orders.created"}',
    ])),
    'fetch' => $code(wp_remote_post('https://api.example.test/lookup', ['body' => '{}'])),
    'wpMail' => wp_mail('customer@example.test', 'Your order', 'Thanks'),
    // The engine's own SpacePress transport. A preview holds the message; live
    // hands it to the Space's mail broker, which this process has no route
    // to, so the attempt surfaces as that refusal.
    'appMail' => (static function (): mixed {
        try {
            return apply_filters('sp_mail_transport', null, ['to' => ['customer@example.test'], 'subject' => 'Receipt']);
        } catch (RuntimeException $error) {
            return $error->getMessage();
        }
    })(),
    'gateways' => array_keys(apply_filters('woocommerce_available_payment_gateways', [
        'stripe' => $gateway('stripe', ['testmode' => 'no']),
        'stripe_test' => $gateway('stripe_test', ['testmode' => 'yes']),
        'ppcp' => $gateway('ppcp', ['sandbox' => 'no']),
        'cod' => $gateway('cod', []),
    ])),
    'wooWebhookDelivers' => apply_filters('woocommerce_webhook_should_deliver', true, null, null),
    'outbound' => $GLOBALS['preview_outbound'],
    'mailed' => $GLOBALS['preview_mailed'],
    'held' => $GLOBALS['SPACEFAST_PREVIEW_GUARD']['held'] ?? [],
], JSON_UNESCAPED_SLASHES), "\n";
