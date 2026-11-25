<?php

declare(strict_types=1);

namespace SpacePress\Wp;

use SpacePress\HttpError;
use SpacePress\Search\Index;
use SpacePress\Viewer;

/**
 * Core's search, `GET /wp/v2/search` (the block editor's link picker, the navigation block), with
 * the items of SpacePress's kinds in it.
 *
 * Core lists only public post types (`WP_REST_Post_Search_Handler`), and SpacePress's are not public: an
 * item's visibility is its kind's read rule, not the post type's. This handler takes core's place
 * as the handler of `type=post` and answers each part from its owner:
 *
 * - the post types core searches (posts, pages, other plugins' public types), by core's own handler
 *   (or whichever handler had `post` before), unchanged;
 * - each kind with a `url` (its items have public addresses to link to), by SpacePress's read pipeline as
 *   the current reader, with `search` on SpacePress's search index ({@see Index}). The read rule, a filter
 *   it returns, the store's guards and field access decide what is found, exactly as for
 *   `posts.list({ search })`: a kind the reader may not read is left out without saying it exists,
 *   a row the rule filters out is not found or counted, and a word only in a field hidden from the
 *   reader matches nothing. Only published items are listed, as core lists only published posts.
 *
 * SpacePress's kinds are subtypes of `post`, not a type of their own, because the editor asks for
 * `type=post` only (core-data's fetchLinkSuggestions) and core keys handlers by type, one per type
 * (WP_REST_Search_Controller::__construct), so another type would never be asked. Core's results
 * come first, then each kind's in the kind's own order; a page is a slice of that one list, and
 * `X-WP-Total` counts both.
 */
final class SearchHandler extends \WP_REST_Search_Handler
{
    /** @var array<string, string> post type => kind, for the kinds this handler searches */
    private array $kinds = [];
    /** @var array<int, bool> First items of the last search => whether the reader may read the title */
    private array $found = [];
    private \WP_REST_Post_Search_Handler $posts;

    public static function register(Plugin $plugin): void
    {
        // Last, so it wraps whichever handler of `post` the site has (core's, or a search plugin's).
        add_filter('wp_rest_search_handlers', static function (mixed $handlers) use ($plugin): mixed {
            if (!is_array($handlers)) {
                return $handlers;
            }
            $at = null;
            foreach ($handlers as $i => $handler) {
                if ($handler instanceof \WP_REST_Search_Handler && $handler->get_type() === 'post') {
                    $at = $i; // the controller keeps the last handler of a type
                }
            }
            $self = new self($plugin, $at === null ? null : $handlers[$at]);
            if ($self->kinds === []) {
                return $handlers;
            }
            $at === null ? $handlers[] = $self : $handlers[$at] = $self;

            return $handlers;
        }, PHP_INT_MAX);
    }

    private function __construct(private readonly Plugin $plugin, private readonly ?\WP_REST_Search_Handler $core)
    {
        $this->type = 'post';
        $this->posts = new \WP_REST_Post_Search_Handler();
        // A backend-only site (`frontend: false`) has no public pages to link to.
        if ($plugin->schema->frontend() !== false) {
            foreach ($plugin->schema->collections() as $name => $spec) {
                // As the site-wide search: a kind with a searchable field and an API list.
                if (is_string($spec['url'] ?? null) && isset($spec['wp']['postType']) && Index::fields($spec) !== [] && ($spec['api']['list'] ?? true)) {
                    $this->kinds[(string) $spec['wp']['postType']] = (string) $name;
                }
            }
        }
        $this->subtypes = array_values(array_unique([...($core?->get_subtypes() ?? []), ...array_keys($this->kinds)]));
    }

    public function search_items(\WP_REST_Request $request)
    {
        $this->found = [];
        $asked = (array) $request[\WP_REST_Search_Controller::PROP_SUBTYPE];
        $any = in_array(\WP_REST_Search_Controller::TYPE_ANY, $asked, true);
        $page = max(1, (int) $request['page']);
        $perPage = max(1, (int) $request['per_page']);

        $ids = [];
        $total = 0;
        // `any` always reaches the previous handler: one may declare no subtypes and still answer it.
        $coreTypes = $this->core === null ? [] : array_values(array_intersect($asked, $this->core->get_subtypes()));
        if ($this->core !== null && ($any || $coreTypes !== [])) {
            $sub = clone $request;
            $sub->set_param(\WP_REST_Search_Controller::PROP_SUBTYPE, $any ? [\WP_REST_Search_Controller::TYPE_ANY] : $coreTypes);
            $result = $this->core->search_items($sub);
            $ids = array_values((array) ($result[self::RESULT_IDS] ?? []));
            $total = (int) ($result[self::RESULT_TOTAL] ?? 0);
            if ($ids === [] && $page > 1) {
                // A page past core's results comes back without its total (WP_Query skips the count
                // when a page is empty): ask for one row to learn how many there are.
                $sub->set_param('page', 1);
                $sub->set_param('per_page', 1);
                $total = (int) ($this->core->search_items($sub)[self::RESULT_TOTAL] ?? 0);
            }
        }

        // The rest of the page comes from SpacePress's kinds, after core's results.
        $skip = max(0, ($page - 1) * $perPage - $total);
        $want = $perPage - count($ids);
        $viewer = CoreAccess::viewer();
        $kinds = $any ? $this->kinds : array_intersect_key($this->kinds, array_flip($asked));
        if ($viewer === null || $kinds === [] || $this->plugin->unavailable() !== null) {
            return [self::RESULT_IDS => $ids, self::RESULT_TOTAL => $total];
        }
        $db = $this->plugin->db();
        foreach ($kinds as $kind) {
            $spec = $this->plugin->schema->collection($kind);
            if (!KindUrls::patternReadable($spec, $viewer)) {
                continue; // its addresses would carry a value the reader may not read
            }
            $pk = $spec['primaryKey'];
            $where = self::where($spec, $request);
            $extra = (is_string($request['search']) && $request['search'] !== '' ? ['search' => $request['search']] : [])
                + (\SpacePress\Locale\Module::localized($kind) ? ['locale' => '*'] : []);
            try {
                $n = $db->count($kind, $where, $viewer, $extra);
                $total += $n;
                if ($want <= 0 || $skip >= $n) {
                    $skip = max(0, $skip - $n);
                    continue;
                }
                $title = $spec['title'] ?? null;
                $titled = is_string($title) && !\SpacePress\FieldAccess::mayHide($spec['columns'][$title], $viewer);
                $rows = $db->findMany($kind, ['where' => $where, 'columns' => [$pk => true] + ($titled ? [$title => true] : []),
                    'limit' => $want, 'offset' => $skip] + $extra, $viewer);
            } catch (HttpError $e) {
                if ($e->status >= 500) {
                    throw $e;
                }
                // The reader may not read this kind (401/403), or the text is longer than SpacePress
                // searches (400): the kind is left out, without saying which.
                continue;
            }
            foreach ($rows as $row) {
                $ids[] = (int) $row[$pk];
                // A title the field rules strip from this row is not shown either.
                $this->found[(int) $row[$pk]] = $titled && array_key_exists($title, $row);
            }
            $want -= count($rows);
            $skip = 0;
        }

        return [self::RESULT_IDS => $ids, self::RESULT_TOTAL => $total];
    }

    /** Published items, narrowed by core's `include` and `exclude`. */
    private static function where(array $spec, \WP_REST_Request $request): array
    {
        $and = ($spec['drafts'] ?? false) ? [['status' => 'published']] : [];
        foreach (['include' => 'in', 'exclude' => 'notIn'] as $param => $op) {
            $values = array_values(array_filter(array_map('intval', (array) ($request[$param] ?? [])), static fn (int $v): bool => $v > 0));
            if ($values !== []) {
                $and[] = [$spec['primaryKey'] => [$op => $values]];
            } elseif ($param === 'include' && ($request[$param] ?? []) !== []) {
                $and[] = [$spec['primaryKey'] => ['in' => [0]]];
            }
        }

        return $and === [] ? [] : ['AND' => $and];
    }

    public function prepare_item($id, array $fields)
    {
        if (!isset($this->found[(int) $id])) {
            return $this->core?->prepare_item($id, $fields) ?? [];
        }
        $data = $this->posts->prepare_item($id, $fields);
        if (array_key_exists(\WP_REST_Search_Controller::PROP_TITLE, $data) && !$this->found[(int) $id]) {
            $data[\WP_REST_Search_Controller::PROP_TITLE] = '';
        }

        return $data;
    }

    public function prepare_item_links($id)
    {
        return isset($this->found[(int) $id]) ? $this->posts->prepare_item_links($id) : ($this->core?->prepare_item_links($id) ?? []);
    }
}
