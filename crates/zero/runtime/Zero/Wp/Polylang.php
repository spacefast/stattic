<?php

declare(strict_types=1);

namespace Zero\Wp;

use Zero\ColumnRef;
use Zero\HttpError;
use Zero\Locale\GroupSync;
use Zero\Schema;

/**
 * The Polylang adapter for localized post-backed collections (§7.3). Polylang owns each post's
 * language (`language` taxonomy) and its translation group (`post_translations` taxonomy); Zero
 * writes them through Polylang's public API (`pll_set_post_language`, `pll_save_post_translations`)
 * and reads them in SQL as term subqueries, so `locale`, `translations` and the locale rule compile
 * like any other column. Polylang languages are created from `locales.all` on the first request after
 * activation or a change to them.
 */
final class Polylang
{
    private static bool $busy = false;

    public static function boot(): void
    {
        add_filter('pll_get_post_types', static function (mixed $types, mixed $hide): array {
            $types = (array) $types;
            foreach (self::postTypes() as $type) {
                if ($hide) {
                    unset($types[$type]); // Zero decides; keep it out of Polylang's settings screen
                } else {
                    $types[$type] = $type;
                }
            }

            return $types;
        }, 10, 2);
        // Zero syncs its own columns in the pipeline (hooks and revisions per row). Polylang's live
        // meta sync would update siblings first, behind the pipeline's back, so it never touches
        // Zero's fields; its one-time copy into a new translation (wp-admin "+") stays.
        add_filter('pll_copy_post_metas', static function (mixed $metas, mixed $sync, mixed $from = 0): array {
            $metas = (array) $metas;
            if (!$sync || !in_array(get_post_type((int) $from), self::postTypes(), true)) {
                return $metas;
            }

            return array_values(array_filter($metas, static fn ($key) => !str_starts_with((string) $key, 'zero_') && !str_starts_with((string) $key, '_zero_')));
        }, 100, 3);
        // A group linked outside Zero's API (wp-admin "+", pll_save_post_translations): once every
        // member is linked, the group adopts its synced values (see adopt()).
        add_action('set_object_terms', static function (int $objectId, mixed $terms, mixed $ttIds, string $taxonomy): void {
            if ($taxonomy === 'post_translations' && !self::$busy && !self::zeroWriting() && ($ttIds = (array) $ttIds) !== []) {
                self::adopt($objectId, (int) reset($ttIds));
            }
        }, 10, 4);
        // Languages follow `locales` on the first request Zero can serve (REST, cron, CLI, admin) after
        // activation or a change. Activation alone can't: with Polylang activated in the same request,
        // its model isn't loaded yet. (On a site with no languages Polylang builds no front-end
        // context, so a theme page doesn't provision; Zero serves nothing there.)
        // Concurrent first requests queue on one lock and recheck the marker, so a language is added once.
        add_action('init', static function (): void {
            $plugin = Runtime::plugin();
            $locales = $plugin?->schema->locales();
            // Not while storage lags the build (the schema's locales may not be the stored ones yet).
            // The marker only says provisioning is done; assign() checks each write's language itself.
            if ($plugin === null || $locales === null || !function_exists('PLL') || $plugin->unavailable() !== null || self::provisioned($locales)) {
                return;
            }
            // Until every language exists a localized write would store no language, so a failure
            // (lock timeout, Polylang refusing) refuses Zero like lagging storage; the next request retries.
            $problem = Migrator::upgrade($plugin->connection(), 'zero_polylang_languages', static fn (): bool => self::provisioned($locales, true), self::ensureLanguages(...));
            // Whoever provisioned, this request may hold the language list it loaded before: reload
            // it, and refuse if a configured language is still missing.
            self::reload();
            $missing = array_diff(array_map(self::slug(...), $locales['all']), array_map('strval', (array) pll_languages_list(['fields' => 'slug'])));
            if ($problem === null && $missing !== []) {
                $problem = 'Polylang is missing the languages ' . implode(', ', $missing);
            }
            if ($problem !== null) {
                $plugin->refuse($problem);
            }
        }, 2);
    }

    /** locale => post id of $postId's group (self included), in configured locale spelling. */
    public static function group(int $postId, array $locales): array
    {
        $bySlug = [];
        foreach ($locales as $l) {
            $bySlug[self::slug($l)] = $l;
        }
        $out = [];
        $members = (array) pll_get_post_translations($postId);
        if ($members === [] && ($own = pll_get_post_language($postId))) {
            $members = [$own => $postId];
        }
        foreach ($members as $slug => $id) {
            $out[$bySlug[$slug] ?? $slug] = (int) $id;
        }

        return $out;
    }

    /** Polylang language slugs are lowercase; Zero locales may carry a region in caps (pt-BR). */
    public static function slug(string $locale): string
    {
        return strtolower($locale);
    }

    /**
     * Source refs on wp_posts ($prefix: the WP table prefix): `locale` (the language term slug mapped back to the configured
     * locale), `translationGroup` (the post_translations term), `translations` (computed by Sql).
     */
    public static function ref(string $col, string $prefix, array $locales): ColumnRef
    {
        $term = static fn (string $taxonomy, string $field): string => "(SELECT {a}_pll_t.$field FROM `{$prefix}term_relationships` {a}_pll_r"
            . " JOIN `{$prefix}term_taxonomy` {a}_pll_t ON {a}_pll_t.term_taxonomy_id = {a}_pll_r.term_taxonomy_id"
            . ($field === 'slug' ? " JOIN `{$prefix}terms` {a}_pll_n ON {a}_pll_n.term_id = {a}_pll_t.term_id" : '')
            . " WHERE {a}_pll_r.object_id = {a}.ID AND {a}_pll_t.taxonomy = '$taxonomy' LIMIT 1)";
        if ($col === 'locale') {
            $slug = str_replace('{a}_pll_t.slug', '{a}_pll_n.slug', $term('language', 'slug'));
            $case = 'CASE ' . $slug;
            foreach ($locales as $l) {
                $case .= " WHEN '" . addslashes(self::slug($l)) . "' THEN '" . addslashes($l) . "'";
            }

            return new ColumnRef($case . ' END', '', '', 'scalar');
        }
        if ($col === 'translationGroup') {
            return new ColumnRef($term('post_translations', 'term_taxonomy_id'), '', '', 'scalar');
        }

        return new ColumnRef('NULL', '', '', 'json');
    }

    /**
     * After WpPostStore::insert: set the new post's language and, for a `translationOf` create, join
     * $group (locale => id, from the source row). Membership changes are serialized per group by
     * locking its posts, and the group is re-read under the lock, so a locale is added at most once
     * (409 at /locale). On rollback the members' and group terms' caches are dropped.
     *
     * @param array<string, int> $group
     */
    public static function assign(Connection $db, int $id, string $locale, array $group): void
    {
        // Polylang resolves the slug through the language list this request loaded; one loaded before
        // another request provisioned the language wouldn't know it. Reload once, then refuse rather
        // than store a post without its language.
        if (!PLL()->model->get_language(self::slug($locale))) {
            self::reload();
        }
        if (!PLL()->model->get_language(self::slug($locale))) {
            throw new HttpError(503, 'unavailable', "Polylang has no $locale language yet; try again shortly");
        }
        self::busy(static fn () => pll_set_post_language($id, self::slug($locale)));
        if ($group === []) {
            return;
        }
        $current = self::locked($db, array_map('intval', array_values($group)));
        if (isset($current[self::slug($locale)])) {
            throw new HttpError(409, 'unique_violation', "This translation group already has a $locale row", '/locale');
        }
        $current[self::slug($locale)] = $id;
        $terms = [];
        foreach ($current as $member) {
            $db->touchPost($member);
            clean_object_term_cache($member, (string) get_post_type($member));
            foreach (wp_get_object_terms($member, 'post_translations', ['fields' => 'ids']) as $term) {
                $terms[(int) $term] = true;
            }
        }
        // The full, freshly locked group: Polylang merges it into the group term and links every member.
        self::busy(static fn () => pll_save_post_translations($current));
        foreach (wp_get_object_terms($id, 'post_translations', ['fields' => 'ids']) as $term) {
            $terms[(int) $term] = true;
        }
        $db->onRollback(static function () use ($terms): void {
            foreach (array_keys($terms) as $term) {
                clean_term_cache($term, 'post_translations');
            }
        });
    }

    /**
     * language slug => post id of the group $ids belong to, read with locking reads of the core
     * taxonomy tables (latest committed state, then held), so concurrent joins serialize per group.
     *
     * @param list<int> $ids
     * @return array<string, int>
     */
    public static function locked(Connection $db, array $ids): array
    {
        $p = $db->prefix();
        $in = static fn (array $v) => implode(', ', array_fill(0, count($v), '?'));
        $db->run("SELECT ID FROM `{$p}posts` WHERE ID IN ({$in($ids)}) ORDER BY ID FOR UPDATE", $ids);
        $groups = array_column($db->run("SELECT DISTINCT tr.term_taxonomy_id AS g FROM `{$p}term_relationships` tr JOIN `{$p}term_taxonomy` tt ON tt.term_taxonomy_id = tr.term_taxonomy_id"
            . " WHERE tr.object_id IN ({$in($ids)}) AND tt.taxonomy = 'post_translations' FOR UPDATE", $ids), 'g');
        if ($groups !== []) {
            $members = array_map('intval', array_column($db->run("SELECT object_id AS id FROM `{$p}term_relationships` WHERE term_taxonomy_id IN ({$in($groups)}) FOR UPDATE", $groups), 'id'));
            $ids = array_values(array_unique([...$ids, ...$members]));
        }
        // Live members only, as every read sees them: a trashed translation keeps Polylang's group
        // relationship but is no longer a row (a join in its locale drops it from the group).
        $ids = array_map('intval', array_column($db->run("SELECT ID FROM `{$p}posts` WHERE ID IN ({$in($ids)}) AND post_status NOT IN ('trash', 'auto-draft', 'inherit') FOR UPDATE", $ids), 'ID'));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($db->run("SELECT tr.object_id AS id, t.slug FROM `{$p}term_relationships` tr JOIN `{$p}term_taxonomy` tt ON tt.term_taxonomy_id = tr.term_taxonomy_id"
            . " JOIN `{$p}terms` t ON t.term_id = tt.term_id WHERE tr.object_id IN ({$in($ids)}) AND tt.taxonomy = 'language' ORDER BY tr.object_id FOR UPDATE", $ids) as $r) {
            $out[(string) $r['slug']] ??= (int) $r['id'];
        }

        return $out;
    }

    /** Create every configured locale missing from Polylang; the default locale becomes Polylang's default. */
    public static function ensureLanguages(): void
    {
        $schema = Runtime::plugin()?->schema;
        $locales = $schema?->locales();
        if ($locales === null || !function_exists('PLL') || !function_exists('pll_languages_list')) {
            return;
        }
        self::reload(); // another request may have added some since this one loaded them
        $have = array_map('strval', (array) pll_languages_list(['fields' => 'slug']));
        $ordered = array_values(array_unique([$locales['default'], ...$locales['all']]));
        foreach ($ordered as $i => $locale) {
            if (in_array(self::slug($locale), $have, true)) {
                continue;
            }
            [$lang, $region] = array_pad(explode('-', $locale, 2), 2, null);
            $wpLocale = $region !== null ? $lang . '_' . strtoupper($region) : ($lang === 'en' ? 'en_US' : $lang . '_' . strtoupper($lang));
            $added = PLL()->model->add_language(['locale' => $wpLocale, 'slug' => self::slug($locale), 'name' => $locale, 'term_group' => $i]);
            if ($added instanceof \WP_Error) {
                throw new \RuntimeException("Polylang could not add $locale: " . $added->get_error_message());
            }
        }
        // Polylang's default follows locales.default, also on sites whose languages already existed.
        $default = PLL()->model->get_language(self::slug($locales['default']));
        if ($default instanceof \PLL_Language && pll_default_language() !== $default->slug) {
            PLL()->model->update_default_lang($default->slug);
        }
        // Posts of localized post types that predate localization (no language) become default-locale rows.
        $types = self::postTypes();
        if ($types !== [] && $default instanceof \PLL_Language) {
            foreach (get_posts(['post_type' => $types, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'lang' => '', 'suppress_filters' => true]) as $id) {
                if (!pll_get_post_language((int) $id)) {
                    pll_set_post_language((int) $id, $default->slug);
                }
            }
        }
        update_option('zero_polylang_languages', self::state($locales), false);
    }

    /** Polylang's language list as committed now: its own cache, and WordPress's term-query cache it reads through. */
    private static function reload(): void
    {
        wp_cache_set_terms_last_changed();
        PLL()->model->clean_languages_cache();
    }

    private static function provisioned(array $locales, bool $fresh = false): bool
    {
        if ($fresh) {
            // Under the lock: the committed value, past the option caches (an absent option is
            // cached as a negative entry in `notoptions`, which deleting the key alone keeps).
            global $wpdb;

            return $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'zero_polylang_languages')) === self::state($locales);
        }

        return get_option('zero_polylang_languages') === self::state($locales);
    }

    /** Provisioning marker: the default plus every locale, so changing either re-provisions. */
    private static function state(array $locales): string
    {
        return $locales['default'] . ':' . implode(',', $locales['all']);
    }

    /** @return list<string> post types of localized post-backed collections */
    private static function postTypes(): array
    {
        $schema = Runtime::plugin()?->schema;
        if ($schema?->locales() === null) {
            return [];
        }
        $out = [];
        foreach ($schema->collections() as $spec) {
            if (($spec['localized'] ?? false) && ($spec['editor'] ?? true) && isset($spec['wp']['postType'])) {
                $out[] = $spec['wp']['postType'];
            }
        }

        return $out;
    }

    /**
     * A group linked outside Zero's API (Polylang's "+", pll_save_post_translations). Like an
     * editor save (design-wp-parity §1.7), the link is already written when Zero learns of it, and
     * Zero never rewrites what WordPress or Polylang stored. Once every member of the group
     * (pll_get_post_translations) is linked, the members adopt the oldest member's synced values in
     * one transaction, checked as a whole: if a hook makes them diverge, that adoption rolls back
     * and the error surfaces to whoever made the link, which itself stands.
     */
    private static function adopt(int $postId, int $groupTt): void
    {
        $plugin = Runtime::plugin();
        $type = get_post_type($postId);
        if ($plugin === null || !in_array($type, self::postTypes(), true)) {
            return;
        }
        $members = array_map('intval', array_values((array) pll_get_post_translations($postId)));
        if (count($members) < 2) {
            return;
        }
        foreach ($members as $member) {
            if (!in_array($groupTt, array_map('intval', wp_get_object_terms($member, 'post_translations', ['fields' => 'tt_ids'])), true)) {
                return; // Polylang is still linking this group; adopt once the last member is linked
            }
        }
        foreach ($plugin->schema->collections() as $name => $spec) {
            if (($spec['wp']['postType'] ?? null) !== $type) {
                continue;
            }
            $db = $plugin->db();
            $pk = $spec['primaryKey'];
            // Hooks see the user who made the link, as on editor saves; revisions are theirs.
            $previous = $db->actor();
            $db->setActor(WpAuth::current());
            try {
                $db->transaction(static function () use ($db, $name, $pk, $members): void {
                    $rows = $db->findMany((string) $name, ['where' => [$pk => ['in' => $members]], 'locale' => '*']);
                    if (count($rows) >= 2) {
                        usort($rows, static fn ($x, $y) => $x[$pk] <=> $y[$pk]);
                        GroupSync::adoptAll((string) $name, $rows);
                    }
                });
            } finally {
                $db->setActor($previous);
            }

            return;
        }
    }

    private static function zeroWriting(): bool
    {
        return Runtime::plugin()?->connection()->busy() ?? false;
    }

    private static function busy(callable $fn): mixed
    {
        [$was, self::$busy] = [self::$busy, true];
        try {
            return $fn();
        } finally {
            self::$busy = $was;
        }
    }
}
