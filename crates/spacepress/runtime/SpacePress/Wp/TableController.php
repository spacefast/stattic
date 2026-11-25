<?php

declare(strict_types=1);

namespace SpacePress\Wp;

use SpacePress\App;
use SpacePress\Extensions;
use SpacePress\HttpError;
use SpacePress\Response;
use SpacePress\Viewer;
use SpacePress\Wp\Abilities\JsonSchema;

/**
 * Core-shaped `/wp/v2/<table>` for a `table()` kind (spec §3a): the one new core route. Post kinds,
 * users and media already have core routes; a custom SQL table has none, so this controller gives
 * it one in core's shape (`WP_REST_Controller`: collection and item routes, `per_page`/`page`,
 * `X-WP-Total`/`X-WP-TotalPages`, `_fields`, a JSON Schema on OPTIONS, `WP_Error` refusals).
 *
 * It owns no query or write path: every call is SpacePress's pipeline as the viewer (`Db::findMany`,
 * `insert`, `update`, `delete`), so access rules, field masks, validation, hooks and referential
 * actions decide exactly as they do everywhere else. Rows are First rows: fields flat, as stored.
 * The route is the kind's storage name (`sp_<table>`), which core and plugins see as its REST base.
 */
final class TableController extends \WP_REST_Controller
{
    public function __construct(private readonly string $collection, string $base)
    {
        $this->namespace = 'wp/v2';
        $this->rest_base = $base;
    }

    /** Register `/wp/v2/<table>` for every table kind the API serves (rest_api_init). */
    public static function registerAll(Plugin $plugin): void
    {
        $auth = $plugin->schema->auth()['collection'] ?? null;
        foreach ($plugin->schema->collections() as $name => $spec) {
            if (($spec['editor'] ?? true) && ($spec['wp'] ?? null) !== null || $name === $auth || !isset($spec['table'])) {
                continue; // post kinds and the users kind have their own core routes
            }
            (new self((string) $name, (string) $spec['table']))->register_routes();
        }
        foreach ($plugin->schema->taxonomies() as $name => $spec) {
            if (isset($spec['commerce'])) { (new self((string) $name, 'sp_' . $name))->register_routes(); }
        }
    }

    public function register_routes(): void
    {
        $id = ['id' => ['description' => 'The row id.', 'type' => ['integer', 'string']]];
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            ['methods' => \WP_REST_Server::READABLE, 'callback' => [$this, 'get_items'], 'permission_callback' => '__return_true', 'args' => $this->get_collection_params()],
            ['methods' => \WP_REST_Server::CREATABLE, 'callback' => [$this, 'create_item'], 'permission_callback' => '__return_true', 'args' => []],
            'schema' => [$this, 'get_public_item_schema'],
        ]);
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[^/]+)', [
            'args' => $id,
            ['methods' => \WP_REST_Server::READABLE, 'callback' => [$this, 'get_item'], 'permission_callback' => '__return_true', 'args' => ['with' => $this->get_collection_params()['with']]],
            ['methods' => \WP_REST_Server::EDITABLE, 'callback' => [$this, 'update_item'], 'permission_callback' => '__return_true', 'args' => []],
            ['methods' => \WP_REST_Server::DELETABLE, 'callback' => [$this, 'delete_item'], 'permission_callback' => '__return_true', 'args' => []],
            'schema' => [$this, 'get_public_item_schema'],
        ]);
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[^/]+)/restore', [
            'args' => $id,
            ['methods' => \WP_REST_Server::CREATABLE, 'callback' => [$this, 'restore_item'], 'permission_callback' => '__return_true', 'args' => []],
        ]);
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[^/]+)/purge', [
            'args' => $id,
            ['methods' => \WP_REST_Server::DELETABLE, 'callback' => [$this, 'purge_item'], 'permission_callback' => '__return_true', 'args' => []],
        ]);
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[^/]+)/revisions', [
            'args' => $id,
            ['methods' => \WP_REST_Server::READABLE, 'callback' => [$this, 'get_revisions'], 'permission_callback' => '__return_true', 'args' => []],
        ]);
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[^/]+)/revisions/diff', [
            'args' => $id,
            ['methods' => \WP_REST_Server::READABLE, 'callback' => [$this, 'diff_revisions'], 'permission_callback' => '__return_true', 'args' => []],
        ]);
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[^/]+)/revisions/(?P<rid>\d+)', [
            'args' => $id,
            ['methods' => \WP_REST_Server::READABLE, 'callback' => [$this, 'get_revision'], 'permission_callback' => '__return_true', 'args' => []],
        ]);
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[^/]+)/revisions/(?P<rid>\d+)/restore', [
            'args' => $id,
            ['methods' => \WP_REST_Server::CREATABLE, 'callback' => [$this, 'restore_revision'], 'permission_callback' => '__return_true', 'args' => []],
        ]);
    }

    // Access is SpacePress's rules, decided inside each callback by the pipeline (one decision, one place):
    // core's permission callbacks would be a second, weaker check.

    public function get_items($request)
    {
        return $this->run('list', $request, function (Viewer $viewer) use ($request): \WP_REST_Response {
            $query = array_intersect_key($request->get_query_params(), array_flip(['where', 'orderBy', 'with', 'locale', 'search', 'deleted']));
            $opts = App::listOptions($query);
            $perPage = (int) $request['per_page'];
            $page = (int) $request['page'];
            $db = $this->db();
            // The total over the same pipeline (read rule, query checks), counted in SQL.
            $count = $db->countQuery($this->collection, $opts['where'] ?? null, $viewer, array_intersect_key($opts, ['locale' => true, 'search' => true, 'deleted' => true]));
            $total = (int) (Plugin::instance()?->connection()->run($count['sql'], $count['params'])[0]['n'] ?? 0);
            // `offset` (core's own list argument) wins over `page`, as on core's posts and users routes.
            $offset = $request['offset'] !== null ? (int) $request['offset'] : ($page - 1) * $perPage;
            $rows = $db->findMany($this->collection, ['limit' => $perPage, 'offset' => $offset] + $opts, $viewer);
            $items = [];
            foreach ($rows as $row) {
                $items[] = $this->prepare_response_for_collection($this->prepare_item_for_response($row, $request));
            }
            $pages = (int) ceil($total / max(1, $perPage));
            if ($request['offset'] === null && $page > max(1, $pages) && $total > 0) {
                throw new HttpError(400, 'rest_post_invalid_page_number', 'The page number requested is larger than the number of pages available.');
            }
            $response = rest_ensure_response($items);
            $response->header('X-WP-Total', (string) $total);
            $response->header('X-WP-TotalPages', (string) $pages);

            return $response;
        });
    }

    public function get_item($request)
    {
        return $this->run('get', $request, function (Viewer $viewer) use ($request): \WP_REST_Response {
            $opts = isset($request->get_query_params()['with']) ? ['with' => App::listOptions(['with' => $request->get_query_params()['with']])['with']] : [];
            $row = $this->db()->findFirst($this->collection, ['where' => [$this->pk() => $this->id($request)]] + $opts, $viewer)
                ?? throw $this->missing($request);

            return $this->prepare_item_for_response($row, $request);
        });
    }

    public function create_item($request)
    {
        return $this->run('create', $request, function (Viewer $viewer) use ($request): \WP_REST_Response {
            $body = (string) $request->get_body();
            $headers = self::headers($request);
            $result = null;
            $around = function (callable $write) use ($body, $headers, $viewer, &$result): array {
                $result = Extensions::execute('create:' . $this->collection, $this->spec(), $body, $headers, $viewer,
                    static fn (): Response => Response::json($write(), 201));

                return $result->data;
            };
            $row = $this->db()->insert($this->collection, $this->input($request), true, $viewer, $around)[0];
            $response = $this->prepare_item_for_response($row, $request);
            $response->set_status($result?->status ?? 201);
            foreach ($result?->headers ?? [] as $name => $value) {
                $response->header($name, $value);
            }

            return $response;
        });
    }

    public function update_item($request)
    {
        return $this->run('update', $request, function (Viewer $viewer) use ($request): \WP_REST_Response {
            [$patch, $rev, $takeover] = Rev::split($this->input($request));
            $id = $this->id($request);
            $row = Rev::guarded($this->collection, $id, $rev, $takeover, $viewer,
                fn () => $this->db()->update($this->collection, $patch, [$this->pk() => $id], true, $viewer)[0] ?? throw $this->missing($request), $patch);

            return $this->prepare_item_for_response($row, $request);
        });
    }

    public function delete_item($request)
    {
        return $this->run('delete', $request, function (Viewer $viewer) use ($request): \WP_REST_Response {
            $id = $this->id($request);
            $row = Rev::guarded($this->collection, $id, $request->get_param(Rev::FIELD), ($request->get_param(Rev::TAKEOVER) ?? '') === '1', $viewer,
                fn () => $this->db()->delete($this->collection, [$this->pk() => $id], true, $viewer)[0] ?? throw $this->missing($request));
            return rest_ensure_response(['deleted' => true, 'previous' => $this->prepare_item_for_response($row, $request)->get_data()]);
        });
    }

    public function restore_item($request)
    {
        return $this->run('delete', $request, function (Viewer $viewer) use ($request): \WP_REST_Response {
            $row = $this->db()->restore($this->collection, [$this->pk() => $this->id($request)], true, $viewer)[0]
                ?? throw $this->missing($request);

            return $this->prepare_item_for_response($row, $request);
        });
    }

    public function purge_item($request)
    {
        return $this->run('delete', $request, function (Viewer $viewer) use ($request): \WP_REST_Response {
            $row = $this->db()->purge($this->collection, [$this->pk() => $this->id($request)], true, $viewer)[0]
                ?? throw $this->missing($request);

            return rest_ensure_response(['purged' => true, 'previous' => $this->prepare_item_for_response($row, $request)->get_data()]);
        });
    }

    public function get_revisions($request)
    {
        return $this->run('update', $request, function (Viewer $viewer) use ($request): \WP_REST_Response {
            $revs = $this->db()->revisions($this->collection, $this->id($request), $viewer);
            $out = array_map(static function (array $r): array {
                $user = $r['userId'] !== null && $r['userId'] > 0 ? get_userdata($r['userId']) : null;

                return [
                    'id' => $r['id'],
                    'version' => $r['version'],
                    'op' => $r['op'],
                    'at' => $r['at'],
                    'user' => $user instanceof \WP_User ? ['id' => (int) $user->ID, 'name' => (string) $user->display_name] : null,
                    'schemaMigration' => $r['schemaMigration'],
                    'changed' => $r['changed'],
                ];
            }, $revs);

            return rest_ensure_response($out);
        });
    }

    public function diff_revisions($request)
    {
        return $this->run('update', $request, function (Viewer $viewer) use ($request): \WP_REST_Response {
            $from = preg_match('/^\d{1,18}$/D', (string) ($request['from'] ?? '')) ? (int) $request['from'] : throw HttpError::invalidQuery('from must be an integer', '/from');
            $to = preg_match('/^\d{1,18}$/D', (string) ($request['to'] ?? '')) ? (int) $request['to'] : throw HttpError::invalidQuery('to must be an integer', '/to');

            return rest_ensure_response($this->db()->diffRevisions($this->collection, $this->id($request), $from, $to, $viewer, byVersion: true));
        });
    }

    public function get_revision($request)
    {
        return $this->run('update', $request, function (Viewer $viewer) use ($request): \WP_REST_Response {
            $row = $this->db()->revision($this->collection, $this->id($request), (int) $request['rid'], $viewer);

            return $this->prepare_item_for_response($row, $request);
        });
    }

    public function restore_revision($request)
    {
        return $this->run('update', $request, function (Viewer $viewer) use ($request): \WP_REST_Response {
            $id = $this->id($request);
            $rid = (int) $request['rid'];
            $params = $request->get_json_params();
            if (!is_array($params)) {
                $params = $request->get_body_params();
            }
            [, $rev, $takeover] = Rev::split(is_array($params) ? $params : []);
            $row = Rev::guarded($this->collection, $id, $rev, $takeover, $viewer, fn (): array => $this->db()->restoreRevision($this->collection, $id, $rid, $viewer));

            return $this->prepare_item_for_response($row, $request);
        });
    }

    public function prepare_item_for_response($item, $request)
    {
        $data = PostMeta::values($this->collection, $item, array_map('strval', array_keys($item)));
        if (!empty($request['_fields'])) {
            $data = self::fields($data, self::fieldTree(wp_parse_list($request['_fields'])));
        }
        $response = rest_ensure_response($data);
        $fields = $this->get_fields_for_response($request);
        if (rest_is_field_included('_links', $fields) || rest_is_field_included('_embedded', $fields)) {
            $response->add_link('self', rest_url(sprintf('%s/%s/%s', $this->namespace, $this->rest_base, rawurlencode((string) ($item[$this->pk()] ?? '')))));
            $response->add_link('collection', rest_url(sprintf('%s/%s', $this->namespace, $this->rest_base)));
        }

        return $response;
    }

    public function get_item_schema()
    {
        if ($this->schema !== null) {
            return $this->add_additional_fields_schema($this->schema);
        }
        $row = JsonSchema::row($this->spec());
        $properties = [];
        foreach ($row['properties'] ?? [] as $name => $schema) {
            $properties[$name] = $schema + ['context' => ['view', 'edit', 'embed']];
        }
        $this->schema = ['$schema' => 'http://json-schema.org/draft-04/schema#', 'title' => $this->rest_base, 'type' => 'object', 'properties' => $properties];

        return $this->add_additional_fields_schema($this->schema);
    }

    public function get_collection_params()
    {
        $params = parent::get_collection_params();
        // A table's text fields are searchable too (Search\Index): core's own `search` param, as SpacePress reads it.
        $params['search'] = ['description' => 'Words to find, each at the start of a word, in the text fields the caller may read.', 'type' => 'string'];
        $params['per_page']['maximum'] = 100;
        $params['offset'] = ['description' => 'Rows to skip; replaces `page`.', 'type' => 'integer', 'minimum' => 0];

        return $params + [
            'where' => ['description' => 'A SpacePress filter, as JSON: `{"email":{"like":"%@example.com"}}`.', 'type' => 'string'],
            'orderBy' => ['description' => 'SpacePress fields to sort by, comma-separated; `-field` sorts descending.', 'type' => 'string'],
            'with' => ['description' => 'Relations to attach: `team,signups` or a JSON object.', 'type' => 'string'],
            'deleted' => ['description' => 'Soft-delete filter: exclude (default), include, or only.', 'type' => 'string', 'enum' => ['exclude', 'include', 'only']],
        ] + ((Plugin::instance()?->schema->collection($this->collection)['localized'] ?? false)
            ? ['locale' => ['description' => 'A SpacePress locale, or `*`: that locale\'s rows, else each translation group\'s fallback.', 'type' => 'string']]
            : []);
    }

    // ================================================================ helpers

    /**
     * `_fields` as core reads it (`a`, `a.b`; a parent named whole wins over its sub-paths), as a tree.
     * Core's own filter runs again after this one but only recurses into PHP arrays; First rows keep
     * their objects (`{}` stays an object), so the nested paths are applied here.
     */
    private static function fieldTree(array $fields): array
    {
        $tree = [];
        foreach (array_map('trim', $fields) as $field) {
            $ref = &$tree;
            $parts = explode('.', (string) $field);
            while (count($parts) > 1) {
                $next = array_shift($parts);
                if (($ref[$next] ?? null) === true) {
                    continue 2;
                }
                $ref[$next] ??= [];
                $ref = &$ref[$next];
            }
            $ref[array_shift($parts)] = true;
            unset($ref);
        }

        return $tree;
    }

    private static function fields(mixed $value, array $tree): mixed
    {
        if ($value instanceof \stdClass) {
            return (object) self::fields(get_object_vars($value), $tree);
        }
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value) && $value !== []) {
            return array_map(static fn ($item) => self::fields($item, $tree), $value);
        }
        $out = [];
        foreach (array_intersect_key($value, $tree) as $key => $v) {
            $out[$key] = $tree[$key] === true ? $v : self::fields($v, $tree[$key]);
        }

        return $out;
    }

    /**
     * One API operation: the kind must enable it, the account must not be suspended, and the
     * pipeline runs in one transaction; SpacePress's refusals become core's `WP_Error` with SpacePress's code,
     * status and pointer.
     */
    private function run(string $op, \WP_REST_Request $request, callable $fn): mixed
    {
        $plugin = Plugin::instance() ?? throw new \LogicException('No SpacePress app');
        if ($plugin->unavailable() !== null) {
            return new \WP_Error('sp_unavailable', Plugin::UNAVAILABLE, ['status' => 503]);
        }
        try {
            if (!($this->spec()['api'][$op] ?? false)) {
                throw new HttpError(405, 'method_not_allowed', "The $op operation is disabled for this collection");
            }
            $viewer = CoreAccess::viewer() ?? throw new HttpError(403, 'suspended', 'This account is suspended');
            $db = $this->db();

            return $db->transaction(function () use ($db, $viewer, $fn) {
                $previous = $db->actor();
                $db->setActor($viewer->user);
                try {
                    return $fn($viewer);
                } finally {
                    $db->setActor($previous);
                }
            });
        } catch (HttpError | \SpacePress\JsError $e) {
            $problem = App::problem($e);

            return CoreAccess::error(new HttpError($problem['status'], $problem['code'], $problem['detail'], $problem['pointer'] ?? null));
        }
    }

    /**
     * The request body: JSON as SpacePress reads it (`{}` stays an object inside json() values); a form
     * (urlencoded or multipart) as WordPress parsed it.
     */
    private function input(\WP_REST_Request $request): array
    {
        $body = (string) $request->get_body();
        if (!$request->is_json_content_type() && ($request->get_content_type() !== null || trim($body) === '')) {
            $params = $request->get_body_params();

            // WordPress's own request controls (the cookie nonce, method override) are not row fields.
            return is_array($params) ? array_diff_key($params, ['_wpnonce' => true, '_method' => true]) : [];
        }
        try {
            $decoded = \SpacePress\Seam::container(\SpacePress\Js::decodeJson($body));
        } catch (\JsonException $e) {
            throw new HttpError(400, 'invalid_json', 'Request body is not valid JSON: ' . $e->getMessage());
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw HttpError::invalidInput('Body must be a JSON object', '');
        }

        return $decoded;
    }

    private function id(\WP_REST_Request $request): int|string
    {
        $raw = (string) $request['id'];
        if ($this->spec()['columns'][$this->pk()]['type'] !== 'integer') {
            return $raw;
        }

        return preg_match('/^-?\d{1,18}$/D', $raw) ? (int) $raw : throw $this->missing($request);
    }

    private function missing(\WP_REST_Request $request): HttpError
    {
        return HttpError::notFound("No {$this->collection} row with {$this->pk()} {$request['id']}");
    }

    /** @return array<string, string> lower-cased request headers */
    private static function headers(\WP_REST_Request $request): array
    {
        $out = [];
        foreach ($request->get_headers() as $name => $values) {
            $out[str_replace('_', '-', strtolower((string) $name))] = implode(', ', (array) $values);
        }

        return $out;
    }

    private function pk(): string
    {
        return $this->spec()['primaryKey'];
    }

    private function spec(): array
    {
        return $this->db()->schema()->collection($this->collection);
    }

    private function db(): \SpacePress\Db
    {
        return Plugin::instance()?->db() ?? throw new \LogicException('No SpacePress app');
    }
}
