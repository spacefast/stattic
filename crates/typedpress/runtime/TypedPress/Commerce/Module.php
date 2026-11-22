<?php

declare(strict_types=1);

namespace TypedPress\Commerce;

use Automattic\WooCommerce\Blocks\Assets\Api as BlockAssets;
use Automattic\WooCommerce\Blocks\Package as Blocks;
use Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils;
use TypedPress\Render\Cache;
use TypedPress\Vendor\WordPress\DataLiberation\BlockMarkup\BlockMarkupProcessor;
use TypedPress\Wp\AppRouter;
use TypedPress\Wp\ContentPages;
use TypedPress\Wp\Plugin;
use TypedPress\Wp\Runtime;

/** Source settings and deliberately small v1 Woo policy, active only with a store() export. */
final class Module
{
    public static function register(Plugin $plugin): void
    {
        $store = $plugin->schema->commerce();
        if ($store === null) { return; }
        \TypedPress\Extensions::route('store', static function ($method, $segments) use ($plugin): array {
            \TypedPress\App::allow($method, ['GET', 'HEAD']);
            if (count($segments) !== 1) { throw \TypedPress\HttpError::notFound('No such store route'); }
            return [200, self::information($plugin)];
        });
        \TypedPress\Extensions::normalizeWrite(static function (string $op, string $collection, array &$data) use ($plugin): void {
            if ($plugin->schema->hasCollection($collection) && ($plugin->schema->collection($collection)['commerce'] ?? '') === 'product' && array_key_exists('price', $data) && $data['price'] !== null && !is_string($data['price'])) { throw \TypedPress\HttpError::invalidInput('Price must be an exact decimal string', '/price'); }
        });
        \TypedPress\Extensions::beforeRead(static function (string $collection, array &$opts, ?\TypedPress\Viewer $viewer) use ($plugin): void {
            if (!$plugin->schema->hasCollection($collection)) { return; }
            $spec = $plugin->schema->collection($collection);
            $kind = $spec['commerce'] ?? '';
            if (!in_array($kind, ['product', 'order'], true)) { return; }
            if ($kind === 'order' && !\TypedPress\Wp\AgentScope::may('personal')) { throw \TypedPress\Wp\AgentScope::refusal('personal'); }
            $fields = $kind === 'product' ? ['id', 'uid', 'name', 'slug', 'description', 'shortDescription', 'status', 'sku', 'price', 'availability', 'virtual', 'downloadable', 'categories', 'tags'] : ['id', 'status', 'createdAt', 'updatedAt', 'customer', 'total', 'currency'];
            if ($kind === 'product') {
                foreach ($spec['columns'] as $name => $column) {
                    if (($column['commerceMeta'] ?? false) || $name === 'stockQuantity') { $fields[] = $name; }
                }
            }
            $where = static function (array $filter) use (&$where, $fields): void {
                foreach ($filter as $key => $value) {
                    if (in_array($key, ['AND', 'OR'], true)) { foreach ($value as $part) { $where($part); } }
                    elseif ($key === 'NOT') { $where($value); }
                    elseif (!in_array($key, $fields, true)) { throw \TypedPress\HttpError::invalidInput('This commerce field is not filterable in v1', '/where/' . $key); }
                }
            };
            if (is_array($opts['where'] ?? null)) { $where($opts['where']); }
            foreach ($opts['orderBy'] ?? [] as $key => $value) {
                if (!in_array($key, $fields, true)) { throw \TypedPress\HttpError::invalidInput('This commerce field is not sortable in v1', '/orderBy/' . $key); }
            }
        }, true);
        add_filter('tp_ability_operations', static function (array $ops, string $app) use ($plugin): array {
            $ops[] = ['name' => $app . '/store-get', 'op' => 'get', 'agents' => true, 'typedpress' => ['store' => true], 'label' => 'Read store information', 'description' => 'Currency, precision, store country and checkout URL.', 'input' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false], 'output' => ['type' => 'object'], 'annotations' => ['readonly' => true, 'idempotent' => true], 'rule' => [['role' => 'anyone'], 'read', 'read store'], 'run' => static fn () => self::information($plugin)];
            return $ops;
        }, 10, 2);
        global $wpdb;
        if (strlen($wpdb->prefix) > 20) { throw new \RuntimeException('WooCommerce requires a WordPress table prefix of at most 20 characters'); }
        if (!defined('WC_VERSION') || version_compare(WC_VERSION, '11.3.0', '<')) { throw new \RuntimeException('This store requires WooCommerce 11.3.0 or later'); }
        // A managed build is refused before a host exposes it. A plugin install reports the same
        // problem from onInit(), so wp-admin stays usable for changing order storage back.
        if (Runtime::managed()) { self::checkOrderStorage(); }
        add_action('before_woocommerce_init', static function () use ($plugin): void {
            if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', $plugin->dir . '/' . basename($plugin->dir) . '.php', false);
            }
        });
        $reviews = false;
        foreach ($plugin->schema->kinds() as $spec) {
            if (($spec['commerce'] ?? '') === 'product' && ($spec['comments'] ?? false)) { $reviews = true; }
            if (($spec['commerce'] ?? '') === 'product' && isset($spec['columns']['stockQuantity'])) {
                Operations::register($plugin);
            }
        }
        $options = [
            'woocommerce_enable_coupons' => 'no', 'woocommerce_enable_reviews' => $reviews ? 'yes' : 'no', 'woocommerce_enable_review_rating' => 'no',
            'woocommerce_enable_signup_and_login_from_checkout' => 'no', 'woocommerce_enable_myaccount_registration' => 'no', 'woocommerce_enable_delayed_account_creation' => 'no',
            'woocommerce_enable_guest_checkout' => 'yes', 'woocommerce_calc_taxes' => 'no', 'woocommerce_coming_soon' => 'no',
            'woocommerce_currency' => $store['currency'], 'woocommerce_default_country' => $store['country'],
        ];
        foreach (['line1' => 'address', 'line2' => 'address_2', 'city' => 'city', 'postcode' => 'postcode'] as $field => $suffix) { if (isset($store['address'][$field])) { $options['woocommerce_store_' . $suffix] = $store['address'][$field]; } }
        foreach (['weight', 'dimension'] as $field) { if (isset($store['units'][$field])) { $options['woocommerce_' . $field . '_unit'] = $store['units'][$field]; } }
        foreach (['fromName' => 'name', 'fromAddress' => 'address'] as $field => $suffix) { if (isset($store['email'][$field])) { $options['woocommerce_email_from_' . $suffix] = $store['email'][$field]; } }
        if (isset($store['sellTo'])) {
            $options['woocommerce_allowed_countries'] = $store['sellTo'] === 'all' ? 'all' : 'specific';
            $options['woocommerce_specific_allowed_countries'] = $store['sellTo'] === 'all' ? [] : $store['sellTo'];
            $options['woocommerce_ship_to_countries'] = ''; // Woo: ship to every selling country.
        }
        foreach ($options as $name => $value) { add_filter('pre_option_' . $name, static fn () => $value, PHP_INT_MAX); }
        foreach (['woocommerce_coupons_enabled', 'wc_tax_enabled', 'woocommerce_product_backorders_allowed', 'woocommerce_product_is_on_sale'] as $hook) { add_filter($hook, '__return_false', PHP_INT_MAX); }
        add_filter('woocommerce_product_get_backorders', static fn () => 'no', PHP_INT_MAX);
        add_filter('woocommerce_product_get_stock_status', static fn ($status) => $status === 'onbackorder' ? 'outofstock' : $status, PHP_INT_MAX);
        add_filter('woocommerce_product_get_sale_price', static fn () => '', PHP_INT_MAX);
        add_filter('woocommerce_product_get_price', static fn ($price, $product) => $product->get_regular_price(), PHP_INT_MAX, 2);
        add_filter('woocommerce_create_pages', '__return_empty_array', PHP_INT_MAX);
        add_filter('woocommerce_get_cart_url', static fn () => home_url('/cart'));
        add_filter('woocommerce_get_checkout_page_id', static fn ($id) => self::checkoutPage($plugin) ?: $id);
        add_action('before_woocommerce_init', self::isolateSharedCart(...), 0);
        add_action('before_woocommerce_init', self::copyTokenCartAgain(...), 0);
        // Every response Woo sets a cookie on is that visitor's, whichever endpoint or extension set it
        // (`/checkout-link/` sends no cache headers of its own).
        add_filter('woocommerce_set_cookie_enabled', static function ($enabled) {
            if ($enabled && !headers_sent()) { Cache::send(Cache::PRIVATE); }
            return $enabled;
        }, PHP_INT_MAX);
        add_action('wp_loaded', static function (): void {
            // Woo can handle and redirect a cart action before WordPress sends its page headers.
            if (self::cartAction()) { Cache::send(Cache::PRIVATE); }
            self::dropSessionToken();
        }, 0);
        add_filter('wp_headers', static function (array $headers) use ($plugin): array {
            // A page the theme renders keeps the visitor's cart session (isolateSharedCart() covers
            // app pages only), so Woo prints its cart-dependent prefetch links there: that visitor's page.
            if (self::privateRequest($plugin) || self::cartSession($plugin)) {
                $headers['Cache-Control'] = Cache::PRIVATE;
                $headers[Cache::EDGE_HEADER] = 'no-cache';
                if (function_exists('batcache_cancel')) { batcache_cancel(); }
            }
            return $headers;
        }, PHP_INT_MAX);
        add_action('wp_head', static fn () => self::checkoutPrefetchAsset($plugin));
        // Every Store API answer: a cart, an order's addresses (`order/<id>`), a shopper's lists.
        add_filter('rest_post_dispatch', static function ($response, $server, $request) {
            if (self::storeApiRoute($request->get_route())) {
                $response->header('Cache-Control', Cache::PRIVATE);
                $response->header(Cache::EDGE_HEADER, 'no-cache');
                if (function_exists('batcache_cancel')) { batcache_cancel(); }
            }
            return $response;
        }, 10, 3);
        // A declared external frontend is this store's storefront. Its first Store API call carries no
        // Cart-Token yet, and Woo answers another origin only with one or when WordPress allows the origin.
        // After Woo's own CORS headers (Authentication::send_cors_headers, priority 10).
        $frontend = $plugin->schema->frontend();
        if (is_string($frontend)) {
            add_filter('rest_pre_serve_request', static fn ($served, $result, $request, $server) => self::storefrontCors($served, $request, $server, $frontend), 11, 4);
        }
        // An order placed from a cart a token handed over (`?session=`) also ends that token's cart.
        // Woo empties the cart in the checkout request, or, for a gateway that sends the shopper
        // away to pay, on the page they come back to (wc_clear_cart_after_payment, the receipt).
        foreach (['woocommerce_checkout_order_processed', 'woocommerce_store_api_checkout_order_processed'] as $hook) {
            add_action($hook, static function (): void { self::$ordered = true; });
        }
        add_filter('woocommerce_should_clear_cart_after_payment', static function ($clear) {
            if ($clear) { self::$ordered = true; }
            return $clear;
        }, PHP_INT_MAX);
        add_action('woocommerce_before_cart_emptied', self::endTokenCart(...));
        if (isset($store['payments'])) {
            add_filter('woocommerce_available_payment_gateways', static function (array $gateways) use ($store): array {
                $out = []; foreach ($store['payments'] as $id) { if (isset($gateways[$id])) { $out[$id] = $gateways[$id]; } } return $out;
            }, PHP_INT_MAX);
            // Source enables built-in offline gateways too; credentials for other gateways remain Woo's.
            foreach ($store['payments'] as $id) {
                if (in_array($id, ['cod', 'bacs', 'cheque'], true)) { foreach (['option_', 'default_option_'] as $prefix) { add_filter($prefix . 'woocommerce_' . $id . '_settings', static fn ($settings) => array_replace(is_array($settings) ? $settings : [], ['enabled' => 'yes'])); } }
            }
        }
        add_action('init', static fn () => self::onInit($plugin), 20);
        // Source-owned fields are visibly locked, including disabled v1 features.
        add_filter('woocommerce_get_settings_products', static fn ($settings) => self::settings($settings, $options), PHP_INT_MAX);
        foreach (['general', 'tax', 'accounts', 'email'] as $section) { add_filter('woocommerce_get_settings_' . $section, static fn ($settings) => self::settings($settings, $options), PHP_INT_MAX); }
        if (array_key_exists('shipping', $store)) {
            foreach (['shipping_zones_save_changes', 'shipping_zone_add_method', 'shipping_zone_remove_method', 'shipping_zone_methods_save_changes', 'shipping_zone_methods_save_settings'] as $action) {
                add_action('wp_ajax_woocommerce_' . $action, static function (): void { wp_send_json_error(['message' => 'Shipping zones are managed by TypedPress source.'], 403); }, 0);
            }
            add_action('woocommerce_settings_shipping', static function (): void {
                echo '<div class="notice notice-info inline"><p>Shipping zones are managed by TypedPress source.</p></div><script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll("#mainform input,#mainform button,#mainform select,#mainform textarea,.wc-shipping-zone-methods .wc-shipping-zone-method-settings").forEach(function(el){el.disabled=true;el.setAttribute("aria-disabled","true");});});</script>';
            }, 1);
        }
        foreach (['pre_delete_post', 'pre_trash_post'] as $hook) { add_filter($hook, static fn ($check, $post) => $post instanceof \WP_Post && $post->ID === self::checkoutPage($plugin) ? false : $check, PHP_INT_MAX, 2); }
        add_filter('wp_insert_post_data', static function (array $data, array $input) use ($plugin): array {
            $id = (int) ($input['ID'] ?? 0);
            if ($id > 0 && $id === self::checkoutPage($plugin) && !self::validCheckout(wp_unslash($data['post_content'] ?? ''))) {
                $data['post_content'] = wp_slash(get_post_field('post_content', $id, 'raw'));
            }
            return $data;
        }, PHP_INT_MAX, 2);
        add_filter('tp_markdown_twin', static fn ($has, $post) => $has && ($post->post_type !== 'page' || !in_array((int) $post->ID, self::shopperPageIds(), true)), 10, 2);
        add_filter('wp_sitemaps_posts_query_args', static function (array $args, string $type): array {
            if ($type === 'page') { $args['post__not_in'] = [...($args['post__not_in'] ?? []), ...self::shopperPageIds()]; }
            return $args;
        }, 10, 2);
        // Woo handles checkout outside TypedPress REST, including Store API batch subrequests.
        add_filter('rest_request_before_callbacks', static function ($result, $handler, $request) use ($plugin) {
            if ($plugin->unavailable() !== null && preg_match('~^/wc/store/(?:v[0-9]+/)?checkout(?:/|$)~i', $request->get_route())) {
                return new \WP_Error('tp_unavailable', Plugin::UNAVAILABLE, ['status' => 503]);
            }
            return $result;
        }, 10, 3);
        add_action('woocommerce_before_checkout_process', static function () use ($plugin): void {
            if ($plugin->unavailable() !== null) { throw new \Exception(Plugin::UNAVAILABLE); }
        }, 0);
        add_action('woocommerce_before_pay_action', static function () use ($plugin): void {
            if ($plugin->unavailable() !== null) { wp_die(esc_html(Plugin::UNAVAILABLE), '', ['response' => 503]); }
        }, 0);
        add_filter('wp_robots', static function ($robots) use ($plugin) { if (self::privateRequest($plugin)) { $robots['noindex'] = true; } return $robots; });
        foreach (['woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_delete_product'] as $hook) { add_action($hook, static fn ($id) => self::changed($plugin, 'product', (int) $id)); }
        foreach (['created_term', 'edited_term', 'delete_term'] as $hook) { add_action($hook, static function ($id, $tt, $taxonomy) use ($plugin): void { $kind = ['product_cat' => 'productCategory', 'product_tag' => 'productTag'][$taxonomy] ?? null; if ($kind) { self::changed($plugin, $kind, (int) $id); } }, 10, 3); }
        add_action('template_redirect', static function () use ($plugin): void {
            // A feed of the product archive (`?feed=rss2&post_type=product`) is its own answer, not a storefront page.
            if (is_feed()) { return; }
            $canonical = is_singular('product') ? get_permalink() : ((is_tax('product_cat') || is_tax('product_tag')) ? get_term_link(get_queried_object()) : (is_post_type_archive('product') ? home_url('/shop') : null));
            if ($canonical === null) { return; }
            if (!is_string($canonical) || !\TypedPress\Wp\AppRouter::instance()?->serves($canonical)) {
                $GLOBALS['wp_query']->set_404(); status_header(404); return;
            }
            if (rtrim(AppRouter::requestPath(), '/') !== rtrim((string) wp_parse_url($canonical, PHP_URL_PATH), '/')) { wp_safe_redirect($canonical, 301); exit; }
        }, 0);
        foreach (['woocommerce_product_set_stock', 'woocommerce_product_set_stock_status'] as $hook) {
            add_action($hook, static function ($subject) use ($plugin): void { $id = $subject instanceof \WC_Product ? $subject->get_id() : (int) $subject; $post = get_post($id); if ($post) { \TypedPress\Wp\Purges::saved($plugin, $id, $post); self::changed($plugin, 'product', $id); } }, 10, 1);
        }
    }

    /** Request-time installation keeps wp-admin usable on failure; managed initialization still throws. */
    public static function onInit(Plugin $plugin): void
    {
        $type = get_post_type_object('product'); if ($type) { $type->show_in_abilities = false; }
        try {
            if (Runtime::installs()) {
                self::checkOrderStorage();
                self::initialize($plugin);
            }
        } catch (\Throwable $e) {
            $problem = $e->getMessage();
            $plugin->refuse($problem); \TypedPress\Wp\InstallMarker::forget();
            error_log('[typedpress] ' . $problem);
            add_action('admin_notices', static function () use ($problem): void {
                if (current_user_can('manage_options')) { printf('<div class="notice notice-error"><p>%s</p></div>', esc_html('TypedPress: ' . $problem)); }
            });
        }
    }

    private static function changed(Plugin $plugin, string $resource, int $id): void
    {
        foreach ($plugin->schema->kinds() as $name => $spec) {
            if (($spec['commerce'] ?? '') === $resource) {
                $plugin->app();
                \TypedPress\Render\Cache::written('update', (string) $name, ['id' => $id], null);
                return;
            }
        }
    }

    private static function settings(array $settings, array $options): array
    {
        foreach ($settings as &$setting) { if (array_key_exists($setting['id'] ?? '', $options)) { $setting['custom_attributes']['disabled'] = 'disabled'; $setting['desc'] = ($setting['desc'] ?? '') . ' Managed by TypedPress source.'; } } unset($setting); return $settings;
    }
    public static function checkOrderStorage(): void
    {
        global $wpdb;
        // Both options in one statement, from the database rather than the option cache.
        $values = $wpdb->get_col("SELECT option_value FROM {$wpdb->options} WHERE option_name IN ('woocommerce_custom_orders_table_enabled', 'woocommerce_custom_orders_table_data_sync_enabled')");
        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('Unable to verify WooCommerce order storage');
        }
        if (in_array('yes', $values, true)) {
            throw new \RuntimeException('TypedPress commerce requires legacy order storage with HPOS synchronization disabled. Change storage in WooCommerce after completing synchronization.');
        }
    }

    /** The same pending work is used by managed inspection and installation. */
    public static function plan(Plugin $plugin): array
    {
        $store = $plugin->schema->commerce(); if ($store === null || !array_key_exists('shipping', $store)) { return []; }
        $key = 'tp_' . $plugin->schema->name() . '_shipping';
        $hash = hash('sha256', (string) wp_json_encode($store['shipping']));
        wp_cache_delete($key, 'options'); wp_cache_delete('alloptions', 'options'); wp_cache_delete('notoptions', 'options');
        return get_option($key) === ['source' => $hash, 'state' => self::shippingState()] ? [] : [Runtime::step('commerce-shipping', 'commerce-shipping')];
    }
    public static function initialize(Plugin $plugin): void
    {
        if (self::plan($plugin) === []) { return; }
        $connection = $plugin->connection();
        \TypedPress\Wp\Migrator::locked($connection, 'commerce_shipping', static function () use ($plugin, $connection): void {
            $connection->transaction(static fn () => \TypedPress\Wp\Functions::tracked(static fn () => self::reconcileShipping($plugin)));
        });
    }
    private static function reconcileShipping(Plugin $plugin): void
    {
        if (self::plan($plugin) === []) { return; }
        $store = $plugin->schema->commerce();
        $key = 'tp_' . $plugin->schema->name() . '_shipping';
        $hash = hash('sha256', (string) wp_json_encode($store['shipping']));
        $existing = \WC_Shipping_Zones::get_zones(); $kept = [];
        foreach ($store['shipping'] as $index => $zone) {
            $id = 0;
            if ($zone['countries'] !== 'rest') {
                if ($zone['name'] === '0' || wc_clean($zone['name']) !== $zone['name']) {
                    throw new \RuntimeException('Shipping zone names must match their WooCommerce representation');
                }
                foreach ($existing as $candidate) { if ($candidate['zone_name'] === $zone['name']) { $id = (int) $candidate['zone_id']; break; } }
                $z = new \WC_Shipping_Zone($id ?: null);
                $z->set_zone_name($zone['name']); $z->set_zone_order($index);
                $countries = array_values(array_unique($zone['countries']));
                $z->set_zone_locations(array_map(static fn ($code) => (object) ['code' => $code, 'type' => 'country'], $countries));
                if (!$z->save()) { throw new \RuntimeException('Could not save source shipping zone ' . $zone['name']); }
                $id = $z->get_id();
                $saved = new \WC_Shipping_Zone($id);
                $locations = array_map(static fn ($loc) => $loc->type . ':' . $loc->code, $saved->get_zone_locations('edit'));
                $expected = array_map(static fn ($code) => 'country:' . $code, $countries);
                sort($locations); sort($expected);
                if ($locations !== $expected) { throw new \RuntimeException('Could not persist source shipping locations'); }
                if ($saved->get_zone_order('edit') !== $index || $saved->get_zone_name('edit') !== $zone['name']) {
                    throw new \RuntimeException('Could not persist source shipping zone order or name');
                }
                $kept[] = $id;
            } else { $z = new \WC_Shipping_Zone(0); }
            self::clearMethods($z);
            foreach ($zone['methods'] as $method) {
                $type = ['flatRate' => 'flat_rate', 'freeShipping' => 'free_shipping', 'localPickup' => 'local_pickup'][$method['type']];
                $instance = $z->add_shipping_method($type);
                if (!$instance) { throw new \RuntimeException('Could not add source shipping method ' . $type); }
                $settings = ['title' => $method['title'], 'enabled' => 'yes', 'tax_status' => 'none'];
                if (isset($method['cost'])) { $settings['cost'] = $method['cost']; }
                if (isset($method['minimum'])) { $settings['requires'] = 'min_amount'; $settings['min_amount'] = $method['minimum']; }
                $option = 'woocommerce_' . $type . '_' . $instance . '_settings';
                update_option($option, $settings);
                if (get_option($option) !== $settings) { throw new \RuntimeException('Could not save source shipping method settings'); }
            }
        }
        foreach ($existing as $zone) { if (!in_array((int) $zone['zone_id'], $kept, true)) { (new \WC_Shipping_Zone($zone['zone_id']))->delete(); } }
        global $wpdb;
        $rows = \WC_Data_Store::load('shipping-zone')->get_zones();
        if ($wpdb->last_error !== '' || array_map('intval', array_column($rows ?? [], 'zone_id')) !== $kept) {
            throw new \RuntimeException('Persisted shipping zones do not match the source');
        }
        if (!array_filter($store['shipping'], static fn ($z) => $z['countries'] === 'rest')) { $rest = new \WC_Shipping_Zone(0); self::clearMethods($rest); }
        \WC_Cache_Helper::get_transient_version('shipping', true);
        update_option($key, ['source' => $hash, 'state' => self::shippingState()], false);
    }
    private static function persistedMethods(\WC_Shipping_Zone $zone): array
    {
        global $wpdb;
        $methods = $zone->get_data_store()->get_methods($zone->get_id(), false);
        if ($wpdb->last_error !== '') { throw new \RuntimeException('Could not read shipping methods'); }
        return $methods;
    }
    private static function clearMethods(\WC_Shipping_Zone $zone): void
    {
        foreach (self::persistedMethods($zone) as $method) { $zone->delete_shipping_method($method->instance_id); }
        if (self::persistedMethods($zone) !== []) { throw new \RuntimeException('Could not remove shipping methods'); }
    }
    private static function shippingState(): string
    {
        $state = [];
        foreach ([...array_keys(\WC_Shipping_Zones::get_zones()), 0] as $id) {
            $zone = new \WC_Shipping_Zone($id);
            $methods = [];
            $registered = $zone->get_shipping_methods();
            foreach ($registered as $method) { $methods[] = [$method->id, $method->enabled, get_option($method->get_instance_option_key())]; }
            foreach (self::persistedMethods($zone) as $row) {
                if (!isset($registered[$row->instance_id])) { $methods[] = ['unregistered', $row->method_id, $row->instance_id]; }
            }
            // Zone zero has no stored name. Retain the original English receipt across locales.
            $name = (int) $id === 0 ? 'Locations not covered by your other zones' : $zone->get_zone_name('edit');
            $state[] = [$name, $zone->get_zone_order(), $zone->get_zone_locations(), $methods];
        }
        return hash('sha256', (string) wp_json_encode($state));
    }

    private static function validCheckout(string $content): bool
    {
        // Core recovers truncated blocks at EOF. Reject incomplete edits before using its parsed tree.
        $markup = new BlockMarkupProcessor($content);
        while ($markup->next_token()) {
            if ($markup->get_last_error() !== null) { return false; }
        }
        return $markup->get_last_error() === null && $markup->get_block_depth() === 0
            && self::containsCheckout(parse_blocks($content));
    }

    private static function containsCheckout(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if ($block['blockName'] === 'woocommerce/checkout') {
                // Woo expands this legacy wrapper. Current templates carry the Place Order controls.
                if ($block['innerBlocks'] === [] && preg_match('~^<div class="(?:[a-z0-9_-]+ +)*wp-block-woocommerce-checkout(?: +[a-z0-9_-]+)*"></div>$~iD', trim($block['innerHTML']))) {
                    return true;
                }
                $wrapper = new \WP_HTML_Tag_Processor($block['innerHTML']);
                if (!$wrapper->next_tag(['tag_name' => 'DIV', 'class_name' => 'wp-block-woocommerce-checkout'])) {
                    continue;
                }
                $content = serialize_block($block);
                if (has_block('woocommerce/checkout-actions-block', $content)) { return true; }
                $tags = new \WP_HTML_Tag_Processor($content);
                while ($tags->next_tag('div')) {
                    if ($tags->get_attribute('data-block-name') === 'woocommerce/checkout-actions-block') { return true; }
                }
            }
            if (self::containsCheckout($block['innerBlocks'])) {
                return true;
            }
        }
        return false;
    }

    public static function checkoutPage(Plugin $plugin): int
    {
        $path = $plugin->schema->commerce()['checkout'] ?? '/checkout';
        foreach ($plugin->schema->app()['content'] ?? [] as $route => $page) {
            if (rtrim($page['path'], '/') === rtrim($path, '/')) {
                // The id comes from the autoloaded page record and the post cache: no query of its own,
                // on a filter Woo applies to every request (WC_Cache_Helper::prevent_caching).
                $id = ContentPages::pageId((string) $route) ?? 0;
                return $id > 0 && get_post_status($id) !== 'trash' ? $id : 0;
            }
        }
        return 0;
    }
    public static function privatePath(Plugin $plugin, string $path): bool
    {
        if ($plugin->schema->commerce() === null) { return false; }
        $path = '/' . implode('/', array_map('rawurldecode', array_values(array_filter(explode('/', $path), static fn ($part) => $part !== ''))));
        $private = ['/cart', rtrim($plugin->schema->commerce()['checkout'] ?? '/checkout', '/'), '/my-account'];
        foreach (self::shopperPageIds() as $id) {
            $page = get_page_uri($id);
            if ($page) { $private[] = '/' . rawurldecode($page); }
            if ($id === (int) get_option('page_on_front') && get_option('show_on_front') === 'page') { $private[] = '/'; }
        }
        foreach ($private as $root) {
            if ($path === $root || str_starts_with($path, $root . '/')) { return true; }
        }
        return false;
    }

    public static function privateRequest(Plugin $plugin): bool
    {
        if ($plugin->schema->commerce() === null) { return false; }
        $path = AppRouter::requestPath();
        $base = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        return self::cartAction() || self::privatePath($plugin, $path) || is_cart() || is_checkout() || is_account_page();
    }

    /**
     * Woo's session cookie reached Woo on this request: {@see isolateSharedCart()} did not take it
     * out, so what Woo prints may be this visitor's cart. Such an answer is never a shared one.
     */
    public static function cartSession(Plugin $plugin): bool
    {
        return $plugin->schema->commerce() !== null && isset($_COOKIE[self::sessionCookie()]);
    }

    /**
     * Lets the external frontend read Store API answers, and nothing else: only a dispatched
     * `/wc/store/` route, only that exact origin, and without credentials. A token cart sends no
     * cookie, so a script there never reads a visitor's cookie cart, address or sign-in through this.
     * WordPress's `allowed_http_origins` is left alone: that list also opens admin-ajax to an origin.
     */
    public static function storefrontCors(mixed $served, \WP_REST_Request $request, \WP_REST_Server $server, string $frontend): mixed
    {
        $url = wp_parse_url($frontend);
        if (!self::storeApiRoute($request->get_route()) || !isset($url['scheme'], $url['host'])) { return $served; }
        // As a browser writes an origin: lower case, and no port when it is the scheme's own.
        $scheme = strtolower($url['scheme']);
        $port = isset($url['port']) && $url['port'] !== (['http' => 80, 'https' => 443][$scheme] ?? 0) ? ':' . $url['port'] : '';
        $origin = $scheme . '://' . strtolower($url['host']) . $port;
        if (($_SERVER['HTTP_ORIGIN'] ?? null) !== $origin) { return $served; }
        $server->send_header('Access-Control-Allow-Origin', $origin);
        $server->remove_header('Access-Control-Allow-Credentials');
        return $served;
    }

    private static function storeApiRoute(string $route): bool
    {
        return str_starts_with(strtolower($route), '/wc/store/');
    }

    /** @return list<int> */
    private static function shopperPageIds(): array
    {
        // Woo's page ids are not autoloaded. isolateSharedCart() reaches here before Woo reads them all
        // at once (WC_Cache_Helper::prevent_caching), so read the same six together: one statement, not
        // one per wc_get_page_id(), and none left for Woo.
        wp_prime_option_caches(array_map(static fn (string $page) => "woocommerce_{$page}_page_id", ['cart', 'checkout', 'coming_soon', 'myaccount', 'shop', 'terms']));
        return array_values(array_filter(array_map('wc_get_page_id', ['cart', 'checkout', 'myaccount']), static fn (int $id) => $id > 0));
    }

    private static function cartAction(): bool
    {
        return array_intersect_key($_GET, array_flip([
            'add-to-cart', 'wc-ajax', 'wc-api', 'session', 'remove_coupon', 'remove_item', 'undo_item',
        ])) !== [];
    }

    /**
     * The browser owns cart presentation on shared pages. Before Woo initializes its session,
     * make a guest page render the empty cart, regardless of which cookies the browser sent.
     */
    private static function isolateSharedCart(): void
    {
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true) || is_admin() || wp_doing_ajax()
            || WC()->is_rest_api_request() || is_user_logged_in() || self::cartAction()
            || !AppRouter::instance()?->serves(AppRouter::requestPath())) {
            return;
        }
        unset($_COOKIE[self::sessionCookie()], $_COOKIE['woocommerce_cart_hash'], $_COOKIE['woocommerce_items_in_cart']);
    }

    /** The guest session a valid `?session=` cart token names, or null. */
    private static function sessionToken(): ?string
    {
        $token = $_GET['session'] ?? null;
        if (!is_string($token) || $token === '') { return null; }
        $customer = CartTokenUtils::get_cart_token_payload(wc_clean(wp_unslash($token)))['user_id'];
        return is_string($customer) && str_starts_with($customer, 't_') ? $customer : null;
    }

    /**
     * Woo copies a token's cart into the cookie session once: a cookie session that already holds a
     * copy keeps it, however the token's cart changed since (WC_Session_Handler::init_session_from_request).
     * Without the cookie Woo copies again, into a new session. A cookie that is the token's own
     * session needs no copy and is kept.
     */
    private static function copyTokenCartAgain(): void
    {
        $customer = self::sessionToken();
        $cookie = $_COOKIE[self::sessionCookie()] ?? null;
        if ($customer === null) { return; }
        self::forgetCachedSession($customer);
        if (is_string($cookie) && explode('|', $cookie)[0] !== $customer) { unset($_COOKIE[self::sessionCookie()]); }
    }

    /**
     * A token session is written to the table only (StoreApi\SessionHandler::save_data), while the
     * cookie session reads another session through the object cache (WC_Session_Handler::get_session).
     * With a persistent object cache that read would be the token's cart as it was at the first copy.
     */
    private static function forgetCachedSession(string $customer): void
    {
        wp_cache_delete(\WC_Cache_Helper::get_cache_prefix(WC_SESSION_CACHE_GROUP) . $customer, WC_SESSION_CACHE_GROUP);
    }

    /**
     * A cart token is a credential for 48 hours. Once Woo has copied its cart, the visitor goes on
     * at the same address without it, so it stays out of the address bar, history and referrers.
     */
    private static function dropSessionToken(): void
    {
        // A page a browser navigates to, where Woo started its cookie session: that is where it reads `?session=`.
        if (self::sessionToken() === null || !in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)
            || is_admin() || wp_doing_ajax() || WC()->is_rest_api_request() || isset($_GET['rest_route'])
            || !WC()->session instanceof \WC_Session_Handler) {
            return;
        }
        header('Referrer-Policy: same-origin');
        // A path on this site, whatever the request line's leading slashes read as.
        wp_safe_redirect('/' . ltrim(remove_query_arg('session'), '/'));
        exit;
    }

    /** This request placed an order, or Woo found the session's order paid. */
    private static bool $ordered = false;

    /** The request is a receipt address with its order's key: the page a shopper returns to after paying. */
    private static function receipt(): bool
    {
        $order = wc_get_order(absint($GLOBALS['wp']->query_vars['order-received'] ?? 0));
        $key = $_GET['key'] ?? null;
        return $order instanceof \WC_Order && is_string($key) && hash_equals($order->get_order_key(), wc_clean(wp_unslash($key)));
    }

    /**
     * Woo empties the cookie cart after an order. When that cart is the copy of a token's cart and the
     * token's cart still holds the same lines, the order bought them: the token's session ends too, so
     * the storefront that handed it over does not show, or hand over, a cart already paid for.
     */
    private static function endTokenCart(): void
    {
        $customer = WC()->session?->get('previous_customer_id');
        if (!is_string($customer) || !str_starts_with($customer, 't_') || !(self::$ordered || self::receipt())) { return; }
        self::forgetCachedSession($customer);
        $quantities = static fn ($cart): array => array_map(static fn ($line) => (int) ($line['quantity'] ?? 0), is_array($cart) ? $cart : []);
        $ordered = $quantities(WC()->cart->get_cart());
        $kept = $quantities(maybe_unserialize(((array) WC()->session->get_session($customer, []))['cart'] ?? []));
        ksort($ordered); ksort($kept);
        if ($ordered === $kept) { WC()->session->delete_session($customer); }
    }

    /** Woo's session cookie name, as WC_Session_Handler builds it. */
    private static function sessionCookie(): string
    {
        return (string) apply_filters('woocommerce_cookie', 'wp_woocommerce_session_' . COOKIEHASH);
    }

    /** Publish one static asset URL. The storefront decides whether to prefetch it after reading the cart. */
    private static function checkoutPrefetchAsset(Plugin $plugin): void
    {
        if (self::privateRequest($plugin) || !AppRouter::instance()?->serves(AppRouter::requestPath())) { return; }
        // Woo's block asset registry is internal to Woo: a page without the hint is still a whole page.
        try {
            $assets = Blocks::container()->get(BlockAssets::class);
            $script = $assets->get_script_data($assets->get_block_asset_build_path('checkout-frontend'));
        } catch (\Throwable) {
            return;
        }
        printf('<meta name="tp-woo-checkout-script" content="%s">' . "\n", esc_url(add_query_arg('ver', $script['version'], $script['src'])));
    }
    /** Only native Woo endpoints may extend checkout's Page route. */
    public static function routePath(Plugin $plugin, string $path): string
    {
        if ($plugin->schema->commerce() === null) { return $path; }
        $checkout = rtrim($plugin->schema->commerce()['checkout'] ?? '/checkout', '/');
        foreach (['order-pay' => 'woocommerce_checkout_pay_endpoint', 'order-received' => 'woocommerce_checkout_order_received_endpoint'] as $var => $option) {
            $slug = (string) get_option($option, $var);
            if ($slug !== '' && preg_match('#^' . preg_quote($checkout, '#') . '/' . preg_quote($slug, '#') . '/([0-9]+)/?$#', rawurldecode($path), $m)) {
                $GLOBALS['wp']->query_vars[$var] = $m[1]; set_query_var($var, $m[1]);
                return $checkout;
            }
        }
        return $path;
    }
    public static function downloadDirectory(string $file): string
    {
        try { $parent = (new \Automattic\WooCommerce\Internal\Utilities\URL($file))->get_parent_url(); }
        catch (\Automattic\WooCommerce\Internal\Utilities\URLException $e) { throw \TypedPress\HttpError::invalidInput($e->getMessage(), '/downloads'); }
        if (!is_string($parent) || mb_strlen(trailingslashit($parent)) > 256) { throw \TypedPress\HttpError::invalidInput('Downloads need a valid directory URL of at most 256 characters', '/downloads'); }
        return $parent;
    }
    public static function approveDownload(string $file): void
    {
        $register = wc_get_container()->get(\Automattic\WooCommerce\Internal\ProductDownloads\ApprovedDirectories\Register::class);
        try {
            $id = $register->add_approved_directory(self::downloadDirectory($file), true); $register->enable_by_id($id);
        } catch (\Automattic\WooCommerce\Internal\Utilities\URLException | \Automattic\WooCommerce\Internal\ProductDownloads\ApprovedDirectories\ApprovedDirectoriesException $e) {
            throw \TypedPress\HttpError::invalidInput($e->getMessage(), '/downloads');
        }
    }
    public static function information(Plugin $plugin): array
    {
        return [
            'currency' => get_woocommerce_currency(), 'precision' => wc_get_price_decimals(), 'country' => WC()->countries->get_base_country(),
            'display' => ['symbol' => get_woocommerce_currency_symbol(), 'position' => get_option('woocommerce_currency_pos'), 'decimalSeparator' => wc_get_price_decimal_separator(), 'thousandSeparator' => wc_get_price_thousand_separator()],
            'units' => ['weight' => get_option('woocommerce_weight_unit'), 'dimension' => get_option('woocommerce_dimension_unit')],
            'sellTo' => array_keys(WC()->countries->get_allowed_countries()), 'productTypes' => ['simple'],
            'checkoutAvailable' => self::checkoutPage($plugin) > 0, 'checkout' => wc_get_checkout_url(), 'cart' => wc_get_cart_url(),
        ];
    }
}
