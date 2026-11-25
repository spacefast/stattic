<?php

declare(strict_types=1);

namespace SpacePress\Wp;

use SpacePress\HttpError;

/**
 * Core `/wp/v2/sp_<name>` for a `taxonomy()` kind (docs/decisions/2026-11-11-authoring-surface.md,
 * rulings 25 and 29): core's terms controller, with SpacePress's rules on top of the per-taxonomy
 * capabilities it already checks ({@see CoreAccess::mapMetaCap()} answers those from the same rules).
 *
 * - Reads: a read rule that denies is 401/403; SpacePress's `where`, `orderBy` and `with` (as on a post
 *   kind's route) select and attach through SpacePress's read pipeline, so access rules and field masks
 *   decide as they do for `topics.list()`. `meta` carries the authored fields this viewer may read.
 * - Writes: create, update and delete run through SpacePress's write pipeline as the viewer, in one
 *   transaction (validation, field rules, the taxonomy's create/update/delete rule), so a core client
 *   and the API get the same answer. The response is core's item shape.
 */
final class TermsController extends \WP_REST_Terms_Controller
{
    private string $collection;

    public function __construct($taxonomy)
    {
        parent::__construct($taxonomy);
        $this->collection = CoreAccess::taxonomyKind((string) $taxonomy) ?? throw new \LogicException("$taxonomy is not a SpacePress taxonomy");
        $this->meta = new TermMeta((string) $taxonomy, $this->collection);
    }

    public function get_collection_params()
    {
        return parent::get_collection_params() + [
            'where' => ['description' => 'A SpacePress filter, as JSON: `{"name":{"like":"a%"}}`.', 'type' => 'string'],
            'orderBy' => ['description' => 'SpacePress fields to sort by, comma-separated; `-field` sorts descending.', 'type' => 'string'],
            'with' => ['description' => 'Relations to attach under `with`: `posts` or a JSON object.', 'type' => 'string'],
        ];
    }

    public function get_items_permissions_check($request)
    {
        return CoreAccess::readDenial($this->collection, CoreAccess::viewer()) ?? parent::get_items_permissions_check($request);
    }

    public function get_items($request)
    {
        $viewer = CoreAccess::viewer() ?? throw new \LogicException('checked');
        try {
            $keys = SpacePressQuery::keys($this->collection, $request, $viewer, false);
        } catch (HttpError $e) {
            return CoreAccess::error($e);
        }
        if ($keys === null) {
            return SpacePressQuery::attach($this->collection, $request, $viewer, parent::get_items($request));
        }
        $ordered = $request['orderBy'] !== null;
        $limit = static function (array $args) use ($keys, $ordered): array {
            $in = !empty($args['include']) ? array_values(array_intersect($keys, array_map('intval', (array) $args['include']))) : $keys;

            return ['include' => $in === [] ? [0] : $in] + ($ordered ? ['orderby' => 'include'] : []) + $args;
        };
        add_filter("rest_{$this->taxonomy}_query", $limit, PHP_INT_MAX);
        try {
            return SpacePressQuery::attach($this->collection, $request, $viewer, parent::get_items($request));
        } finally {
            remove_filter("rest_{$this->taxonomy}_query", $limit, PHP_INT_MAX);
        }
    }

    public function get_item_permissions_check($request)
    {
        return CoreAccess::readDenial($this->collection, CoreAccess::viewer()) ?? parent::get_item_permissions_check($request);
    }

    public function get_item($request)
    {
        $viewer = CoreAccess::viewer();

        return $viewer === null ? parent::get_item($request) : SpacePressQuery::attach($this->collection, $request, $viewer, parent::get_item($request));
    }

    /** A term row: core's, with `url` as SpacePress builds it and SpacePress's own row for the facade. */
    public function prepare_item_for_response($item, $request)
    {
        $response = parent::prepare_item_for_response($item, $request);
        if (Facade::dispatching() && $response instanceof \WP_REST_Response && is_array($data = $response->get_data())
            && ($viewer = CoreAccess::viewer()) !== null && $item instanceof \WP_Term) {
            $db = Plugin::instance()?->db() ?? throw new \LogicException('No SpacePress app');
            $row = $db->findFirst($this->collection, ['where' => ['id' => $item->term_id]], $viewer);
            $data[Kinds::ROW] = $row === null ? null : (object) PostMeta::values($this->collection, $row, array_map('strval', array_keys($row)));
            $response->set_data($data);
        }

        return $response;
    }

    // ================================================================ writes through SpacePress's pipeline

    public function create_item_permissions_check($request)
    {
        return $this->writeDenial('create') ?? parent::create_item_permissions_check($request);
    }

    public function update_item_permissions_check($request)
    {
        return $this->writeDenial('update') ?? parent::update_item_permissions_check($request);
    }

    public function delete_item_permissions_check($request)
    {
        return $this->writeDenial('delete') ?? parent::delete_item_permissions_check($request);
    }

    /** A suspended account, or a kind whose $op rule refuses this viewer outright. */
    private function writeDenial(string $op): ?\WP_Error
    {
        $viewer = CoreAccess::viewer();
        if ($viewer === null) {
            return CoreAccess::error(new HttpError(403, 'suspended', 'This account is suspended'));
        }
        $spec = Plugin::instance()?->schema->collection($this->collection) ?? throw new \LogicException('No SpacePress app');
        $rule = \SpacePress\Access::collectionRules($spec)[$op];
        if (!isset($rule['fn']) && \SpacePress\Access::evaluate($rule, $viewer, $op, null) === false) {
            return CoreAccess::error(\SpacePress\Access::deny($viewer->user, "$op {$this->collection} rows"));
        }

        return null;
    }

    public function create_item($request)
    {
        return $this->write($request, null);
    }

    public function update_item($request)
    {
        $term = $this->get_term($request['id']);

        return $term instanceof \WP_Error ? $term : $this->write($request, $term->term_id);
    }

    public function delete_item($request)
    {
        $term = $this->get_term($request['id']);
        if ($term instanceof \WP_Error) {
            return $term;
        }
        if (!($request['force'] ?? false) && !Facade::dispatching()) {
            return parent::delete_item($request); // core's answer: terms aren't trashed
        }
        $request->set_param('context', 'view');
        $previous = $this->prepare_item_for_response($term, $request);
        $result = $this->pipeline(fn (\SpacePress\Db $db, \SpacePress\Viewer $viewer) => $db->delete($this->collection, ['id' => $term->term_id], true, $viewer)[0]
            ?? throw HttpError::notFound("No {$this->collection} row with id {$term->term_id}"));
        if ($result instanceof \WP_Error) {
            return $result;
        }
        $response = new \WP_REST_Response();
        $response->set_data(['deleted' => true, 'previous' => $previous->get_data()]);
        do_action("rest_delete_{$this->taxonomy}", $term, $response, $request);

        return $response;
    }

    /** A create or update as SpacePress's write: core's term fields and `meta`, or the API's own body. */
    private function write(\WP_REST_Request $request, ?int $id): mixed
    {
        $data = [];
        // Only what the request sent: core fills its own defaults into params it never received.
        $sent = (array) ($request->get_json_params() ?? []) + (array) $request->get_body_params();
        foreach (['name', 'slug', 'description', 'parent'] as $field) {
            if (array_key_exists($field, $sent) && ($field !== 'parent' || isset(\SpacePress\db()->schema()->collection($this->collection)['columns']['parent']))) {
                $data[$field] = $request->get_param($field);
            }
        }
        if (array_key_exists('parent', $data)) {
            $data['parent'] = (int) $data['parent'] === 0 ? null : (int) $data['parent'];
        }
        $spec = \SpacePress\db()->schema()->collection($this->collection);
        $meta = TermMeta::input($request);
        if (is_array($meta)) {
            $data += array_intersect_key($meta, TermMeta::fields($spec));
        }
        // The API's other fields (one the kind doesn't have, a read-only one): SpacePress refuses them.
        if (is_array($extra = \SpacePress\Seam::container($sent['spacepress'] ?? null))) {
            $data += $extra;
        }
        $row = $this->pipeline(fn (\SpacePress\Db $db, \SpacePress\Viewer $viewer) => $id === null
            ? $db->insert($this->collection, $data, true, $viewer)[0]
            : ($db->update($this->collection, $data, ['id' => $id], true, $viewer)[0] ?? throw HttpError::notFound("No {$this->collection} row with id $id")));
        if ($row instanceof \WP_Error) {
            return $row;
        }
        $term = get_term((int) $row['id'], $this->taxonomy);
        if (!$term instanceof \WP_Term) {
            return new \WP_Error('rest_term_invalid', __('Term does not exist.'), ['status' => 404]);
        }
        do_action("rest_insert_{$this->taxonomy}", $term, $request, $id === null);
        $request->set_param('context', 'edit');
        do_action("rest_after_insert_{$this->taxonomy}", $term, $request, $id === null);
        $response = rest_ensure_response($this->prepare_item_for_response($term, $request));
        if ($id === null) {
            $response->set_status(201);
            $response->header('Location', rest_url($this->namespace . '/' . $this->rest_base . '/' . $term->term_id));
        }

        return $response;
    }

    /** One write as the viewer in one transaction; SpacePress's refusals as core's errors. */
    private function pipeline(callable $fn): mixed
    {
        $plugin = Plugin::instance() ?? throw new \LogicException('No SpacePress app');
        if ($plugin->unavailable() !== null) {
            return new \WP_Error('sp_unavailable', Plugin::UNAVAILABLE, ['status' => 503]);
        }
        try {
            $viewer = CoreAccess::viewer() ?? throw new HttpError(403, 'suspended', 'This account is suspended');
            $db = $plugin->db();

            return $db->transaction(function () use ($db, $viewer, $fn) {
                $previous = $db->actor();
                $db->setActor($viewer->user);
                try {
                    return $fn($db, $viewer);
                } finally {
                    $db->setActor($previous);
                }
            });
        } catch (HttpError | \SpacePress\JsError $e) {
            $problem = \SpacePress\App::problem($e);

            return CoreAccess::error(new HttpError($problem['status'], $problem['code'], $problem['detail'], $problem['pointer'] ?? null));
        }
    }
}
