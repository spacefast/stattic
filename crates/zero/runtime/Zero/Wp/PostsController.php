<?php

declare(strict_types=1);

namespace Zero\Wp;

/**
 * Core `/wp/v2/zero_*` (§4.8) for one Zero post type: core's posts controller, with Zero's access
 * rules ({@see CoreAccess}) on top of the capabilities it already checks.
 *
 * - Lists: a read rule that denies is 401/403; a filtering rule (or draft visibility) limits the
 *   query to the rows it covers, so pagination counts only those. `search` and `slug` can't probe a
 *   column hidden from the viewer.
 * - Items: a row the read rule filters out is 404, as on `/zero/v1`.
 * - Every response, in every context, drops the fields the viewer can't read.
 * - Creates and updates run in one transaction ({@see EditorSave}) that Zero's rules authorize on the
 *   save's own data before it commits; a refusal is Zero's 401/403 and nothing is saved.
 */
final class PostsController extends \WP_REST_Posts_Controller
{
    private string $collection;

    public function __construct($post_type)
    {
        parent::__construct($post_type);
        $this->collection = CoreAccess::collection($post_type) ?? throw new \LogicException("$post_type is not a Zero post type");
    }

    public function get_items_permissions_check($request)
    {
        return $this->readRequest($request, fn () => CoreAccess::readDenial($this->collection, CoreAccess::viewer())
            ?? CoreAccess::queryDenial($this->collection, $request, CoreAccess::viewer())
            ?? parent::get_items_permissions_check($request));
    }

    public function get_items($request)
    {
        return $this->readRequest($request, fn () => $this->readItems($request));
    }

    private function readItems(\WP_REST_Request $request)
    {
        $keys = CoreAccess::keys($this->collection, 'read', CoreAccess::viewer() ?? throw new \LogicException('checked'));
        if (!is_array($keys)) {
            return parent::get_items($request);
        }
        $allowed = array_map('intval', $keys);
        // Core maps the default empty `include` to post__in = [], which means no restriction.
        $limit = static function (array $args) use ($allowed): array {
            $in = !empty($args['post__in']) ? array_values(array_intersect($allowed, array_map('intval', (array) $args['post__in']))) : $allowed;

            return ['post__in' => $in === [] ? [0] : $in] + $args;
        };
        add_filter("rest_{$this->post_type}_query", $limit, PHP_INT_MAX);
        try {
            return parent::get_items($request);
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
        return $this->readRequest($request, fn () => parent::get_item($request));
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

    public function prepare_item_for_response($item, $request)
    {
        $response = parent::prepare_item_for_response($item, $request);

        $stored = Plugin::instance()?->db()->editorRow($this->collection, (int) $item->ID);

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
        if (!is_wp_error($allowed) || $allowed->get_error_code() !== 'rest_cannot_edit') {
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
        return EditorSave::rest($this->collection, null, $request, fn () => parent::create_item($request));
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
        } catch (\Zero\HttpError | \Zero\JsError $e) {
            $problem = \Zero\App::problem($e);

            return CoreAccess::error(new \Zero\HttpError($problem['status'], $problem['code'], $problem['detail'], $problem['pointer'] ?? null));
        }
    }

    public function update_item($request)
    {
        $post = $this->get_post($request['id']);
        $postId = $post instanceof \WP_Post ? $post->ID : null;

        return EditorSave::rest($this->collection, $postId, $request, fn () => parent::update_item($request));
    }
}
