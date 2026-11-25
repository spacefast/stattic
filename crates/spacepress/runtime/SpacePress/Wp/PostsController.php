<?php

declare(strict_types=1);

namespace SpacePress\Wp;

/**
 * Core `/wp/v2/sp_*` (§4.8) for one SpacePress post type: core's posts controller, with SpacePress's access
 * rules ({@see CoreAccess}) on top of the capabilities it already checks.
 *
 * - Lists: a read rule that denies is 401/403; a filtering rule (or draft visibility) limits the
 *   query to the rows it covers, so pagination counts only those. `search` and `slug` can't probe a
 *   column hidden from the viewer.
 * - Items: a row the read rule filters out is 404, as in SpacePress's read pipeline.
 * - Every response, in every context, drops the fields the viewer can't read.
 * - Creates and updates run in one transaction ({@see EditorSave}) that SpacePress's rules authorize on the
 *   save's own data before it commits; a refusal is SpacePress's 401/403 and nothing is saved.
 */
final class PostsController extends \WP_REST_Posts_Controller
{
    private string $collection;
    /** @var ?array{request: \WP_REST_Request, ids: list<int>, rows: array, generation: int} */
    private ?array $projectedPage = null;

    public function __construct($post_type)
    {
        parent::__construct($post_type);
        $this->collection = CoreAccess::collection($post_type) ?? throw new \LogicException("$post_type is not a SpacePress post type");
        $this->meta = new PostMeta($post_type, $this->collection);
    }

    /** SpacePress's query arguments (spec §3a), next to core's own; values as on the facade's `?where=`. */
    public function get_collection_params()
    {
        return parent::get_collection_params() + [
            'where' => ['description' => 'A SpacePress filter, as JSON: `{"seats":{"gte":10}}`.', 'type' => 'string'],
            'orderBy' => ['description' => 'SpacePress fields to sort by, comma-separated; `-field` sorts descending.', 'type' => 'string'],
            'with' => ['description' => 'Relations to attach under `with`: `author,comments` or a JSON object.', 'type' => 'string'],
        ] + ((Plugin::instance()?->schema->collection($this->collection)['localized'] ?? false)
            ? ['locale' => ['description' => 'A SpacePress locale, or `*`: that locale\'s rows, else each translation group\'s fallback.', 'type' => 'string']]
            : []);
    }

    public function get_items_permissions_check($request)
    {
        return $this->readRequest($request, fn () => CoreAccess::readDenial($this->collection, CoreAccess::viewer())
            ?? CoreAccess::queryDenial($this->collection, $request, CoreAccess::viewer())
            ?? parent::get_items_permissions_check($request));
    }

    public function get_items($request)
    {
        $facade = Facade::dispatching();
        // The page's stored rows in one read (storedRow()), for the API's own lists and for a core
        // list that asks for some fields. Where a read extension or a locale could give the batch
        // other rows than each item's own read, every item keeps its own.
        $batch = ($facade || $request->has_param('_fields')) && $request->get_method() === 'GET'
            && \SpacePress\Extensions::constraintReadsSafe() && !\SpacePress\Locale\Module::localized($this->collection);
        if ((!$facade && !$batch) || ($viewer = CoreAccess::viewer()) === null) {
            return $this->readRequest($request, fn () => $this->readItems($request));
        }
        $previous = $this->projectedPage;
        $this->projectedPage = $batch ? ['request' => $request, 'ids' => [], 'rows' => [], 'generation' => -1] : null;
        $prefetch = function (mixed $posts, mixed $query) use ($viewer, $facade): mixed {
            if (is_array($posts) && $query instanceof \WP_Query && $query->get('post_type') === $this->post_type) {
                $ids = array_map(static fn ($p) => (int) ($p instanceof \WP_Post ? $p->ID : $p), $posts);
                if ($facade) {
                    Facade::prefetch($this->collection, $ids, $viewer);
                }
                if ($this->projectedPage !== null && $this->projectedPage['generation'] === -1 && count($ids) > 1 && count($ids) <= 100) {
                    $this->projectedPage['ids'] = $ids;
                }
            }
            return $posts;
        };
        add_filter('the_posts', $prefetch, 10, 2);
        try {
            return $this->readRequest($request, fn () => $this->readItems($request));
        } finally {
            remove_filter('the_posts', $prefetch, 10);
            $this->projectedPage = $previous;
        }
    }

    private function readItems(\WP_REST_Request $request)
    {
        $viewer = CoreAccess::viewer() ?? throw new \LogicException('checked');
        try {
            $query = SpacePressQuery::keys($this->collection, $request, $viewer, searched: $searched);
        } catch (\SpacePress\HttpError $e) {
            return CoreAccess::error($e);
        }
        if ($query !== null && \SpacePress\Extensions::constraintReadsSafe()) {
            // The SpacePress query already applied the read rule and draft visibility. Reuse that
            // result when core checks each post.
            CoreAccess::readable($this->collection, $query, $viewer);
            $keys = $query;
        } else {
            $keys = CoreAccess::keys($this->collection, 'read', $viewer);
            if ($keys === false) {
                return CoreAccess::error(\SpacePress\Access::deny($viewer->user, "read {$this->collection}"));
            }
            $keys = $keys === null ? null : array_map('intval', $keys);
            if ($query !== null) {
                $keys = $keys === null ? $query : array_values(array_intersect($query, $keys));
            }
        }
        if ($keys === null) {
            return SpacePressQuery::attach($this->collection, $request, $viewer, parent::get_items($request));
        }
        // Core maps the default empty `include` to post__in = [], which means no restriction.
        $ordered = $query !== null && $request['orderBy'] !== null;
        // Rows SpacePress's query selected already passed its read rule and draft visibility: core's own
        // status filter (published only, for most viewers) must not drop them again.
        // Only a `status` the caller sent counts: core's own default (`publish`) is not a request.
        $sent = $request->get_query_params() + $request->get_body_params();
        $statuses = $query !== null && !isset($sent['status']) ? ['post_status' => ['publish', 'future', 'draft', 'pending', 'private']] : [];
        $limit = static function (array $args) use ($keys, $ordered, $statuses, $searched): array {
            $in = !empty($args['post__in']) ? array_values(array_intersect($keys, array_map('intval', (array) $args['post__in']))) : $keys;
            $args = $searched ? SpacePressQuery::coreArgs($args) : $args;

            return ['post__in' => $in === [] ? [0] : $in] + ($ordered ? ['orderby' => 'post__in'] : []) + $statuses + $args;
        };
        add_filter("rest_{$this->post_type}_query", $limit, PHP_INT_MAX);
        try {
            return SpacePressQuery::attach($this->collection, $request, $viewer, parent::get_items($request));
        } finally {
            remove_filter("rest_{$this->post_type}_query", $limit, PHP_INT_MAX);
        }
    }

    public function get_item_permissions_check($request)
    {
        return $this->readRequest($request, fn () => $this->readItemPermissions($request));
    }

    private function readItemPermissions(\WP_REST_Request $request)
    {
        $viewer = CoreAccess::viewer();
        if (($denied = CoreAccess::readDenial($this->collection, $viewer)) !== null) {
            return $denied;
        }
        $post = $this->get_post($request['id']);
        if ($post instanceof \WP_Post && $post->post_status === 'trash' && Facade::dispatching()) {
            return new \WP_Error('rest_post_invalid_id', __('Invalid post ID.'), ['status' => 404]);
        }
        if ($post instanceof \WP_Post && !empty($request->get_query_params()['password'])) {
            $native = parent::get_item_permissions_check($request);
            if (is_wp_error($native) && $native->get_error_code() === 'rest_post_incorrect_password') {
                // A wrong password must not reveal a row the collection's own rule conceals.
                $readable = $this->readRequest($request,
                    fn () => CoreAccess::covers($this->collection, 'read', $post->ID, $viewer),
                    $post->post_password,
                );
                if ($readable) {
                    return $native;
                }
            }
        }
        if ($post instanceof \WP_Post && $post->post_status !== 'auto-draft' && $post->post_status !== 'trash'
            && !CoreAccess::covers($this->collection, 'read', $post->ID, $viewer)) {
            return new \WP_Error('rest_post_invalid_id', __('Invalid post ID.'), ['status' => 404]);
        }

        return parent::get_item_permissions_check($request);
    }

    public function get_item($request)
    {
        return $this->readRequest($request, function () use ($request) {
            $viewer = CoreAccess::viewer();

            return $viewer === null ? parent::get_item($request) : SpacePressQuery::attach($this->collection, $request, $viewer, parent::get_item($request));
        });
    }

    private function readRequest(\WP_REST_Request $request, callable $read, ?string $passwordProof = null): mixed
    {
        $password = $passwordProof ?? $request['password'];
        $viewer = CoreAccess::viewer();
        $store = Plugin::instance()?->db()->store();
        if ($viewer === null || !$store instanceof CompositeStore) {
            return $read();
        }
        $password = in_array($request->get_method(), ['GET', 'HEAD'], true) && is_string($password) && $password !== '' ? $password : null;

        return $store->posts->withReadPassword($this->collection, $viewer, $password, $read);
    }

    public function check_read_permission($post)
    {
        return parent::check_read_permission($post) && current_user_can('read_post', $post->ID);
    }

    /**
     * The API reshapes each item into SpacePress's row and never reads core's `_links` (HAL links; for a
     * post, one revisions query per item). Its own dispatches leave them out; core's clients keep them.
     */
    public function get_fields_for_response($request)
    {
        $fields = parent::get_fields_for_response($request);

        return Facade::dispatching() ? array_values(array_diff($fields, ['_links'])) : $fields;
    }

    private function storedRow(int $id, \WP_REST_Request $request): ?array
    {
        $db = Plugin::instance()?->db();
        if ($this->projectedPage !== null && $this->projectedPage['request'] === $request) {
            $page = &$this->projectedPage;
            if ($page['generation'] !== CoreAccess::generation()) {
                $page['rows'] = [];
            }
            if ($page['ids'] !== [] && in_array($id, $page['ids'], true)) {
                $ids = $page['ids'];
                $page['ids'] = [];
                $page['generation'] = CoreAccess::generation();
                $page['rows'] = $db?->editorRows($this->collection, $ids) ?? [];
            }
            if ($page['generation'] === CoreAccess::generation() && isset($page['rows'][$id])) {
                return $page['rows'][$id];
            }
        }
        return $db?->editorRow($this->collection, $id);
    }

    public function prepare_item_for_response($item, $request)
    {
        $stored = $this->storedRow((int) $item->ID, $request);
        $response = $this->meta instanceof PostMeta ? $this->meta->with((int) $item->ID, $stored, fn () => parent::prepare_item_for_response($item, $request))
            : parent::prepare_item_for_response($item, $request);
        // For the facade's own dispatches, SpacePress's row as this viewer reads it (field masks, system
        // fields such as `uid`, `locale` and `translations`): core already decided the item is
        // readable; the facade only reshapes. Core's own clients keep core's shape.
        if (Facade::dispatching() && $response instanceof \WP_REST_Response && is_array($data = $response->get_data()) && ($viewer = CoreAccess::viewer()) !== null) {
            $data[Kinds::ROW] = Facade::spRow($this->collection, (int) $item->ID, $viewer);
            $response->set_data($data);
        }

        return CoreAccess::strip($response, $this->collection, $stored, CoreAccess::viewer());
    }

    /**
     * An update rule that is a function may refuse without data and allow this request's data: core's
     * `edit_post` (which also guards revisions and wp-admin) stays the strict answer, and only this
     * update is admitted when the rule, given the data it declares, may allow it. EditorSave then
     * authorizes what was actually saved, against the row as it was.
     */
    public function update_item_permissions_check($request)
    {
        $allowed = parent::update_item_permissions_check($request);
        // WordPress's own posts: core's capability refused, and the app's rules only narrow it.
        if (!is_wp_error($allowed) || $allowed->get_error_code() !== 'rest_cannot_edit'
            || (Plugin::instance()?->schema->collection($this->collection)['native'] ?? false)) {
            return $allowed;
        }
        $post = $this->get_post($request['id']);
        $viewer = CoreAccess::viewer();
        if (!$post instanceof \WP_Post || $viewer === null || $viewer->user === null || !in_array($post->post_status, ['publish', 'future', 'draft', 'pending', 'private'], true)
            || !CoreAccess::mayUpdateWith($this->collection, $post->ID, CoreAccess::incoming($this->collection, $request)[0], $viewer)) {
            return $allowed;
        }

        return true;
    }

    public function create_item($request)
    {
        // A localized post kind's translation (its language and group are SpacePress's write); the schema
        // says so without a running app, which a core create may come before.
        $localized = (bool) (Plugin::instance()?->schema->collection($this->collection)['localized'] ?? false);
        if (Facade::dispatching() || ($localized && ($request->has_param('locale') || $request->has_param('translationOf')))) {
            return $this->spWrite($request, null);
        }

        return EditorSave::rest($this->collection, null, $request, fn () => $this->save($request, fn () => parent::create_item($request)));
    }

    /**
     * A write through SpacePress's own pipeline (Db::insert / Db::update as the viewer, in one transaction):
     * before hooks, defaults, draft rules, validation, field and row rules, after hooks, jobs. Used for
     * the API's writes (spec §3a: core routes enforce SpacePress's rules; the facade only maps) and for a
     * create that names a `locale` or `translationOf` (Polylang group membership is SpacePress's write).
     * The answer is core's item shape, as the users route answers its creates ({@see UserMeta}).
     */
    private function spWrite(\WP_REST_Request $request, ?int $id): mixed
    {
        $plugin = Plugin::instance() ?? throw new \LogicException('No SpacePress app');
        $spec = $plugin->schema->collection($this->collection);
        $data = [];
        foreach (['title' => $spec['title'] ?? null, 'content' => $spec['content'] ?? null, 'slug' => $spec['slug'] ?? null, 'excerpt' => $spec['excerpt'] ?? null] as $param => $col) {
            if ($col !== null && $request->has_param($param)) {
                $v = $request->get_param($param);
                $data[$col] = is_array($v) ? ($v['raw'] ?? null) : $v;
            }
        }
        $sent = $request->get_json_params() ?? $request->get_body_params();
        if (is_string($status = $sent['status'] ?? null)) {
            $data['status'] = ['publish' => 'published', 'future' => 'scheduled'][$status] ?? $status;
        }
        if (array_key_exists('date_gmt', (array) $sent)) {
            $date = $sent['date_gmt'];
            $data['publishedAt'] = is_string($date) && $date !== '' ? substr($date, 0, 19) . '.000Z' : null;
        }
        $meta = PostMeta::input($request);
        if (is_array($meta)) {
            $data += array_intersect_key($meta, PostMeta::fields($spec));
        }
        // The API's other fields as sent (`uid`, or a field the kind doesn't have): SpacePress decides them.
        $body = (string) $request->get_body();
        try {
            $decoded = trim($body) === '' ? null : \SpacePress\Seam::container(\SpacePress\Js::decodeJson($body));
        } catch (\JsonException) {
            $decoded = null;
        }
        $extra = is_array($decoded) ? \SpacePress\Seam::container($decoded['spacepress'] ?? null) : null;
        $data += is_array($extra) ? $extra : [];
        foreach (['locale', 'translationOf'] as $param) {
            if (array_key_exists($param, (array) $sent)) {
                $data[$param] = $sent[$param];
            }
        }
        try {
            $viewer = CoreAccess::viewer() ?? throw new \SpacePress\HttpError(403, 'suspended', 'This account is suspended');
            $db = $plugin->db();
            $pk = $spec['primaryKey'];
            $row = $db->transaction(function () use ($db, $viewer, $data, $id, $pk, $request) {
                $previous = $db->actor();
                $db->setActor($viewer->user);
                try {
                    $row = $id === null
                        ? $db->insert($this->collection, $data, true, $viewer)[0]
                        : ($db->update($this->collection, $data, [$pk => $id], true, $viewer)[0] ?? throw \SpacePress\HttpError::notFound("No {$this->collection} row with $pk $id"));
                } finally {
                    $db->setActor($previous);
                }
                // The rest of core's create_item/update_item after its save, in the same transaction:
                // `rest_insert_{type}`, the registered REST fields, `rest_after_insert_{type}`. Other
                // plugins hooked there see the API's writes as they see core's.
                clean_post_cache((int) $row[$pk]);
                $post = get_post((int) $row[$pk]) ?: throw new \LogicException("Post {$row[$pk]} vanished");
                $this->coreFields($post, $request);
                do_action("rest_insert_{$this->post_type}", $post, $request, $id === null);
                // Other plugins' registered meta (outside SpacePress's fields), as core's save writes it.
                $meta = PostMeta::input($request);
                $others = is_array($meta) ? array_diff_key($meta, PostMeta::fields(Plugin::instance()?->schema->collection($this->collection) ?? [])) : [];
                if ($others !== [] && $this->meta !== null) {
                    $done = $this->meta->update_value($others, $post->ID);
                    if (is_wp_error($done)) {
                        throw new \SpacePress\HttpError((int) ($done->get_error_data()['status'] ?? 400), 'invalid_input', $done->get_error_message());
                    }
                }
                $fields = $this->update_additional_fields_for_object($post, $request);
                if (is_wp_error($fields)) {
                    throw new \SpacePress\HttpError((int) ($fields->get_error_data()['status'] ?? 400), 'invalid_input', $fields->get_error_message());
                }
                $post = get_post((int) $row[$pk]);
                do_action("rest_after_insert_{$this->post_type}", $post, $request, $id === null);

                return $row;
            });
        } catch (\SpacePress\HttpError | \SpacePress\JsError $e) {
            $problem = \SpacePress\App::problem($e);

            return CoreAccess::error(new \SpacePress\HttpError($problem['status'], $problem['code'], $problem['detail'], $problem['pointer'] ?? null));
        }
        clean_post_cache((int) $row[$pk]);
        $response = rest_ensure_response($this->prepare_item_for_response(get_post((int) $row[$pk]), $request));
        $response->set_status($id === null ? 201 : 200);

        return $response;
    }

    /**
     * The core post fields a SpacePress write doesn't own, applied as core's create_item/update_item applies
     * them after its save: author, menu order, featured image and template. SpacePress's own write keeps
     * title, content, slug, status and its fields; a core client's other fields are not dropped. An
     * `author` other than the caller needs the type's edit_others capability, as core checks it.
     */
    private function coreFields(\WP_Post $post, \WP_REST_Request $request): void
    {
        $postarr = [];
        if ($request->has_param('author') && (int) $request['author'] !== (int) $post->post_author) {
            $type = get_post_type_object($this->post_type);
            if ((int) $request['author'] !== get_current_user_id() && !current_user_can($type->cap->edit_others_posts)) {
                throw new \SpacePress\HttpError(403, 'forbidden', 'You may not set another author', '/author');
            }
            $postarr['post_author'] = (int) $request['author'];
        }
        if ($request->has_param('menu_order')) {
            $postarr['menu_order'] = (int) $request['menu_order'];
        }
        if ($postarr !== []) {
            $done = wp_update_post(['ID' => $post->ID] + $postarr, true);
            if (is_wp_error($done)) {
                throw new \SpacePress\HttpError(400, 'invalid_input', $done->get_error_message());
            }
        }
        // What core's update_item does after its save, with public functions: the featured image,
        // the page template (handle_template is public) and the terms of each REST taxonomy.
        if ($request->has_param('featured_media')) {
            (int) $request['featured_media'] !== 0 ? set_post_thumbnail($post->ID, (int) $request['featured_media']) : delete_post_thumbnail($post->ID);
        }
        if ($request->has_param('template')) {
            $this->handle_template($request['template'], $post->ID, true);
        }
        foreach (wp_list_filter(get_object_taxonomies($this->post_type, 'objects'), ['show_in_rest' => true]) as $taxonomy) {
            $base = !empty($taxonomy->rest_base) ? $taxonomy->rest_base : $taxonomy->name;
            if (!isset($request[$base])) {
                continue;
            }
            $terms = wp_set_object_terms($post->ID, $request[$base], $taxonomy->name);
            if (is_wp_error($terms)) {
                throw new \SpacePress\HttpError(400, 'invalid_input', $terms->get_error_message());
            }
        }
    }

    /** Native REST `DELETE ?force=true` (or deleting an already-trashed post) enforces SpacePress `purge` access. */
    public function delete_item_permissions_check($request)
    {
        $post = $this->get_post($request['id']);
        if (is_wp_error($post)) {
            return $post;
        }
        $plugin = Plugin::instance();
        $spec = $plugin?->schema->hasCollection($this->collection) ? $plugin->schema->collection($this->collection) : null;
        $forcing = !empty($request['force']) || $post->post_status === 'trash';
        if ($spec !== null && ($spec['softDelete'] ?? false) && $forcing && !Facade::dispatching()) {
            $viewer = CoreAccess::viewer();
            $store = $plugin->db()->store();
            $check = fn (): bool => $viewer !== null && $plugin->db()->permits($this->collection, 'purge', $post->ID, $viewer);
            $allowed = $store instanceof CompositeStore ? $store->posts->withTrash($check) : $check();
            if (!$allowed) {
                return CoreAccess::error(\SpacePress\Access::deny($viewer?->user, "purge {$this->collection} rows"));
            }
        }

        return parent::delete_item_permissions_check($request);
    }

    /** A hook refusing the delete is the editor's 4xx with its message (§1.7), and nothing is deleted. */
    public function delete_item($request)
    {
        // Inside a transaction someone else opened, a refusal must abort it, not become a response.
        if (Plugin::instance()?->connection()->inTransaction()) {
            return parent::delete_item($request);
        }
        try {
            return parent::delete_item($request);
        } catch (\SpacePress\HttpError | \SpacePress\JsError $e) {
            $problem = \SpacePress\App::problem($e);

            return CoreAccess::error(new \SpacePress\HttpError($problem['status'], $problem['code'], $problem['detail'], $problem['pointer'] ?? null));
        }
    }

    public function update_item($request)
    {
        if (Facade::dispatching()) {
            $post = $this->get_post($request['id']);
            if (!$post instanceof \WP_Post) {
                return $post;
            }

            return $this->spWrite($request, $post->ID);
        }
        $post = $this->get_post($request['id']);
        $postId = $post instanceof \WP_Post ? $post->ID : null;

        return EditorSave::rest($this->collection, $postId, $request, fn () => $this->save($request, fn () => parent::update_item($request)));
    }

    private function save(\WP_REST_Request $request, callable $save): mixed
    {
        return $this->meta instanceof PostMeta ? $this->meta->saving($request, $save) : $save();
    }
}
