<?php

declare(strict_types=1);

namespace TypedPress\Wp;

use TypedPress\HttpError;
use TypedPress\Js;
use TypedPress\Viewer;
use TypedPress\Wp\Abilities\JsonSchema;
use TypedPress\Wp\Media\Rest;

/**
 * Core `/wp/v2/users` for the users kind (spec §2.2, §3a): every member field is registered user meta
 * (`show_in_rest` under its own name, with its JSON Schema), read and written as TypedPress does.
 *
 * - Reads: `meta` holds the member row as TypedPress's read pipeline gives it to this viewer, so the read
 *   rule and field masks decide; a member the viewer can't read shows no member fields.
 * - Writes: member fields in `meta` are taken out of core's generic meta path and applied with
 *   `Db::update` as the viewer, in one transaction with core's own user save: the update rule, field
 *   rules, validation and hooks run, and a refusal rolls the whole request back.
 * - Lists: `where` and `orderBy` run through TypedPress as the viewer and narrow core's `include`.
 */
final class UserMeta
{
    private const CORE = ['email', 'name', 'role'];
    private const ROUTE = '#^/wp/v2/users(?:/(me|\d+))?$#i';

    /** @var ?array{request: \WP_REST_Request, fields: array<string, mixed>, error: ?HttpError, began: bool} */
    private static ?array $open = null;

    public static function register(): void
    {
        add_action('init', [self::class, 'registerMeta']);
        add_filter('rest_prepare_user', [self::class, 'prepare'], 20, 3);
        add_filter('rest_user_query', [self::class, 'query'], 20, 2);
        add_filter('rest_request_before_callbacks', [self::class, 'before'], 20, 3);
        add_filter('rest_dispatch_request', [self::class, 'dispatch'], 20, 4);
        add_filter('rest_request_before_callbacks', [self::class, 'readable'], 10, 3);
    }

    /**
     * rest_request_before_callbacks: reading a user runs core's check first (one's own row,
     * `list_users`, or an author of published posts), then TypedPress's read rule, which only narrows. On
     * the API's own dispatches a refusal is a 404, so a member's existence never leaks (§1.6); core's
     * own clients keep core's answers. Core runs its own check again after this one allows: both read
     * the same state, so the answer doesn't change.
     */
    public static function readable(mixed $response, mixed $handler, mixed $request): mixed
    {
        if ($response !== null || !$request instanceof \WP_REST_Request || !Rest::reads($handler)
            || preg_match('#^/wp/v2/users/(\d+)$#i', $request->get_route()) !== 1
            || !Facade::dispatching() || ($collection = self::collection()) === null) {
            return $response;
        }
        $allowed = call_user_func($handler['permission_callback'], $request);
        $missing = new \WP_Error('rest_user_invalid_id', __('Invalid user ID.'), ['status' => 404]);
        if ($allowed !== true) {
            // Signed out, the caller is asked to sign in (401), as TypedPress's read rule answers an
            // anonymous viewer; signed in, a user it may not read is a missing one. Core's own check
            // gives its answer when this returns nothing.
            if (get_current_user_id() === 0 || (is_wp_error($allowed) && $allowed->get_error_code() === 'rest_forbidden_context')) {
                return is_wp_error($allowed) ? $allowed : null;
            }

            return $missing;
        }
        $viewer = CoreAccess::viewer();
        try {
            $row = $viewer === null ? null : self::plugin()->db()->findFirst($collection, ['where' => ['id' => (int) $request['id']]], $viewer);
        } catch (HttpError $e) {
            return CoreAccess::error($e);
        }

        return $row !== null ? null : $missing;
    }

    public static function registerMeta(): void
    {
        foreach (self::fields() as $col => $def) {
            $schema = ['default' => null] + JsonSchema::column($def, true);
            $types = array_values(array_diff((array) $schema['type'], ['null']));
            register_meta('user', WpUserStore::META . $col, [
                'single' => true,
                'type' => count($types) === 1 ? $types[0] : 'object',
                'show_in_rest' => ['name' => $col, 'schema' => $schema, 'prepare_callback' => static fn () => null],
                // Never written through core's generic meta path (before() takes these out).
                'auth_callback' => '__return_false',
            ]);
        }
    }

    /** @return array<string, array> member fields kept in usermeta, by name */
    public static function fields(): array
    {
        $collection = self::collection();
        if ($collection === null) {
            return [];
        }
        $spec = self::plugin()->schema->collection($collection);

        return array_filter($spec['columns'], static fn (array $def, $col) => $col !== $spec['primaryKey']
            && !in_array($col, self::CORE, true) && !($def['system'] ?? false), ARRAY_FILTER_USE_BOTH);
    }

    // ================================================================ reads

    public static function prepare(mixed $response, mixed $user, mixed $request): mixed
    {
        $collection = self::collection();
        if ($collection === null || !$response instanceof \WP_REST_Response || !$user instanceof \WP_User
            || !is_array($data = $response->get_data()) || !is_array($data['meta'] ?? null)) {
            return $response;
        }
        $fields = self::fields();
        $viewer = CoreAccess::viewer();
        $row = null;
        if ($viewer !== null) {
            try {
                $row = self::plugin()->db()->findFirst($collection, ['where' => ['id' => (int) $user->ID]], $viewer);
            } catch (HttpError) {
                $row = null; // the read rule denies this viewer: no member fields
            }
        }
        $data['meta'] = array_diff_key($data['meta'], $fields);
        if ($row !== null) {
            $data['meta'] = PostMeta::values($collection, $row, array_keys(array_intersect_key($fields, $row))) + $data['meta'];
        }
        if (Facade::dispatching()) {
            // The facade's row is TypedPress's: the member as this viewer may read it (email and role
            // included, for one's own row too), or nothing when the read rule doesn't cover it.
            $data[Kinds::ROW] = $row === null ? null : (object) PostMeta::values($collection, $row, array_map('strval', array_keys($row)));
        }
        $response->set_data($data);

        return $response;
    }

    /** @var array{\WP_REST_Request, list<int>, bool, bool}|null the ids TypedPress selected for a users list: request, ids, ordered, searched */
    private static ?array $selected = null;

    /** `where`/`orderBy` on the users list: TypedPress selects the ids, core keeps its pagination. */
    public static function query(mixed $args, mixed $request): mixed
    {
        if (!is_array($args) || self::$selected === null || self::$selected[0] !== $request) {
            return $args;
        }
        [, $keys, $ordered, $searched] = self::$selected;
        $include = empty($args['include']) ? $keys : array_values(array_intersect($keys, array_map('intval', (array) $args['include'])));
        if ($searched) {
            // TypedPress's search picked the users, as this viewer reads them; core's own search over
            // login, email and name would match fields the viewer can't read.
            unset($args['search'], $args['search_columns']);
        }
        // One named elevation: core limits a list to authors of published posts for a viewer without
        // `list_users`, which drops a member's own row. When the ids come from TypedPress's read rule
        // (`self`, a filter), each one is a user this viewer may read on core's own item route
        // (one's own row) or by TypedPress's rule, so core's authors-only default is replaced by them.
        // Without a `where`/`orderBy`/read rule selection, core's default stays.
        unset($args['has_published_posts']);

        return ['include' => $include === [] ? [0] : $include] + ($ordered ? ['orderby' => 'include'] : []) + $args;
    }

    /** A users list with `where`/`orderBy`/`search`: run it through TypedPress as the viewer before core's query. */
    private static function select(\WP_REST_Request $request): ?\WP_Error
    {
        $collection = self::collection() ?? throw new \LogicException('No users kind');
        try {
            $keys = TypedPressQuery::keys($collection, $request, CoreAccess::viewer() ?? new Viewer(null), searched: $searched);
        } catch (HttpError $e) {
            return CoreAccess::error($e);
        }
        self::$selected = $keys === null ? null : [$request, $keys, $request['orderBy'] !== null, $searched];

        return null;
    }

    // ================================================================ writes

    /** A users list with `where`/`orderBy`: TypedPress selects the ids before core's query. */
    public static function before(mixed $response, mixed $handler, mixed $request): mixed
    {
        if ($response === null && $request instanceof \WP_REST_Request && $request->get_method() === 'GET'
            && strtolower($request->get_route()) === '/wp/v2/users' && self::collection() !== null) {
            return self::select($request);
        }

        return $response;
    }

    /**
     * rest_dispatch_request: a user create or update that carries member fields runs core's callback
     * inside TypedPress's transaction. Here, after core's permission check, nothing is open until the save
     * starts, and the callback runs under try/catch so a throw (core, another plugin's hook) can't
     * leave the transaction or this save open.
     */
    public static function dispatch(mixed $result, mixed $request, mixed $route, mixed $handler): mixed
    {
        if ($result !== null || !$request instanceof \WP_REST_Request || self::$open !== null || !is_array($handler)
            || !in_array($request->get_method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            || !preg_match(self::ROUTE, $request->get_route(), $m) || self::collection() === null) {
            return $result;
        }
        $id = isset($m[1]) ? (strtolower($m[1]) === 'me' ? get_current_user_id() : (int) $m[1]) : 0;
        $controller = is_array($handler['callback'] ?? null) ? ($handler['callback'][0] ?? null) : null;
        // A member is created and deleted by TypedPress's pipeline (create/delete rules, validation, hooks,
        // referential actions), after core's own permission check, in core's response shape.
        // Multisite users belong to the network (wpmu signup, add_user_to_blog, remove from blog): core's.
        if (is_multisite()) {
            $controller = null;
        }
        if ($controller instanceof \WP_REST_Users_Controller && $request->get_method() === 'DELETE') {
            return $id > 0 ? self::remove($id, $request, $controller) : $result;
        }
        if ($controller instanceof \WP_REST_Users_Controller && !isset($m[1])) {
            return self::create($request, $controller);
        }
        if ($request->get_method() === 'DELETE' || $id === 0 || !$controller instanceof \WP_REST_Users_Controller) {
            return $result;
        }
        $meta = PostMeta::input($request);
        $fields = is_array($meta) ? array_intersect_key($meta, self::fields()) : [];
        // The member's email, name and role are the users kind's fields too: an update applies them with
        // TypedPress's update (rules, field rules, hooks) like every other member field, never core alone. A
        // password stays core's (it re-issues the login cookie and notifies the account holder).
        $core = [];
        foreach (['email', 'name'] as $param) {
            if ($request->has_param($param) && $request->get_param($param) !== null) {
                $core[$param] = $request->get_param($param);
            }
        }
        // A role TypedPress knows is the member's `role`; another plugin's role stays core's (promote_user),
        // and the member's ladder role follows its capabilities (WpUserStore::syncRole).
        $roles = $request->get_param('roles');
        if (is_array($roles) && count($roles) === 1 && ($role = WpUserStore::firstRole((string) reset($roles))) !== null) {
            if (is_wp_error($denied = self::checkRoles($id, $roles))) {
                return $denied;
            }
            $core['role'] = $role;
        }
        if ($fields === [] && $core === []) {
            return $result;
        }
        $request->set_param('meta', array_diff_key((array) $request->get_param('meta'), $fields));
        foreach (['email' => 'email', 'name' => 'name', 'role' => 'roles'] as $col => $param) {
            if (array_key_exists($col, $core)) {
                unset($request[$param]);
            }
        }
        $fields = $core + $fields;
        $connection = self::plugin()->connection();
        $began = !$connection->inTransaction();
        if ($began) {
            $connection->begin();
        }
        self::$open = ['request' => $request, 'fields' => $fields, 'error' => null, 'began' => $began];
        try {
            // TypedPress decides before core writes anything.
            self::apply($id);
            if (self::$open['error'] !== null) {
                return self::finish(null);
            }
            $response = call_user_func($handler['callback'], $request);
        } catch (\Throwable $e) {
            self::$open = null;
            if ($began) {
                $connection->rollback();
            }
            throw $e; // inside an enclosing transaction, the throw is what rolls it back
        }

        return self::finish($response);
    }

    /**
     * `POST /wp/v2/users`: Db::insert as the viewer creates the member (create rule, field rules,
     * validation, hooks) from email, name, the role (`roles`), the password and member `meta`; core's
     * `username` becomes the login. Core's own update then applies the rest of the request (other
     * plugins' meta, description, ...) to the new user, in the same transaction.
     */
    private static function create(\WP_REST_Request $request, \WP_REST_Users_Controller $controller): mixed
    {
        $collection = self::collection() ?? throw new \LogicException('No users kind');
        $plugin = self::plugin();
        $meta = PostMeta::input($request);
        $data = is_array($meta) ? array_intersect_key($meta, self::fields()) : [];
        foreach (['email', 'name', 'password'] as $param) {
            if ($request->has_param($param) && $request->get_param($param) !== null) {
                $data[$param] = $request->get_param($param);
            }
        }
        $roles = $request->get_param('roles');
        if (is_array($roles) && $roles !== []) {
            if (is_wp_error($denied = self::checkRoles(0, $roles))) {
                return $denied;
            }
            $data['role'] = count($roles) === 1 ? WpUserStore::firstRole((string) reset($roles)) : null;
            if ($data['role'] === null) {
                return new \WP_Error('tp_invalid_input', 'A member has one of the roles TypedPress knows', ['status' => 422, 'pointer' => '/role']);
            }
        }
        try {
            $viewer = CoreAccess::viewer() ?? throw new HttpError(403, 'suspended', 'This account is suspended');

            $user = $plugin->db()->transaction(function () use ($plugin, $collection, $data, $viewer, $request, $controller, $meta): \WP_User {
                $id = (int) $plugin->db()->insert($collection, $data, true, $viewer)[0]['id'];
                $login = $request->get_param('username');
                if (is_string($login) && $login !== '') {
                    WpUserStore::setLogin($plugin->connection(), $id, $login);
                }
                // The rest of core's create_item, as it runs after wp_insert_user: its hooks (creating),
                // other plugins' registered meta, additional fields.
                $user = get_user_by('id', $id) ?: throw new \LogicException("User $id vanished");
                do_action('rest_insert_user', $user, $request, true);
                $others = is_array($meta) ? array_diff_key($meta, self::fields()) : [];
                if ($others !== []) {
                    $done = (new \WP_REST_User_Meta_Fields())->update_value($others, $id);
                    if (is_wp_error($done)) {
                        throw self::failure($done);
                    }
                }
                $user = get_user_by('id', $id);
                // Fields other plugins registered with register_rest_field: core runs them from its
                // protected update_additional_fields_for_object, and nothing public lists them
                // (docs/wordpress-internals.md).
                $done = (fn ($u, $r) => $this->update_additional_fields_for_object($u, $r))->call($controller, $user, $request);
                if (is_wp_error($done)) {
                    throw self::failure($done); // the member TypedPress created rolls back with it
                }
                do_action('rest_after_insert_user', $user, $request, true);

                return $user;
            });
        } catch (HttpError | \TypedPress\JsError $e) {
            return CoreAccess::error(self::problem($e));
        }
        $request->set_param('context', 'edit');
        $response = rest_ensure_response($controller->prepare_item_for_response($user, $request));
        $response->set_status(201);
        $response->header('Location', rest_url(sprintf('%s/%s/%d', 'wp/v2', 'users', $user->ID)));

        return $response;
    }

    /**
     * Core's role check for `roles` on a user (WP_REST_Users_Controller::check_role_update), with
     * public functions: the role exists, an admin can't give themselves a role without `edit_users`
     * (a multisite super admin can), and the caller may give it (`get_editable_roles()`).
     */
    private static function checkRoles(int $id, array $roles): true|\WP_Error
    {
        foreach ($roles as $role) {
            $potential = wp_roles()->get_role((string) $role);
            if ($potential === null) {
                /* translators: %s: Role key. */
                return new \WP_Error('rest_user_invalid_role', sprintf(__('The role %s does not exist.'), $role), ['status' => 400]);
            }
            if (!(is_multisite() && current_user_can('manage_sites')) && get_current_user_id() === $id && !$potential->has_cap('edit_users')) {
                return new \WP_Error('rest_user_invalid_role', __('Sorry, you are not allowed to give users that role.'), ['status' => rest_authorization_required_code()]);
            }
            require_once ABSPATH . 'wp-admin/includes/user.php';
            if (empty(get_editable_roles()[$role])) {
                return new \WP_Error('rest_user_invalid_role', __('Sorry, you are not allowed to give users that role.'), ['status' => 403]);
            }
        }

        return true;
    }

    /**
     * `DELETE /wp/v2/users/<id>`: Db::delete as the viewer. The member's posts are kept, unowned (or
     * reassigned to `reassign`), as every TypedPress delete keeps them; core would delete them with the user.
     */
    private static function remove(int $id, \WP_REST_Request $request, \WP_REST_Users_Controller $controller): mixed
    {
        if (!$request['force']) {
            return new \WP_Error('rest_trash_not_supported', "Users do not support trashing. Set 'force=true' to delete.", ['status' => 501]);
        }
        $user = get_userdata($id);
        if (!$user instanceof \WP_User) {
            return new \WP_Error('rest_user_invalid_id', __('Invalid user ID.'), ['status' => 404]);
        }
        $reassign = $request['reassign'] === false ? 0 : absint($request['reassign']);
        if ($reassign !== 0 && ($reassign === $id || !get_userdata($reassign))) {
            return new \WP_Error('rest_user_invalid_reassign', __('Invalid user ID for reassignment.'), ['status' => 400]);
        }
        $collection = self::collection() ?? throw new \LogicException('No users kind');
        $plugin = self::plugin();
        $request->set_param('context', 'edit');
        $previous = rest_ensure_response($controller->prepare_item_for_response($user, $request))->get_data();
        try {
            $viewer = CoreAccess::viewer() ?? throw new HttpError(403, 'suspended', 'This account is suspended');
            $deleted = $plugin->db()->transaction(function () use ($plugin, $collection, $id, $reassign, $viewer): array {
                if ($reassign !== 0) {
                    WpUserStore::reassignPosts($plugin->connection(), $id, $reassign);
                }

                // An empty result: the delete rule doesn't cover this member.
                return $plugin->db()->delete($collection, ['id' => $id], true, $viewer)[0] ?? throw HttpError::notFound("No $collection row with id $id");
            });
        } catch (HttpError | \TypedPress\JsError $e) {
            return CoreAccess::error(self::problem($e));
        }
        if (Facade::dispatching() && is_array($previous)) {
            $previous[Kinds::ROW] = (object) PostMeta::values($collection, $deleted, array_map('strval', array_keys($deleted)));
        }
        $response = rest_ensure_response(['deleted' => true, 'previous' => $previous]);
        do_action('rest_delete_user', $user, $response, $request); // core's own notice, as its delete fires it

        return $response;
    }

    private static function problem(\Throwable $e): HttpError
    {
        $problem = \TypedPress\App::problem($e);

        return new HttpError($problem['status'], $problem['code'], $problem['detail'], $problem['pointer'] ?? null);
    }

    private static function apply(int $id): void
    {
        $collection = self::collection() ?? throw new \LogicException('No users kind');
        try {
            $viewer = CoreAccess::viewer() ?? throw new HttpError(403, 'suspended', 'This account is suspended');
            $db = self::plugin()->db();
            $updated = $db->update($collection, self::$open['fields'], ['id' => $id], true, $viewer);
            if ($updated === []) {
                // The update rule doesn't cover this member: the facade's answer to the same write.
                throw HttpError::notFound("No $collection row with id $id");
            }
        } catch (HttpError | \TypedPress\JsError $e) {
            self::$open['error'] = self::problem($e);
        }
    }

    private static function finish(mixed $response): mixed
    {
        ['error' => $error, 'began' => $began] = self::$open;
        self::$open = null;
        $failed = $error !== null || is_wp_error($response)
            || ($response instanceof \WP_REST_Response && $response->get_status() >= 400);
        $connection = self::plugin()->connection();
        if ($failed) {
            if ($began) {
                $connection->rollback();
            } else {
                // Core or a plugin may fail after the user or member fields were written: the enclosing
                // transaction is the only rollback point, so its work is abandoned, not half-committed.
                throw $error ?? self::failure($response);
            }
        } elseif ($began) {
            $connection->commit();
        }

        return $error === null ? $response : CoreAccess::error($error);
    }

    /** A failed core response as TypedPress's error, to abort the enclosing transaction with. */
    private static function failure(mixed $response): HttpError
    {
        $error = $response instanceof \WP_Error ? $response : ($response instanceof \WP_REST_Response ? $response->as_error() : null);
        $data = $error?->get_error_data();
        $status = is_array($data) && is_int($data['status'] ?? null) ? $data['status'] : ($response instanceof \WP_REST_Response ? $response->get_status() : 500);

        return new HttpError($status, (string) ($error?->get_error_code() ?: 'rest_error'), (string) ($error?->get_error_message() ?: 'The user save failed'));
    }

    private static function collection(): ?string
    {
        return Plugin::instance()?->schema->auth()['collection'] ?? null;
    }

    private static function plugin(): Plugin
    {
        return Plugin::instance() ?? throw new \LogicException('No TypedPress app');
    }
}
