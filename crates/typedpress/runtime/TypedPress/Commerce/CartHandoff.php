<?php

declare(strict_types=1);

namespace TypedPress\Commerce;

use Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils;
use TypedPress\Wp\AppRouter;
use TypedPress\Wp\Plugin;

final class CartHandoff
{
    private const LINK = '_typedpress_token_cart';

    private static bool $requested = false;
    private static ?string $source = null;
    private static ?\WC_Order $checkoutOrder = null;

    public static function register(Plugin $plugin): void
    {
        add_action('before_woocommerce_init', static fn () => self::prepare($plugin), 0);
        add_action('woocommerce_init', self::rememberCopy(...));
        add_action('wp_loaded', self::redirect(...), 1);
        add_action('woocommerce_store_api_checkout_update_order_meta', self::linkOrder(...));
        add_action('woocommerce_store_api_checkout_order_processed', static function (\WC_Order $order): void {
            self::$checkoutOrder = $order;
        });
        add_action('woocommerce_checkout_order_processed', static function ($id, $data, \WC_Order $order): void {
            self::linkOrder($order);
            self::$checkoutOrder = $order;
        }, 10, 3);
        add_action('woocommerce_before_cart_emptied', static function (): void {
            if (self::$checkoutOrder !== null) { self::settle(self::$checkoutOrder); }
        });
        // AppRouter serves the checkout page at priority 1.
        add_action('template_redirect', self::settleReceipt(...), 0);
    }

    public static function requested(): bool
    {
        return self::$requested || array_key_exists('session', $_GET);
    }

    private static function checkoutGet(Plugin $plugin): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || is_admin() || wp_doing_ajax() || WC()->is_rest_api_request()
            || array_intersect_key($_GET, array_flip(['wc-ajax', 'wc-api', 'rest_route', 'add-to-cart']))) {
            return false;
        }
        $id = Module::checkoutPage($plugin);
        $url = $id > 0 ? wp_parse_url(get_permalink($id)) : [];
        if (!isset($url['path']) || rtrim($url['path'], '/') !== rtrim(AppRouter::requestPath(), '/')) { return false; }
        // Plain permalinks select the page in the query. Reject query variables that would route elsewhere.
        wp_parse_str($url['query'] ?? '', $checkout);
        foreach ($checkout as $key => $value) {
            if (($_GET[$key] ?? null) !== $value) { return false; }
        }
        $endpoints = WC()->query->get_query_vars();
        $routing = array_merge($GLOBALS['wp']->public_query_vars, array_keys($endpoints), array_values($endpoints));
        return array_intersect_key(array_diff_key($_GET, $checkout), array_flip($routing)) === [];
    }

    /** Leave Woo's input in place only when its built-in guest clone can safely run. */
    private static function prepare(Plugin $plugin): void
    {
        if (!array_key_exists('session', $_GET)) { return; }
        self::$requested = true;
        $token = $_GET['session'];
        unset($_GET['session']);
        if (!self::checkoutGet($plugin) || !is_string($token)) { return; }
        $source = CartTokenUtils::get_cart_token_payload(wc_clean(wp_unslash($token)))['user_id'];
        if (!is_string($source) || !str_starts_with($source, 't_')) { return; }
        self::$source = $source;
        if (is_user_logged_in()) { return; }

        $handler = new \WC_Session_Handler();
        self::forgetCachedSession($source);
        $sourceRevision = self::revision($handler->get_session($source, [])['cart'] ?? null);
        if ($sourceRevision === null) { return; }
        $cookie = $handler->get_session_cookie();
        if ($cookie && (int) $cookie[1] >= time()) {
            if ($cookie[0] === $source) { return; }
            $saved = $handler->get_session($cookie[0], []);
            $revision = self::revision($saved['cart'] ?? null);
            $link = maybe_unserialize($saved[self::LINK] ?? null);
            if (($link['source'] ?? null) === $source) {
                if ($revision === null || $revision !== ($link['revision'] ?? null) || $revision === $sourceRevision) { return; }
            } elseif ($revision !== null) {
                return;
            }
        }

        // Woo skips a repeat clone if it sees the previous copy's cookie.
        unset($_COOKIE[Module::sessionCookie()]);
        $_GET['session'] = $token;
    }

    private static function rememberCopy(): void
    {
        if (self::$source === null || !isset($_GET['session'])) { return; }
        unset($_GET['session']);
        $session = WC()->session;
        if (!$session instanceof \WC_Session_Handler || $session->get('previous_customer_id') !== self::$source) { return; }
        $revision = self::revision($session->get('cart'));
        if ($revision !== null) {
            $session->set(self::LINK, ['source' => self::$source, 'revision' => $revision]);
        }
    }

    private static function redirect(): void
    {
        if (self::$source === null) { return; }
        header('Referrer-Policy: same-origin');
        wp_safe_redirect('/' . ltrim(remove_query_arg('session'), '/'));
        exit;
    }

    /** Woo cart keys identify the product, variation and custom item data. */
    private static function revision(mixed $cart): ?string
    {
        $cart = maybe_unserialize($cart);
        if (!is_array($cart) || $cart === []) { return null; }
        $lines = array_map(static fn ($line): int => (int) $line['quantity'], $cart);
        ksort($lines, SORT_STRING);
        return hash('sha256', serialize($lines));
    }

    private static function linkOrder(\WC_Order $order): void
    {
        $link = WC()->session?->get(self::LINK);
        if (!is_array($link) || $link['revision'] !== self::revision(WC()->cart->get_cart())) {
            if (!$order->meta_exists(self::LINK)) { return; }
            $link = [];
        }
        $order->update_meta_data(self::LINK, $link);
        $order->save_meta_data();
    }

    private static function settleReceipt(): void
    {
        $id = absint($GLOBALS['wp']->query_vars['order-received'] ?? 0);
        $key = $_GET['key'] ?? null;
        if ($id === 0 || !is_string($key) || !is_order_received_page()) { return; }
        $order = wc_get_order($id);
        if ($order instanceof \WC_Order && hash_equals($order->get_order_key(), wc_clean(wp_unslash($key)))) {
            self::settle($order);
        }
    }

    private static function settle(\WC_Order $order): void
    {
        $link = $order->get_meta(self::LINK);
        $source = $link['source'] ?? null;
        if (!is_string($source) || !str_starts_with($source, 't_') || !isset($link['revision']) || !empty($link['settled'])) { return; }
        global $wpdb;
        $table = $wpdb->prefix . 'woocommerce_sessions';
        $value = $wpdb->get_var($wpdb->prepare("SELECT session_value FROM %i WHERE session_key = %s", $table, $source));
        $data = maybe_unserialize($value);
        if (self::revision($data['cart'] ?? null) === $link['revision']) {
            // Compare the whole stored value byte for byte. A token write between the read and
            // this update wins, and the session row and its customer data remain intact.
            unset($data['cart'], $data['cart_totals'], $data['removed_cart_contents'], $data['store_api_draft_order'], $data['order_awaiting_payment']);
            $updated = $wpdb->query($wpdb->prepare("UPDATE %i SET session_value = %s WHERE session_key = %s AND HEX(session_value) = HEX(%s)",
                $table, maybe_serialize($data), $source, $value));
            if ($updated !== 1) { return; }
            self::forgetCachedSession($source);
        }
        // A later visit to the same receipt cannot clear a new cart with the same lines.
        $order->update_meta_data(self::LINK, $link + ['settled' => true]);
        $order->save_meta_data();
        if (WC()->session?->get(self::LINK) === $link) {
            WC()->session->set(self::LINK, null);
        }
    }

    /** Woo's token handler writes the table without updating the cookie handler's cache. */
    private static function forgetCachedSession(string $customer): void
    {
        wp_cache_delete(\WC_Cache_Helper::get_cache_prefix(WC_SESSION_CACHE_GROUP) . $customer, WC_SESSION_CACHE_GROUP);
    }
}
