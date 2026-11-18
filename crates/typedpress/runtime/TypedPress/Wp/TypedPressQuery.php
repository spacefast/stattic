<?php

declare(strict_types=1);

namespace TypedPress\Wp;

use TypedPress\App;
use TypedPress\Js;
use TypedPress\Viewer;

/**
 * TypedPress's query arguments on a core list route (spec §3a): `where`, `orderBy` and `with`, in the same
 * forms as the facade's query string. They run through TypedPress's read pipeline as the viewer, so the
 * read rule, draft visibility and field masks decide exactly as they do for `posts.list()`: a filter
 * or sort on a field hidden from the viewer is the same 403, a relation holds only rows the viewer
 * may read. Core keeps its own pagination, `_fields`, `_embed` and the response shape.
 */
final class TypedPressQuery
{
    /**
     * The ids `where`/`orderBy`/`search` select, in order, or null when the request has none of them.
     * $searched is set when TypedPress's search ran: the caller then drops core's own search from the
     * query ({@see coreArgs()}). The request itself is left alone, so core's `Link` headers keep it.
     *
     * @return ?list<int>
     */
    public static function keys(string $collection, \WP_REST_Request $request, Viewer $viewer, bool $search = true, ?bool &$searched = null): ?array
    {
        $searched = false;
        $given = self::given($request, ['where', 'orderBy']);
        $locale = self::locale($collection, $request);
        $db = Plugin::instance()?->db() ?? throw new \LogicException('No TypedPress app');
        $search = $search ? self::search($collection, $request) : null;
        if ($given === [] && $search === null && $locale === null) {
            return null;
        }
        $opts = App::listOptions($given);
        unset($opts['limit']);
        if ($search !== null) {
            $opts += $search;
            $searched = true;
        }
        $pk = $db->schema()->collection($collection)['primaryKey'];
        $rows = $db->findMany($collection, $opts + ['columns' => [$pk => true]] + ($locale ?? self::allLocales($collection)), $viewer);

        return array_map(static fn (array $r): int => (int) $r[$pk], $rows);
    }

    /**
     * Core's WP_Query args once TypedPress's search selected the rows: TypedPress's search replaces core's (title,
     * content and excerpt only), and `orderby=relevance` needs core's own term, so it becomes core's
     * date order over the rows TypedPress matched.
     */
    public static function coreArgs(array $args): array
    {
        unset($args['s'], $args['search_columns'], $args['exact'], $args['sentence']);
        if (($args['orderby'] ?? null) === 'relevance') {
            $args['orderby'] = 'date';
        }

        return $args;
    }

    /**
     * `with`: each item of a core response gets its relations under `with`, as First rows. A refusal
     * (an unknown relation, a link through a hidden field) answers instead of the items.
     */
    public static function attach(string $collection, \WP_REST_Request $request, Viewer $viewer, mixed $response): mixed
    {
        $given = self::given($request, ['with']);
        if ($given === [] || !$response instanceof \WP_REST_Response || !is_array($data = $response->get_data())) {
            return $response;
        }
        $single = !array_is_list($data);
        $items = $single ? [$data] : $data;
        $ids = array_values(array_filter(array_map(static fn ($i) => is_array($i) ? (int) ($i['id'] ?? 0) : 0, $items)));
        $db = Plugin::instance()?->db() ?? throw new \LogicException('No TypedPress app');
        $pk = $db->schema()->collection($collection)['primaryKey'];
        try {
            $with = App::listOptions($given)['with'];
            // A media field in `with` is a column (populated in place); relations ride along anyway.
            $columns = [$pk => true] + array_fill_keys(array_keys(array_intersect_key($with, $db->schema()->collection($collection)['columns'])), true);
            // The parents are the items core answered (any locale); a `many` relation follows the
            // requested locale, as the pipeline resolves it per parent (Locale\Read).
            $locale = self::locale($collection, $request);
            $rows = $db->findMany($collection, ['where' => [$pk => ['in' => $ids]], 'with' => $with, 'columns' => $columns]
                + ($locale !== null && ($locale['locale'] ?? null) !== '*' && \TypedPress\Locale\Module::localized($collection) ? ['locale' => '*'] : self::allLocales($collection)), $viewer);
        } catch (\TypedPress\HttpError $e) {
            return CoreAccess::error($e);
        }
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row[$pk]] = PostMeta::values($collection, $row, array_map('strval', array_keys($with)));
        }
        foreach ($items as &$item) {
            if (is_array($item) && isset($byId[(int) ($item['id'] ?? 0)])) {
                $item['with'] = (object) $byId[(int) $item['id']];
            }
        }
        unset($item);
        $response->set_data($single ? $items[0] : $items);

        return $response;
    }

    /**
     * `search`: every word in a searchable field the viewer may read (spec §2.6), through the search
     * index ({@see \TypedPress\Search\Index}). `search_columns` narrows it to core's title, content and
     * excerpt columns, each meaning the kind's own title or content field; on the users route, `name`
     * is the member's name (`id`, `username`, `slug` and `email` aren't searchable fields).
     *
     * @return ?array{search: string, searchFields?: list<string>}
     */
    private static function search(string $collection, \WP_REST_Request $request): ?array
    {
        $term = $request['search'];
        if (!is_string($term) || $term === '') {
            return null;
        }
        $spec = Plugin::instance()?->schema->collection($collection) ?? throw new \LogicException('No TypedPress app');
        $columns = (array) ($request['search_columns'] ?? []);
        if ($columns === []) {
            return ['search' => $term];
        }
        $core = $collection === (Plugin::instance()?->schema->auth()['collection'] ?? null)
            ? ['name' => 'name']
            : ['post_title' => $spec['title'] ?? null, 'post_content' => $spec['content'] ?? null, 'post_excerpt' => $spec['excerpt'] ?? $spec['content'] ?? null];

        return ['search' => $term, 'searchFields' => array_values(array_unique(array_filter(array_map(static fn ($c) => $core[$c] ?? null, $columns))))];
    }

    /** @return array<string, mixed> the named query params the request supplied */
    private static function given(\WP_REST_Request $request, array $names): array
    {
        $query = $request->get_query_params();

        return array_intersect_key($query, array_flip($names));
    }

    /**
     * TypedPress's `locale` argument (`fr`, or `*`): the rows of that locale, else each group's fallback, as
     * TypedPress's read pipeline picks them. Without it a core list holds every language (Polylang's `lang`
     * narrows it). An item read takes it too: the group member in that locale.
     */
    public static function locale(string $collection, \WP_REST_Request $request): ?array
    {
        $locale = $request->get_query_params()['locale'] ?? null;
        if ($locale === null && Facade::dispatching() && \TypedPress\Locale\Module::localized($collection)) {
            return []; // TypedPress's own default for the API: the default locale's rows, with fallback
        }

        return $locale === null ? null : ['locale' => $locale];
    }

    /** A core list holds every language (Polylang's `lang` narrows it), not only the default locale. */
    private static function allLocales(string $collection): array
    {
        return \TypedPress\Locale\Module::localized($collection) ? ['locale' => '*'] : [];
    }
}
