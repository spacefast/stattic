<?php

declare(strict_types=1);

namespace TypedPress\Wp;

use TypedPress\HttpError;
use TypedPress\Viewer;

/**
 * Scopes on application passwords (docs/agents.md): what one agent credential may do, narrower than
 * the WordPress user it signs in as. A scope is kept on the application password's own entry, under
 * `tp_scope`:
 *
 *   write     false: the credential reads and never writes
 *   personal  false: the credential neither reads nor writes personal data: email fields, fields the
 *             config restricts with a read rule, and a WordPress account's own details
 *   abilities a list of ability names (`blog/posts-list`, `blog/*`): the only abilities it may run,
 *             and it reaches no route outside them; null means every ability and route the user has
 *
 * An application password with no `tp_scope` is unscoped and works as WordPress ships it: nothing
 * is added to existing passwords and none is narrowed. A password created through TypedPress's flows
 * (`typedpress identity agent add`, the Application Passwords form in wp-admin) starts as
 * `{write: false, personal: false}`; writes and personal data are each granted explicitly.
 *
 * A scope only ever narrows. The user's role, the kind's access rules and its field rules decide
 * first. And whatever its grants, a scoped credential can't do what would remove its own limits:
 * change application passwords, change the sign-in of the account it uses, or administer the site
 * (plugins, themes, settings, other users' roles).
 */
final class AgentScope
{
    public const KEY = 'tp_scope';
    /** The scope of a password created through TypedPress's flows. */
    public const DEFAULT = ['write' => false, 'personal' => false, 'abilities' => null];
    /** What a credential without `personal` sees of a WordPress user: what core shows anyone. */
    private const USER_PUBLIC = ['id', 'name', 'url', 'description', 'link', 'slug', 'avatar_urls', 'meta', '_links', Kinds::ROW];
    private const USERS = '#^/wp/v2/users(/|$)#i';
    private const PASSWORDS = '#^/wp/v2/users/[^/]+/application-passwords(/|$)#i';
    /**
     * Routes that read and change an account itself, whatever plugin serves them: TypedPress Identity
     * (emails, sessions, passkeys) and Two Factor (methods, recovery codes).
     */
    private const ACCOUNT = '#^/(typedpress-identity/v1|two-factor/[^/]+)(/|$)#i';
    /**
     * Site administration no scoped credential reaches, whatever its grants and its user's role: the
     * code the site runs (plugins, themes: deactivating TypedPress would remove the scope itself) and the
     * site's settings (which hold the administrator's email). An agent that needs these uses an
     * unscoped credential, which is a decision its owner makes on purpose.
     */
    private const ADMINISTRATION = '#^/wp/v2/(plugins|themes|settings|plugin-status|block-directory)(/|$)|^/wp-site-health/#i';
    /** Core abilities that return the site's or the user's account details. */
    private const ACCOUNT_ABILITIES = ['core/get-site-info' => true, 'core/get-user-info' => true, 'core/get-environment-info' => true];
    /**
     * Capabilities behind that administration, refused to a scoped credential on every route. Not
     * `manage_options`: TypedPress reads it to tell an administrator from an editor, and a scope doesn't
     * change which of TypedPress's access rules the user passes.
     */
    private const ADMIN_CAPS = ['activate_plugins', 'activate_plugin', 'deactivate_plugins', 'deactivate_plugin', 'install_plugins', 'delete_plugins', 'update_plugins',
        'edit_plugins', 'switch_themes', 'edit_themes', 'install_themes', 'delete_themes', 'update_themes', 'update_core',
        'create_users', 'promote_users', 'delete_users', 'remove_users'];
    /** Where an ability decides for itself whether it writes: core's run route and the MCP transports. */
    private const RUNS = '#^/(wp-abilities/v1/abilities/.+/run/?$|mcp(/|$))#i';
    private const READS = ['GET', 'HEAD', 'OPTIONS'];

    /** @var array<string, ?array{write: bool, personal: bool, abilities: ?list<string>}> by "user id:password uuid" */
    private static array $scopes = [];
    /** The scope this request signed in with, once known. */
    private static ?array $request = null;

    public static function register(): void
    {
        add_action('do_parse_request', [self::class, 'facade'], -1);
        add_filter('rest_pre_dispatch', [self::class, 'route'], 0, 3);
        add_filter('wp_pre_execute_ability', [self::class, 'ability'], 0, 4);
        add_filter('map_meta_cap', [self::class, 'capability'], PHP_INT_MAX, 2);
        add_filter('mcp_adapter_tools_list', [self::class, 'tools'], PHP_INT_MAX);
        add_filter('rest_prepare_user', [self::class, 'user'], PHP_INT_MAX);
        add_filter('rest_prepare_comment', [self::class, 'comment'], PHP_INT_MAX);
        add_filter('rest_user_query', [self::class, 'userQuery'], PHP_INT_MAX);
        // Personal fields are hidden from a credential without `personal` wherever a field rule hides one:
        // rows, filters, sorts, searches, relations, uniqueness checks, core's routes and wp-admin.
        \TypedPress\FieldAccess::withhold(static fn (array $def): bool => self::restricted($def) && !self::may('personal'));
        // A create has no stored row for the field rules to compare with: a credential without
        // `personal` sets no personal field on a new row either.
        \TypedPress\Extensions::onWrite(static function (string $op, string $collection, array &$data, ?array $existing, ?Viewer $viewer, array $provided): void {
            $schema = Plugin::instance()?->schema;
            if ($op === 'create' && $viewer !== null && !self::may('personal') && $schema !== null && $schema->hasCollection($collection)
                && array_intersect_key($provided, self::personal($schema->collection($collection))) !== []) {
                throw self::refusal('personal');
            }
        });
        // The users kind is the same accounts as core's users route, reached through TypedPress's own API
        // and its abilities: a scoped credential creates no user and sets no sign-in field there.
        \TypedPress\Extensions::onWrite(static function (string $op, string $collection, array &$data, ?array $existing, ?Viewer $viewer, array $provided): void {
            $schema = Plugin::instance()?->schema;
            if ($viewer === null || $op === 'delete' || self::current() === null || $schema === null || !$schema->hasCollection($collection)
                || ($schema->collection($collection)['auth'] ?? false) !== true) {
                return;
            }
            if ($op === 'create') {
                throw self::refusal('administration');
            }
            if (array_intersect_key($provided, ['email' => true, 'password' => true, 'role' => true]) !== []) {
                throw self::refusal('account');
            }
        });
        \TypedPress\Extensions::schemaDescribe(static function (array $description): array {
            if (($scope = self::current()) !== null) {
                $description['runtime']['credential'] = ['scope' => $scope];
            }

            return $description;
        });
        self::credentials();
    }

    /**
     * The scope of the application password that authenticated this request; null when the request is
     * unscoped (another kind of sign-in, or a password with no scope). Core records which password it
     * accepted, whenever in the request that happened, so no hook of TypedPress's has to be there first.
     */
    public static function current(): ?array
    {
        $user = wp_get_current_user(); // core authenticates on the first call
        $uuid = function_exists('rest_get_authenticated_app_password') ? rest_get_authenticated_app_password() : null;
        if (!is_string($uuid) || !$user->exists()) {
            return null;
        }
        // Per user as well: the password core recorded stays set when code switches the current user.
        $key = $user->ID . ':' . $uuid;
        if (!array_key_exists($key, self::$scopes)) {
            self::$scopes[$key] = self::stored(\WP_Application_Passwords::get_user_application_password($user->ID, $uuid));
        }
        // A request that began as a scoped credential keeps that scope whoever it then acts as.
        self::$request ??= self::$scopes[$key];

        return self::$scopes[$key] ?? self::$request;
    }

    /** The scope on one stored application password entry, or null when it has none. */
    private static function stored(mixed $item): ?array
    {
        return is_array($item) && array_key_exists(self::KEY, $item) ? self::normalize($item[self::KEY]) : null;
    }

    /** Whether this request's credential grants `write` or `personal`. An unscoped request has both. */
    public static function may(string $grant): bool
    {
        return (self::current()[$grant] ?? true) === true;
    }

    /**
     * A stored scope in its full form. A value that isn't a scope (edited by hand, written by other
     * code) is the narrowest one: a credential is never widened by a value nobody can read.
     *
     * @return array{write: bool, personal: bool, abilities: ?list<string>}
     */
    public static function normalize(mixed $stored): array
    {
        if (!is_array($stored)) {
            return ['write' => false, 'personal' => false, 'abilities' => []];
        }
        $abilities = $stored['abilities'] ?? null;

        return [
            'write' => ($stored['write'] ?? false) === true,
            'personal' => ($stored['personal'] ?? false) === true,
            'abilities' => $abilities === null ? null : array_values(array_unique(array_filter(is_array($abilities) ? $abilities : [], 'is_string'))),
        ];
    }

    public static function refusal(string $grant): HttpError
    {
        return new HttpError(403, 'scope', match ($grant) {
            'write' => 'This application password is read-only. Grant it writes (typedpress identity agent grant <user> <id> --write) or use another credential.',
            'personal' => "This application password has no access to personal data (emails, restricted fields, account details). Grant it (typedpress identity agent grant <user> <id> --personal) or use another credential.",
            'passwords' => "A scoped application password can't read or change application passwords.",
            'administration' => "A scoped application password can't administer the site: plugins, themes, settings and other users' accounts need an unscoped credential.",
            'account' => "A scoped application password can't change the account it signs in as: its password, email and sign-in methods.",
            default => "This application password is limited to a set of abilities, and this isn't one of them.",
        });
    }

    /** Core's shape of the same refusal (`tp_scope`; TypedPress's API answers `scope`). */
    private static function error(string $grant): \WP_Error
    {
        return new \WP_Error('tp_scope', self::refusal($grant)->getMessage(), ['status' => 403]);
    }

    /**
     * The same refusal as rest_pre_dispatch's answer. A response, not a WP_Error: core's batch endpoint
     * hands this filter's result to `rest_post_dispatch` callbacks that take responses only.
     */
    private static function refused(string $grant): \WP_REST_Response
    {
        return rest_convert_error_to_response(self::error($grant));
    }

    /**
     * wp_authenticate_application_password_errors, from the runtime: a scoped password signs in only
     * where its scope is enforced. That is a REST or API request while a TypedPress app is active; with no
     * app active (deactivated, or it failed to start), and over XML-RPC, it is refused, so a credential
     * is never wider than its scope because the app is away.
     */
    public static function authenticating(mixed $error, mixed $user, mixed $item): void
    {
        if ($error instanceof \WP_Error && is_array($item) && array_key_exists(self::KEY, $item)
            && (Runtime::plugin() === null || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST))) {
            self::$unenforced = new \WP_Error('tp_scope', 'This application password is scoped by TypedPress and can be used only on the REST API of a site with an active TypedPress app.', ['status' => 403]);
            $error->merge_from(self::$unenforced);
        }
    }

    /**
     * rest_authentication_errors: the refusal above, as the request's answer. Core can resolve the user
     * more than once in a request and keeps only its last answer, so the refusal is kept here; without
     * this the request would go on as a visitor, which is narrower but says nothing about why.
     */
    public static function unenforced(mixed $result): mixed
    {
        if (is_wp_error($result) || Runtime::plugin() !== null || !isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
            return $result;
        }
        // Core skipped application passwords when it first resolved the user (at `init`, before the
        // request was known to be REST): check this one now, as core's own REST resolve would.
        if (self::$unenforced === null && !is_user_logged_in()) {
            wp_authenticate_application_password(null, (string) $_SERVER['PHP_AUTH_USER'], (string) $_SERVER['PHP_AUTH_PW']);
        }

        return self::$unenforced ?? $result;
    }

    private static ?\WP_Error $unenforced = null;

    // ================================================================ routes

    /**
     * do_parse_request, before the facade serves `/__tp/api/v1`: its own features with no core
     * route (sign-in, sync, files, services) never reach {@see route()}, so the facade's method and
     * path are judged here. Kinds are judged again on the core route each one dispatches to.
     */
    public static function facade(mixed $parse): mixed
    {
        $path = Facade::path();
        $scope = $path === null ? null : self::current();
        if ($scope === null) {
            return $parse;
        }
        $segments = array_map('rawurldecode', array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== '')));
        $name = $segments[0] ?? '';
        // An action or a global is an ability: its own annotations say whether it writes.
        $ability = ($name === 'actions' && count($segments) === 2) || (count($segments) === 1 && isset(Plugin::instance()?->schema->globals()[$name]));
        $read = in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), self::READS, true);
        $refused = match (true) {
            $ability => null,
            $scope['abilities'] !== null && $name !== '' => 'abilities',
            !$scope['write'] && !$read => 'write',
            !$scope['personal'] && $name === 'auth' => 'personal',
            default => null,
        };
        if ($refused !== null) {
            $problem = \TypedPress\App::problem(self::refusal($refused));
            status_header($problem['status']);
            header('Content-Type: application/problem+json');
            header('Cache-Control: private, no-store');
            echo \TypedPress\Js::jsonStringify($problem);
            exit;
        }

        return $parse;
    }

    /**
     * rest_pre_dispatch: every REST request of a scoped credential and every internal dispatch made
     * for it (the facade's, the batch endpoint's sub-requests, an embed). Core has already applied
     * `_method` and `X-HTTP-Method-Override` to the method read here.
     */
    public static function route(mixed $result, mixed $server, mixed $request): mixed
    {
        $scope = self::current();
        if ($result !== null || $scope === null || !$request instanceof \WP_REST_Request) {
            return $result;
        }
        $route = $request->get_route();
        $read = in_array(strtoupper($request->get_method()), self::READS, true);
        if (preg_match(self::PASSWORDS, $route) && !($read && preg_match('#/application-passwords/introspect/?$#i', $route))) {
            return self::refused('passwords');
        }
        if (preg_match(self::ADMINISTRATION, $route)) {
            return self::refused('administration');
        }
        $runs = preg_match(self::RUNS, $route) === 1;
        if ($scope['abilities'] !== null && !$runs && !self::describes($route)) {
            return self::refused('abilities');
        }
        if (!$scope['write'] && !$read && !$runs) {
            return self::refused('write');
        }
        if (preg_match(self::USERS, $route) && !$read) {
            // The credential's own sign-in: a new password or email would be a way out of the scope.
            foreach (['password', 'email', 'username', 'roles', 'capabilities'] as $field) {
                if ($request->has_param($field)) {
                    return self::refused('account');
                }
            }
            if (!$scope['personal']) {
                return self::refused('personal');
            }
        }
        if (preg_match(self::ACCOUNT, $route)) {
            return self::refused($scope['personal'] ? 'account' : 'personal');
        }
        if (!$scope['personal'] && self::probes($request)) {
            return self::refused('personal');
        }

        return $result;
    }

    /** What an ability-limited credential still reads: the abilities it may run, and the app's schema. */
    private static function describes(string $route): bool
    {
        if (preg_match('#^/wp-abilities/v1/abilities/(.+?)/?$#i', $route, $m)) {
            return self::listed($m[1], self::current()['abilities'] ?? []);
        }

        return preg_match('#/application-passwords/introspect/?$#i', $route) === 1 || (Facade::dispatching() && Facade::path() === '');
    }

    /** A list query that would answer "which rows hold this personal value" without showing it. */
    private static function probes(\WP_REST_Request $request): bool
    {
        $route = strtolower(rtrim($request->get_route(), '/'));
        $given = static fn (mixed $v): bool => is_array($v) ? $v !== [] : $v !== null && $v !== '' && $v !== false;

        return match ($route) {
            // Sorting by, or filtering on, what a user row no longer shows would still tell it apart.
            '/wp/v2/users' => in_array($request['orderby'], ['email', 'registered_date'], true)
                || $given($request['roles']) || $given($request['capabilities']) || $given($request['who']),
            // Core's comment search reads the commenter's email, URL and address.
            '/wp/v2/comments' => $given($request['search']) || $given($request['author_email']),
            default => false,
        };
    }

    // ================================================================ abilities

    /**
     * wp_pre_execute_ability: where every ability is invoked (core's run route, the MCP adapter, the
     * facade's actions and globals, PHP called for the request). A write is an ability that doesn't
     * declare itself `readonly`. The trusted CLI has no application password, and so no scope.
     */
    public static function ability(mixed $pre, mixed $name, mixed $input, mixed $ability): mixed
    {
        $scope = self::current();
        if ($scope === null || !is_string($name) || !$ability instanceof \WP_Ability) {
            return $pre;
        }
        if (str_starts_with($name, 'mcp-adapter/')) {
            // The adapter's execute tool carries another ability's name, judged when that one runs. Its
            // discovery tools list every ability, which a credential limited to named ones doesn't see.
            return $scope['abilities'] !== null && $name !== 'mcp-adapter/execute-ability' ? self::error('abilities') : $pre;
        }
        if ($scope['abilities'] !== null && !self::listed($name, $scope['abilities'])) {
            return self::error('abilities');
        }
        if (!$scope['write'] && empty(((array) $ability->get_meta_item('annotations', []))['readonly'])) {
            return self::error('write');
        }
        if (!$scope['personal'] && (str_starts_with($name, 'typedpress-identity/') || isset(self::ACCOUNT_ABILITIES[$name]))) {
            return self::error('personal');
        }

        return $pre;
    }

    /** mcp_adapter_tools_list: a credential limited to named abilities is shown those tools only. */
    public static function tools(mixed $tools): mixed
    {
        $allowed = self::current()['abilities'] ?? null;
        if ($allowed === null || !is_array($tools)) {
            return $tools;
        }

        return array_values(array_filter($tools, static function (mixed $tool) use ($allowed): bool {
            $name = is_object($tool) && method_exists($tool, 'getName') ? (string) $tool->getName() : (is_array($tool) ? (string) ($tool['name'] ?? '') : '');
            foreach (wp_get_abilities() as $ability) {
                // The adapter names a tool after its ability, with the slash as a hyphen.
                if (str_replace('/', '-', $ability->get_name()) === $name) {
                    return self::listed($ability->get_name(), $allowed);
                }
            }

            return false; // the adapter's own discovery and execute tools
        }));
    }

    /**
     * map_meta_cap: the capabilities behind site administration are refused to a scoped credential on
     * every route that asks them, core's and any plugin's, not only the routes named above.
     */
    public static function capability(mixed $caps, mixed $cap): mixed
    {
        return is_string($cap) && in_array($cap, self::ADMIN_CAPS, true) && self::signedIn() !== null ? ['do_not_allow'] : $caps;
    }

    /**
     * The request's scope, for capability checks. Core asks capabilities while it is still resolving
     * the user, so this never resolves it: it reads what core has recorded so far.
     */
    private static function signedIn(): ?array
    {
        if (self::$request !== null) {
            return self::$request;
        }
        $uuid = function_exists('rest_get_authenticated_app_password') ? rest_get_authenticated_app_password() : null;
        $user = $GLOBALS['current_user'] ?? null;
        if (!is_string($uuid) || !$user instanceof \WP_User || !$user->exists()) {
            return null;
        }

        return self::$request = self::stored(\WP_Application_Passwords::get_user_application_password($user->ID, $uuid));
    }

    /** @param list<string> $allowed exact names, or `<namespace>/*` */
    private static function listed(string $name, array $allowed): bool
    {
        foreach ($allowed as $pattern) {
            if ($pattern === $name || (str_ends_with($pattern, '/*') && strlen($pattern) > 2 && str_starts_with($name, substr($pattern, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    // ================================================================ personal data

    /** rest_prepare_user: a credential without `personal` sees of any user what core shows anyone. */
    public static function user(mixed $response): mixed
    {
        if (self::may('personal') || !$response instanceof \WP_REST_Response || !is_array($data = $response->get_data())) {
            return $response;
        }
        $data = array_intersect_key($data, array_flip(self::USER_PUBLIC));
        if (isset($data['meta'])) {
            // Of registered user meta, only the users kind's fields that aren't personal.
            $users = Plugin::instance()?->schema->auth()['collection'] ?? null;
            $spec = $users === null ? null : Plugin::instance()->schema->collection($users);
            $data['meta'] = $spec === null ? [] : array_intersect_key((array) $data['meta'], array_diff_key($spec['columns'], self::personal($spec)));
        }
        $response->set_data($data);

        return $response;
    }

    /** rest_prepare_comment: a commenter's email and address are personal data. */
    public static function comment(mixed $response): mixed
    {
        if (!self::may('personal') && $response instanceof \WP_REST_Response && is_array($data = $response->get_data())) {
            $response->set_data(array_diff_key($data, ['author_email' => true, 'author_ip' => true, 'author_user_agent' => true]));
        }

        return $response;
    }

    /** rest_user_query: core's user search reads email and login; without `personal` it reads names. */
    public static function userQuery(mixed $args): mixed
    {
        if (!self::may('personal') && is_array($args) && isset($args['search'])) {
            $args['search_columns'] = ['display_name'];
        }

        return $args;
    }

    /**
     * The personal fields of a kind: every email field, and every field the config restricts with a
     * read rule. (A restricted sub-field is withheld on its own; the field around it is not personal.)
     *
     * @return array<string, true>
     */
    public static function personal(array $spec): array
    {
        return array_map(static fn (): bool => true, array_filter($spec['columns'], self::restricted(...)));
    }

    private static function restricted(array $def): bool
    {
        return ($def['type'] ?? '') === 'email' || isset($def['access']['read']);
    }

    // ================================================================ creating and changing credentials

    /** Whether this request's logged-in cookie was valid (`auth_cookie_valid`, as core's REST cookie check reads it). */
    private static bool $cookie = false;

    /** Registered as the runtime loads, before any user is resolved. */
    public static function trackCookie(): void
    {
        add_action('auth_cookie_valid', static function (): void {
            self::$cookie = true;
        });
    }

    /** The Application Passwords forms in wp-admin, and core's REST route behind them. */
    private static function credentials(): void
    {
        // A password created from wp-admin (the browser's cookie) is scoped: as its form asked, else the
        // default. Any other client's create keeps core's behaviour unless it sends a scope.
        add_action('wp_create_application_password', static function (mixed $userId, mixed $item, mixed $password, mixed $args): void {
            $asked = is_array($args) ? ($args[self::KEY] ?? null) : null;
            // Core's own Application Passwords screens create through this REST route with the browser's
            // cookie; a password another plugin creates in PHP keeps core's behaviour.
            $form = self::$cookie && wp_is_serving_rest_request()
                && preg_match(self::PASSWORDS, (string) ($GLOBALS['wp']->query_vars['rest_route'] ?? '')) === 1;
            if (is_array($asked) || $form) {
                self::store((int) $userId, (string) ($item['uuid'] ?? ''), self::normalize((is_array($asked) ? $asked : []) + self::DEFAULT));
            }
        }, 10, 4);
        add_filter('rest_pre_insert_application_password', static function (mixed $prepared, mixed $request): mixed {
            if (is_object($prepared) && $request instanceof \WP_REST_Request && is_array($request[self::KEY])) {
                $prepared->{self::KEY} = $request[self::KEY];
            }

            return $prepared;
        }, 10, 2);
        add_action('rest_api_init', static function (): void {
            register_rest_field('application-password', self::KEY, [
                'get_callback' => static function (array $item, string $field, \WP_REST_Request $request): ?array {
                    // The stored entry: a create answers before core's own copy of the item has the scope.
                    $user = (int) ($request['user_id'] === 'me' || $request['user_id'] === null ? get_current_user_id() : $request['user_id']);
                    $stored = \WP_Application_Passwords::get_user_application_password($user, (string) ($item['uuid'] ?? '')) ?? $item;

                    return array_key_exists(self::KEY, $stored) ? self::normalize($stored[self::KEY]) : null;
                },
                'update_callback' => static function (mixed $value, mixed $item, string $field, \WP_REST_Request $request): bool|\WP_Error {
                    if (!isset($request['uuid'])) {
                        return true; // a new password's scope was stored as it was created
                    }
                    $user = (int) ($request['user_id'] === 'me' ? get_current_user_id() : $request['user_id']);

                    return self::store($user, (string) $request['uuid'], self::normalize($value));
                },
                'schema' => [
                    'description' => "What this application password may do. A password created in wp-admin without one is read-only with no access to personal data.",
                    'type' => ['object', 'null'],
                    'context' => ['view', 'edit', 'embed'],
                    'properties' => [
                        'write' => ['type' => 'boolean'],
                        'personal' => ['type' => 'boolean'],
                        'abilities' => ['type' => ['array', 'null'], 'items' => ['type' => 'string']],
                    ],
                ],
            ]);
        });
        add_action('wp_create_application_password_form', static fn () => self::form('wp_application_passwords_new_password_request'));
        add_action('wp_authorize_application_password_form', static fn () => self::form('wp_application_passwords_approve_app_request'));
        add_filter('manage_application-passwords-user_columns', static fn (array $columns): array => array_slice($columns, 0, 1, true) + [self::KEY => 'Scope'] + $columns);
        add_action('manage_application-passwords-user_custom_column', static function (string $column, array $item): void {
            if ($column === self::KEY) {
                echo esc_html(self::describe(array_key_exists(self::KEY, $item) ? self::normalize($item[self::KEY]) : null));
            }
        }, 10, 2);
        add_action('manage_application-passwords-user_custom_column_js_template', static function (string $column): void {
            if ($column === self::KEY) {
                echo "{{ data.tp_scope ? ( data.tp_scope.write ? 'Read and write' : 'Read-only' ) + '; ' + ( data.tp_scope.personal ? 'personal data' : 'no personal data' )"
                    . " + ( data.tp_scope.abilities ? '; abilities: ' + ( data.tp_scope.abilities.join( ', ' ) || 'none' ) : '' ) : 'Unscoped: everything the user can do' }}";
            }
        });
    }

    /** Record a scope on one application password entry (null removes it); core's own fields stay as they are. */
    public static function store(int $userId, string $uuid, ?array $scope): bool|\WP_Error
    {
        $passwords = \WP_Application_Passwords::get_user_application_passwords($userId);
        foreach ($passwords as $i => $item) {
            if (($item['uuid'] ?? null) === $uuid) {
                unset($passwords[$i][self::KEY]);
                if ($scope !== null) {
                    $passwords[$i][self::KEY] = $scope;
                }
                update_user_meta($userId, \WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, $passwords);

                return true;
            }
        }

        return new \WP_Error('application_password_not_found', __('Could not find an application password with that id.'), ['status' => 404]);
    }

    /**
     * `wp typedpress identity agent …` (docs/agents.md): create, list, re-scope and revoke agent credentials.
     * A new one is read-only with no personal data unless the flags say otherwise.
     *
     * @param list<string> $args after `agent`
     * @param array<string, string|true> $flags
     */
    public static function cli(array $args, array $flags): array
    {
        $fail = static fn (string $detail): \InvalidArgumentException => new \InvalidArgumentException($detail);
        $who = (string) ($args[1] ?? '');
        $user = ctype_digit($who) ? get_user_by('id', (int) $who) : get_user_by('login', $who);
        if (!$user instanceof \WP_User) {
            throw $fail('Usage: identity agent add <user> --name=<name> [--write] [--personal] [--abilities=<a,b>] | agent list <user> | agent grant <user> <id> [--write] [--personal] [--abilities=<a,b>] | agent revoke <user> <id>');
        }
        if (($flags['abilities'] ?? null) === true || ($flags['abilities'] ?? null) === '') {
            throw $fail('--abilities needs a list, such as --abilities=blog/posts-list,blog/posts-get; leave the flag out for every ability');
        }
        $scope = self::normalize([
            'write' => ($flags['write'] ?? false) === true,
            'personal' => ($flags['personal'] ?? false) === true,
            'abilities' => isset($flags['abilities']) && is_string($flags['abilities']) ? array_filter(array_map('trim', explode(',', $flags['abilities']))) : null,
        ]);
        $public = static fn (array $item): array => [
            'id' => $item['uuid'], 'name' => $item['name'], 'user' => $user->user_login,
            'scope' => array_key_exists(self::KEY, $item) ? self::normalize($item[self::KEY]) : null,
            'lastUsed' => isset($item['last_used']) ? gmdate('c', (int) $item['last_used']) : null,
        ];
        $find = static fn (): array => \WP_Application_Passwords::get_user_application_password($user->ID, (string) ($args[2] ?? ''))
            ?? throw $fail("No application password {$args[2]} for {$user->user_login}");
        switch ($args[0] ?? '') {
            case 'add':
                if (!wp_is_application_passwords_available_for_user($user)) {
                    throw $fail("Application passwords are not available for {$user->user_login}");
                }
                $created = \WP_Application_Passwords::create_new_application_password($user->ID, ['name' => (string) ($flags['name'] ?? ''), self::KEY => $scope]);
                if (is_wp_error($created)) {
                    throw $fail($created->get_error_message());
                }
                self::store($user->ID, $created[1]['uuid'], $scope);

                // The password is shown this once, as WordPress shows it.
                return ['password' => \WP_Application_Passwords::chunk_password($created[0])] + $public([self::KEY => $scope] + $created[1]);
            case 'list':
                return array_map($public, \WP_Application_Passwords::get_user_application_passwords($user->ID));
            case 'grant':
                $item = $find();
                self::store($user->ID, $item['uuid'], $scope);

                return $public([self::KEY => $scope] + $item);
            case 'revoke':
                $item = $find();
                \WP_Application_Passwords::delete_application_password($user->ID, $item['uuid']);

                return ['revoked' => $item['uuid']];
            default:
                throw $fail('Unknown agent command; use add, list, grant or revoke');
        }
    }

    public static function describe(?array $scope): string
    {
        if ($scope === null) {
            return 'Unscoped: everything the user can do';
        }
        $parts = [$scope['write'] ? 'Read and write' : 'Read-only', $scope['personal'] ? 'personal data' : 'no personal data'];
        if ($scope['abilities'] !== null) {
            $parts[] = 'abilities: ' . ($scope['abilities'] === [] ? 'none' : implode(', ', $scope['abilities']));
        }

        return implode('; ', $parts);
    }

    /** The explicit steps that grant writes and personal data, added to core's form; $filter is the form script's own hook. */
    private static function form(string $filter): void
    {
        ?>
        <div class="form-field typedpress-scope">
            <p class="description">A new application password is <strong>read-only</strong> and has no access to personal data. Grant more only to an agent that needs it:</p>
            <p><label><input type="checkbox" id="typedpress-scope-write" /> Allow writes (create, update and delete as this user)</label></p>
            <p><label><input type="checkbox" id="typedpress-scope-personal" /> Allow personal data (emails, restricted fields, account details)</label></p>
            <p><label for="typedpress-scope-abilities">Limit to these abilities (optional, one per line, such as <code>blog/posts-list</code> or <code>blog/*</code>)</label><br />
                <textarea id="typedpress-scope-abilities" rows="3" class="large-text code"></textarea></p>
        </div>
        <script>
        // Core's form script loads in the footer; it asks this filter for the request when the button is pressed.
        document.addEventListener('DOMContentLoaded', function () {
            wp.hooks.addFilter(<?php echo wp_json_encode($filter); ?>, 'tp/scope', function (request) {
                var abilities = document.getElementById('typedpress-scope-abilities').value.split(/\s+/).filter(Boolean);
                request[<?php echo wp_json_encode(self::KEY); ?>] = {
                    write: document.getElementById('typedpress-scope-write').checked,
                    personal: document.getElementById('typedpress-scope-personal').checked,
                    abilities: abilities.length ? abilities : null
                };
                return request;
            });
        });
        </script>
        <?php
    }
}
