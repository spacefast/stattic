<?php

declare(strict_types=1);

namespace TypedPress\Commerce;

use TypedPress\ColumnRef;
use TypedPress\Dialect;
use TypedPress\HttpError;
use TypedPress\Schema;
use TypedPress\Source;
use TypedPress\Viewer;
use TypedPress\Wp\AgentScope;
use TypedPress\Wp\Connection;
use TypedPress\Wp\WpTableStore;

/** Select native identities with the shared query engine, then read/write objects through Woo CRUD. */
final class Store implements \TypedPress\Store
{
    private bool $trash = false;
    public function __construct(private readonly Connection $db, private readonly Schema $schema, private readonly WpTableStore $tables) {}

    public function withTrash(callable $fn): mixed
    {
        $previous = $this->trash; $this->trash = true;
        try { return $fn(); } finally { $this->trash = $previous; }
    }

    public function source(string $collection): Source
    {
        $spec = $this->schema->collection($collection);
        $order = $spec['commerce'] === 'order';
        $wpdb = $this->db->wpdb();
        $meta = ['uid' => '_tp_uid', 'sku' => '_sku', 'price' => '_regular_price', 'availability' => '_stock_status', 'virtual' => '_virtual', 'downloadable' => '_downloadable', 'customer' => '_customer_user', 'total' => '_order_total', 'currency' => '_order_currency'];
        $refs = [];
        foreach ($spec['columns'] as $name => $col) {
            $name = (string) $name;
            $core = match ($name) { 'id' => '{a}.ID', 'name' => '{a}.post_title', 'slug' => '{a}.post_name', 'description' => '{a}.post_content', 'shortDescription' => '{a}.post_excerpt', 'status' => $order ? "REPLACE({a}.post_status, 'wc-', '')" : "CASE {a}.post_status WHEN 'publish' THEN 'published' WHEN 'future' THEN 'scheduled' ELSE {a}.post_status END", 'createdAt' => $this->dialect()->isoTimestamp('{a}.post_date_gmt'), 'updatedAt' => $this->dialect()->isoTimestamp('{a}.post_modified_gmt'), default => null };
            if ($core !== null) { $refs[$name] = new ColumnRef($core, '', '', 'scalar'); continue; }
            $authored = !$order && ($col['commerceMeta'] ?? false);
            $stock = !$order && $name === 'stockQuantity';
            if ($authored) {
                $meta[$name] = '_tp_field_' . $name;
            }
            if ($stock) {
                $meta[$name] = '_stock';
            }
            if (isset($meta[$name])) {
                $join = "LEFT JOIN `{$wpdb->postmeta}` {j} ON {j}.post_id = {a}.ID AND {j}.meta_key = '" . $meta[$name] . "'";
                $expr = match ($name) { 'virtual', 'downloadable' => "CASE {j}.meta_value WHEN 'yes' THEN 1 ELSE 0 END", 'availability' => "CASE {j}.meta_value WHEN 'onbackorder' THEN 'outofstock' ELSE {j}.meta_value END", 'customer' => "NULLIF(NULLIF({j}.meta_value, ''), '0')", default => "NULLIF({j}.meta_value, '')" };
                if ($authored) { $expr = '{j}.meta_value'; }
                if ($stock) {
                    $managed = "EXISTS (SELECT 1 FROM `{$wpdb->postmeta}` stock_meta WHERE stock_meta.post_id = {a}.ID AND stock_meta.meta_key = '_manage_stock' AND stock_meta.meta_value = 'yes')";
                    $expr = "CASE WHEN $managed THEN $expr END";
                }
                $compare = match (true) {
                    // An authored date is stored as YYYY-MM-DD, the form a filter sends. A comparison
                    // expression would have Sql::metaParam rewrite the filter to SCF's YYYYMMDD.
                    $authored && $col['type'] === 'date' => '',
                    in_array($name, ['price', 'total'], true), $authored && $col['type'] === 'real' => "CAST($expr AS DECIMAL(30,10))",
                    $stock, $authored && in_array($col['type'], ['integer', 'boolean'], true) => "CAST($expr AS SIGNED)",
                    default => $expr,
                };
                $refs[$name] = new ColumnRef($expr, $join, $compare, ColumnRef::kindFor($col['type']));
            } else { $refs[$name] = new ColumnRef('NULL', '', '', ColumnRef::kindFor($col['type'])); }
        }
        $type = $order ? 'shop_order' : 'product';
        $excluded = $this->trash ? "'auto-draft'" : "'trash', 'auto-draft'";
        return new Source("`{$wpdb->posts}`", "{a}.post_type = '$type' AND {a}.post_status NOT IN ($excluded)", $refs);
    }

    public function select(string $collection, array $q): array
    {
        $spec = $this->schema->collection($collection);
        $rows = $this->db->run($q['sql'], $q['params']);
        if ($spec['commerce'] === 'product' && count($rows) > 1) {
            // wc_get_product() primes one product at a time (posts, terms, meta: three reads each).
            _prime_post_caches(array_map('intval', array_column($rows, 'id')));
        }
        foreach ($rows as &$row) {
            if (!isset($row['id'])) { continue; }
            $object = $spec['commerce'] === 'order' ? wc_get_order((int) $row['id']) : wc_get_product((int) $row['id']);
            if (!$object) { continue; }
            $values = $object instanceof \WC_Order ? self::order($object) : self::product($object);
            if ($spec['commerce'] === 'product' && !isset($spec['url'])) { $values['url'] = null; }
            foreach ($row as $name => $_) {
                $column = $spec['columns'][$name];
                if ($object instanceof \WC_Product && ($column['commerceMeta'] ?? false)) {
                    $key = '_tp_field_' . $name;
                    $values[$name] = $object->meta_exists($key) ? $object->get_meta($key, true, 'edit') : null;
                }
                if ($object instanceof \WC_Product && $name === 'stockQuantity') {
                    $values[$name] = $object->managing_stock() ? $object->get_stock_quantity() : null;
                }
                if (array_key_exists($name, $values)) { $row[$name] = Schema::store($column, $values[$name]); }
            }
        }
        unset($row);
        return $rows;
    }

    private static function product(\WC_Product $p): array
    {
        $sku = $p->get_sku();
        return [
            'id' => $p->get_id(), 'uid' => $p->get_meta('_tp_uid') ?: null, 'name' => $p->get_name(), 'slug' => $p->get_slug(),
            'description' => $p->get_description(), 'shortDescription' => $p->get_short_description(), 'sku' => $sku === '' ? null : $sku,
            'status' => ['publish' => 'published', 'future' => 'scheduled'][$p->get_status()] ?? $p->get_status(),
            'price' => $p->get_regular_price() === '' ? null : $p->get_regular_price(), 'images' => array_values(array_filter([$p->get_image_id(), ...$p->get_gallery_image_ids()])),
            'categories' => $p->get_category_ids(), 'tags' => $p->get_tag_ids(), 'virtual' => $p->is_virtual(), 'downloadable' => $p->is_downloadable(),
            'downloads' => array_values(array_map(static fn (\WC_Product_Download $d): array => ['id' => $d->get_id(), 'name' => $d->get_name(), 'file' => $d->get_file()], $p->get_downloads())),
            'type' => $p->get_type(), 'purchasable' => $p->is_purchasable(), 'availability' => $p->get_stock_status() === 'onbackorder' ? 'outofstock' : $p->get_stock_status(), 'url' => $p->get_permalink(),
        ];
    }

    /** Purchase-time facts; never reconstruct a line from the current product. */
    private static function order(\WC_Order $o): array
    {
        $date = static fn ($d): ?string => $d ? gmdate('Y-m-d\TH:i:s.000\Z', $d->getTimestamp()) : null;
        $items = static fn (string $type): array => array_values(array_map(static function ($item) use ($type): array {
            $data = ['id' => $item->get_id(), 'name' => $item->get_name()];
            if ($type === 'line_item') { return $data + ['product' => $item->get_product_id(), 'variation' => $item->get_variation_id() ?: null, 'quantity' => $item->get_quantity(), 'subtotal' => $item->get_subtotal(), 'subtotalTax' => $item->get_subtotal_tax(), 'total' => $item->get_total(), 'totalTax' => $item->get_total_tax(), 'taxes' => $item->get_taxes()]; }
            $native = array_intersect_key($item->get_data(), array_flip(['method_id', 'instance_id', 'total', 'total_tax', 'taxes', 'rate_id', 'label', 'compound', 'tax_total', 'shipping_tax_total', 'code', 'discount', 'discount_tax']));
            return $data + $native;
        }, $o->get_items($type)));
        return [
            'id' => $o->get_id(), 'status' => $o->get_status(), 'currency' => $o->get_currency(), 'precision' => wc_get_price_decimals(),
            'createdAt' => $date($o->get_date_created()), 'updatedAt' => $date($o->get_date_modified()), 'paidAt' => $date($o->get_date_paid()), 'completedAt' => $date($o->get_date_completed()),
            'customer' => $o->get_customer_id() ?: null, 'paymentMethod' => $o->get_payment_method(), 'paymentTitle' => $o->get_payment_method_title(),
            'total' => $o->get_total(), 'subtotal' => wc_format_decimal($o->get_subtotal(), wc_get_price_decimals()), 'discountTotal' => $o->get_discount_total(), 'shippingTotal' => $o->get_shipping_total(), 'taxTotal' => $o->get_total_tax(),
            'billing' => $o->get_address('billing'), 'shipping' => $o->get_address('shipping'), 'lines' => $items('line_item'), 'shippingLines' => $items('shipping'), 'taxLines' => $items('tax'), 'feeLines' => $items('fee'), 'couponLines' => $items('coupon'),
            'refunds' => array_values(array_map(static fn ($r): array => ['id' => $r->get_id(), 'amount' => $r->get_amount(), 'reason' => $r->get_reason(), 'createdAt' => $date($r->get_date_created())], $o->get_refunds())),
        ];
    }

    public function insert(string $collection, array $values): int|string
    {
        $this->writable($collection);
        $p = new \WC_Product_Simple();
        $spec = $this->schema->collection($collection);
        if (isset($spec['columns']['stockQuantity'])) {
            $p->set_manage_stock(true);
            $p->set_stock_quantity(0);
        }
        if ($spec['comments'] ?? false) {
            $p->set_reviews_allowed(true);
        }
        try { $this->write($p, $values, $spec['columns']); }
        finally { if ($p->get_id() > 0) { $this->db->touchPost($p->get_id()); } }
        if ($p->get_id() <= 0) {
            throw new \RuntimeException('WooCommerce did not create the product');
        }
        return $p->get_id();
    }
    public function update(string $collection, int|string $pk, array $values): void
    {
        $this->writable($collection);
        $p = wc_get_product((int) $pk);
        if (!$p || !$p->is_type('simple')) { throw HttpError::invalidInput('Only simple products can be edited in v1', '/type'); }
        $this->db->touchPost((int) $pk);
        $this->write($p, $values, $this->schema->collection($collection)['columns']);
    }
    private function writable(string $collection): void
    {
        if ($this->schema->collection($collection)['commerce'] !== 'product') { throw new HttpError(405, 'method_not_allowed', 'Orders are read-only'); }
    }
    private function write(\WC_Product $p, array $values, array $columns): void
    {
        if (($values['status'] ?? null) === 'scheduled' && $p->get_status('edit') !== 'future') {
            throw HttpError::invalidInput('Set a future publication date in WooCommerce to schedule this product', '/status');
        }
        if (isset($values['uid'])) {
            // A trashed product still owns its identity, as in WpPostStore::checkUnique().
            $wpdb = $this->db->wpdb();
            $hit = $this->db->run(
                "SELECT m.post_id FROM `{$wpdb->postmeta}` m JOIN `{$wpdb->posts}` p ON p.ID = m.post_id WHERE m.meta_key = '_tp_uid' AND m.meta_value = ? AND p.post_type = 'product' AND p.post_status NOT IN ('auto-draft', 'inherit') AND p.ID <> ? LIMIT 1 FOR UPDATE",
                [(string) $values['uid'], $p->get_id()],
            );
            if ($hit !== []) { throw new HttpError(409, 'unique_violation', 'A row with this uid already exists', '/uid'); }
        }
        $setters = ['name' => 'name', 'slug' => 'slug', 'description' => 'description', 'shortDescription' => 'short_description', 'sku' => 'sku', 'price' => 'regular_price', 'virtual' => 'virtual', 'downloadable' => 'downloadable', 'categories' => 'category_ids', 'tags' => 'tag_ids'];
        try {
            $downloads = null;
            foreach ($values as $key => $value) {
                if (isset($setters[$key])) { $p->{'set_' . $setters[$key]}(in_array($key, ['virtual', 'downloadable'], true) ? (bool) $value : ($value ?? '')); }
                elseif ($columns[$key]['commerceMeta'] ?? false) {
                    $metaKey = '_tp_field_' . $key;
                    if ($value === null) { $p->delete_meta_data($metaKey); }
                    else { $p->update_meta_data($metaKey, $value); }
                }
                elseif ($key === 'uid') { $p->update_meta_data('_tp_uid', $value); }
                elseif ($key === 'status') { $p->set_status(['published' => 'publish', 'scheduled' => 'future'][$value] ?? $value); }
                elseif ($key === 'images') { $ids = json_decode((string) $value, true) ?? []; $p->set_image_id($ids[0] ?? 0); $p->set_gallery_image_ids(array_slice($ids, 1)); }
                elseif ($key === 'downloads') {
                    $downloads = []; $seen = [];
                    foreach (json_decode((string) $value, true) ?? [] as $entry) {
                        if (isset($seen[$entry['id']]) || $entry['id'] === '') { throw HttpError::invalidInput('Download ids must be nonempty and unique', '/downloads'); }
                        $seen[$entry['id']] = true;
                        $d = new \WC_Product_Download(); $d->set_id($entry['id']); $d->set_name($entry['name']); $d->set_file($entry['file']);
                        Module::downloadDirectory($entry['file']); $downloads[] = $d;
                    }
                }
            }
            if ($downloads !== null) {
                // Woo validates approved directories in set_downloads, so approval must precede
                // that setter. All input is checked first; the shared write transaction owns both.
                foreach ($downloads as $download) { Module::approveDownload($download->get_file()); }
                $p->set_downloads($downloads);
            }
            if (!$p->get_id() && !isset($values['status'])) { $p->set_status('publish'); }
            if ($p->get_status('edit') === 'future') {
                // Woo may have loaded a cached product before cron published it. Hold the current row
                // through save so the edit cannot restore a schedule that has already fired.
                $wpdb = $this->db->wpdb();
                $row = $this->db->run("SELECT post_status FROM `{$wpdb->posts}` WHERE ID = ? FOR UPDATE", [$p->get_id()]);
                if (($row[0]['post_status'] ?? null) !== 'future') {
                    throw new HttpError(409, 'conflict', 'The product publication status changed. Reload it before editing.', '/status');
                }
                // Core changes future to publish in the final minute. Leave publication to its scheduled event.
                $preserveSchedule = static function (array $data, array $post) use ($p): array {
                    if ((int) ($post['ID'] ?? 0) === $p->get_id() && $post['post_status'] === 'future' && $data['post_status'] === 'publish') {
                        $data['post_status'] = 'future';
                    }
                    return $data;
                };
                add_filter('wp_insert_post_data', $preserveSchedule, PHP_INT_MAX, 2);
                try { $this->db->core(static fn () => $p->save()); }
                finally { remove_filter('wp_insert_post_data', $preserveSchedule, PHP_INT_MAX); }
            } else {
                $this->db->core(static fn () => $p->save());
            }
        } catch (\WC_Data_Exception $e) { throw new HttpError(422, $e->getErrorCode(), $e->getMessage()); }
    }
    public function delete(string $collection, int|string $pk): void
    {
        $this->writable($collection);
        if (defined('EMPTY_TRASH_DAYS') && !EMPTY_TRASH_DAYS) {
            throw new HttpError(409, 'trash_disabled', 'Product deletion requires WordPress trash to be enabled.');
        }
        $this->db->touchPost((int) $pk);
        $p = wc_get_product((int) $pk);
        if ($p) { $this->db->core(static fn () => $p->delete(false)); }
        // Woo does not report a native trash veto; verify persistence before committing link changes.
        $status = get_post_status((int) $pk);
        if ($status !== false && $status !== 'trash') { throw new \RuntimeException("Could not delete product $pk"); }
    }
    public function readGuards(string $collection, Viewer $viewer, bool $forSelect = false): array
    {
        $spec = $this->schema->collection($collection);
        if ($spec['commerce'] === 'order') { if (!AgentScope::may('personal')) { throw AgentScope::refusal('personal'); } return []; }
        if (($viewer->user['role'] ?? null) === 'admin') { return []; }
        $wpdb = $this->db->wpdb();
        return [['status' => 'published'], new \TypedPress\SqlPredicate("{a}.post_password = '' AND NOT EXISTS (SELECT 1 FROM `{$wpdb->term_relationships}` cv JOIN `{$wpdb->term_taxonomy}` ct ON ct.term_taxonomy_id = cv.term_taxonomy_id JOIN `{$wpdb->terms}` cn ON cn.term_id = ct.term_id WHERE cv.object_id = {a}.ID AND ct.taxonomy = 'product_visibility' AND cn.slug = 'exclude-from-catalog')", [])];
    }
    public function showsUnique(string $collection, string $column, Viewer $viewer): bool { return ($viewer->user['role'] ?? null) === 'admin'; }
    public function dialect(): Dialect { return $this->tables->dialect(); }
    public function transaction(callable $fn): mixed { return $this->db->transaction($fn); }
    public function afterCommit(callable $fn): void { $this->db->afterCommit($fn); }
    public function applySystemStatements(array $statements): void { $this->tables->applySystemStatements($statements); }
    public function globalRead(string $name): ?array { return $this->tables->globalRead($name); }
    public function globalWrite(string $name, array $values): void { $this->tables->globalWrite($name, $values); }
    public function revisions(string $collection, int|string $pk): array { return []; }
    public function revisionWrite(string $collection, int|string $pk, array $encodedRow, ?int $userId): void {}
    public function revision(string $collection, int|string $pk, int $revisionId): ?array { return null; }
    public function promoteDue(string $collection, string $nowIso): array { return []; }
    public function mapError(\Throwable $e): \Throwable { return $this->tables->mapError($e); }
}
