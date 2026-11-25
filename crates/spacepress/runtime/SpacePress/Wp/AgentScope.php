<?php

declare(strict_types=1);

namespace SpacePress\Wp;

use SpacePress\HttpError;
use SpacePress\Viewer;

/**
 * Scopes on application passwords (docs/agents.md): what one agent credential may do, narrower than
 * the WordPress user it signs in as. A scope is kept on the application password's own entry, under
 * `sp_scope`:
 *
 *   write     false: the credential reads and never writes
 *   personal  false: the credential neither reads nor writes personal data: email fields, fields the
 *             config restricts with a read rule, and a WordPress account's own details
 *   abilities a list of ability names (`blog/posts-list`, `blog/*`): the only abilities it may run,
 *             and it reaches no route outside them; null means every ability and route the user has
 *
 * An application password with no `sp_scope` is unscoped and works as WordPress ships it: nothing
 * is added to existing passwords and none is narrowed. A password created through SpacePress's flows
 * (`spacepress identity agent add`, the Application Passwords form in wp-admin) starts as
 * `{write: false, personal: false}`; writes and personal data are each granted explicitly.
 *
 * A scope only ever narrows. The user's role, the kind's access rules and its field rules decide
 * first. And whatever its grants, a scoped credential can't do what would remove its own limits:
 * change application passwords, change the sign-in of the account it uses, or administer the site
 * (plugins, themes, settings, other users' roles).
 */
final class AgentScope
{
    public const KEY = 'sp_scope';
    /** The scope of a password created through SpacePress's flows. */
    public const DEFAULT = ['write' => false, 'personal' => false, 'abilities' => null];
    /** What a credential without `personal` sees of a WordPress user: what core shows anyone. */
    private const USER_PUBLIC = ['id', 'name', 'url', 'description', 'link', 'slug', 'avatar_urls', 'meta', '_links', Kinds::ROW];
    private const USERS = '#^/wp/v2/users(/|$)#i';
    private const PASSWORDS = '#^/wp/v2/users/[^/]+/application-passwords(/|$)#i';
    /**
     * Routes that read and change an account itself, whatever plugin serves them: SpacePress Identity
     * (emails, sessions, passkeys) and Two Factor (methods, recovery codes).
     */
    private const ACCOUNT = '#^/(spacepress-identity/v1|two-factor/[^/]+)(/|$)#i';
    /**
     * Site administration no scoped credential reaches, whatever its grants and its user's role: the
     * code the site runs (plugins, themes: deactivating SpacePress would remove the scope itself) and the
     * site's settings (which hold the administrator's email). An agent that needs these uses an
     * unscoped credential, which is a decision its owner makes on purpose.
     */
    private const ADMINISTRATION = '#^/wp/v2/(plugins|themes|settings|plugin-status|block-directory)(/|$)|^/wp-site-health/#i';
    /** Core abilities that return the site's or the user's account details. */
    private const ACCOUNT_ABILITIES = ['core/get-site-info' => true, 'core/get-user-info' => true, 'core/get-environment-info' => true];
    /**
     * Capabilities behind that administration, refused to a scoped credential on every route. Not
     * `manage_options`: SpacePress reads it to tell an administrator from an editor, and a scope doesn't
     * change which of SpacePress's access rules the user passes.
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
        add_filter('rest_pre_dispatch', [self::class, 'route'], 0, 3);
        add_filter('wp_pre_execute_ability', [self::class, 'ability'], 0, 4);
        add_filter('map_meta_cap', [self::class, 'capability'], PHP_INT_MAX, 2);
        add_filter('wp_get_abilities_item_include', [self::class, 'included'], PHP_INT_MAX, 2);
        add_filter('mcp_adapter_tools_list', [self::class, 'tools'], PHP_INT_MAX);
        add_filter('rest_prepare_user', [self::class, 'user'], PHP_INT_MAX);
        add_filter('rest_prepare_comment', [self::class, 'comment'], PHP_INT_MAX);
        add_filter('rest_user_query', [self::class, 'userQuery'], PHP_INT_MAX);
        // Personal fields are hidden from a credential without `personal` wherever a field rule hides one:
        // rows, filters, sorts, searches, relations, uniqueness checks, core's routes and wp-admin.
        \SpacePress\FieldAccess::withhold(
            static fn (array $def): bool => self::restricted($def) && !self::may('personal'),
            static fn (): HttpError => self::refusal('personal'),
        );
        // A create has no stored row for the field rules to compare with: a credential without
        // `personal` sets no personal field on a new row either.
        \SpacePress\Extensions::onWrite(static function (string $op, string $collection, array &$data, ?array $existing, ?Viewer $viewer, array $provided): void {
            $schema = Plugin::instance()?->schema;
            if ($op === 'create' && $viewer !== null && !self::may('personal') && $schema !== null && $schema->hasCollection($collection)
                && array_intersect_key($provided, self::personal($schema->collection($collection))) !== []) {
                throw self::refusal('personal');
            }
        });
        // The users kind is the same accounts as core's users route, reached through SpacePress's own API
        // and its abilities: a scoped credential creates no user and sets no sign-in field there.
        \SpacePress\Extensions::onWrite(static function (string $op, string $collection, array &$data, ?array $existing, ?Viewer $viewer, array $provided): void {
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
        \SpacePress\Extensions::schemaDescribe(static function (array $description): array {
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
     * accepted, whenever in the request that happened, so no hook of SpacePress's has to be there first.
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

    /**
     * The scope on one stored application password entry, or null when it has none. Until the site has
     * moved its stored names ({@see BrandUpgrade}), a password scoped by a pre-release build still
     * carries its scope under the earlier key: it is read from there, so the move never widens it.
     */
    private static function stored(mixed $item): ?array
    {
        if (!is_array($item)) {
            return null;
        }
        $earlier = BrandUpgrade::earlierScope($item);
        if (array_key_exists(self::KEY, $item)) {
            // Both keys on one entry (a network part way through its move): the narrower of the two.
            return $earlier === null ? self::normalize($item[self::KEY]) : BrandUpgrade::narrower(self::normalize($item[self::KEY]), self::normalize($earlier));
        }

        return $earlier === null ? null : self::normalize($earlier);
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
            'write' => 'This application password is read-only. Grant it writes (spacepress identity agent grant <user> <id> --write) or use another credential.',
            'personal' => "This application password has no access to personal data (emails, restricted fields, account details). Grant it (spacepress identity agent grant <user> <id> --personal) or use another credential.",
            'passwords' => "A scoped application password can't read or change application passwords.",
            'administration' => "A scoped application password can't administer the site: plugins, themes, settings and other users' accounts need an unscoped credential.",
            'account' => "A scoped application password can't change the account it signs in as: its password, email and sign-in methods.",
            default => "This application password is limited to a set of abilities, and this isn't one of them.",
        });
    }

    /** Core's shape of the same refusal (`sp_scope`; SpacePress's API answers `scope`). */
    private static function error(string $grant): \WP_Error
    {
        return new \WP_Error('sp_scope', self::refusal($grant)->getMessage(), ['status' => 403]);
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
     * where its scope is enforced. That is a REST or API request while a SpacePress app is active; with no
     * app active (deactivated, or it failed to start), and over XML-RPC, it is refused, so a credential
     * is never wider than its scope because the app is away.
     */
    public static function authenticating(mixed $error, mixed $user, mixed $item): void
    {
        if ($error instanceof \WP_Error && is_array($item) && self::stored($item) !== null
            && (Runtime::plugin() === null || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST))) {
            self::$unenforced = new \WP_Error('sp_scope', 'This application password is scoped by SpacePress and can be used only on the REST API of a site with an active SpacePress app.', ['status' => 403]);
            $error->merge_from(self::$unenforced);
        }
    }

    /**
     * rest_authentication_errors, first: the refusal above can be from before the app booted, when
     * other code makes WordPress check application passwords while plugins are still loading. The
     * app is active now and enforces the scope, so that refusal is dropped and core resolves the
     * user again, this time as a REST request.
     */
    public static function booted(mixed $result): mixed
    {
        $user = $GLOBALS['current_user'] ?? null;
        if (self::$unenforced !== null && defined('REST_REQUEST') && REST_REQUEST && Runtime::plugin() !== null && !($user instanceof \WP_User && $user->exists())) {
            self::$unenforced = null;
            $GLOBALS['current_user'] = null;
            rest_application_password_collect_status(null);
        }

        return $result;
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
     * SpacePress's API, as core dispatches to it: its own features with no core route (sign-in,
     * sync, files) never reach another route, so the API's method and path are judged
     * here. Kinds are judged again on the core route each one dispatches to.
     */
    private static function facade(array $scope, string $path, bool $read): ?string
    {
        // The route is already decoded (Facade::decoded()): decoding again would read a different path than the API does.
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
        $name = $segments[0] ?? '';
        // An action or a global is an ability: its own annotations say whether it writes.
        $ability = ($name === 'actions' && count($segments) === 2) || (count($segments) === 1 && isset(Plugin::instance()?->schema->globals()[$name]));

        return match (true) {
            $ability => null,
            $scope['abilities'] !== null && $name !== '' => 'abilities',
            !$scope['write'] && !$read => 'write',
            !$scope['personal'] && $name === 'auth' => 'personal',
            default => null,
        };
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
        if (preg_match('#^/(wc/v[1-4]|wc-admin|wc-analytics|wc-push-notifications|wc-telemetry)(/|$)#i', $route)) { return self::refused('administration'); }
        // Woo also registers core REST controllers for its post types and taxonomies. These
        // must not bypass the app's native-store projection and whole-kind scope checks.
        if (preg_match('#^/wp/v2/(product|product_variation|product_cat|product_tag|product_shipping_class|shop_order|shop_order_refund)(/|$)#i', $route)) { return self::refused('administration'); }
        if (preg_match('#^/wp-abilities/v1/abilities/woocommerce/#i', $route)) { return self::refused('administration'); }
        // One ability's description: answered as its place in the lists is, with the grant it needs.
        if (preg_match('#^/wp-abilities/v1/abilities/(.+?)/?$#i', $route, $m) && wp_has_ability($m[1])
            && ($grant = self::withheld($scope, wp_get_ability($m[1]))) !== null) {
            return self::refused($grant);
        }
        $read = in_array(strtoupper($request->get_method()), self::READS, true);
        if (!Facade::dispatching() && ($path = Facade::below($route)) !== null) {
            $refused = self::facade($scope, $path, $read);

            return $refused === null ? $result : self::refused($refused);
        }
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
        if (str_starts_with($name, 'woocommerce/')) { return self::error('administration'); }
        if (!$scope['personal'] && self::orders($ability)) { return self::error('personal'); }
        if (str_starts_with($name, 'mcp-adapter/')) {
            // The adapter describes any ability by name: one this credential isn't shown is not described.
            $described = $name === 'mcp-adapter/get-ability-info' && is_array($input) && is_string($input['ability_name'] ?? null) ? $input['ability_name'] : null;
            if ($described !== null && wp_has_ability($described) && ($grant = self::withheld($scope, wp_get_ability($described))) !== null) {
                return self::error($grant);
            }
            // The adapter's execute tool carries another ability's name, judged when that one runs. Its
            // discovery tools list every ability, which a credential limited to named ones doesn't see.
            return $scope['abilities'] !== null && $name !== 'mcp-adapter/execute-ability' ? self::error('abilities') : $pre;
        }
        if ($scope['abilities'] !== null && !self::listed($name, $scope['abilities'])) {
            // An action's abilities.call() is judged here too, so the refusal names what to add.
            return new \WP_Error('sp_scope', "This application password is limited to a set of abilities, and `$name` isn't one of them.", ['status' => 403]);
        }
        if (!$scope['write'] && empty(((array) $ability->get_meta_item('annotations', []))['readonly'])) {
            return self::error('write');
        }
        if (!$scope['personal'] && (str_starts_with($name, 'spacepress-identity/') || isset(self::ACCOUNT_ABILITIES[$name]))) {
            return self::error('personal');
        }

        return $pre;
    }

    /** Whether $ability is an operation of an `order()` kind: orders are personal data as a whole. */
    private static function orders(\WP_Ability $ability): bool
    {
        $kind = ((array) $ability->get_meta_item('spacepress', []))['collection'] ?? null;
        $schema = Plugin::instance()?->schema;

        return is_string($kind) && $schema !== null && $schema->hasCollection($kind) && ($schema->collection($kind)['commerce'] ?? '') === 'order';
    }

    /**
     * The grant a scoped credential lacks to be shown $ability, or null when it is shown. The one
     * rule behind every place abilities are listed or described: core's list and single-ability
     * routes, the MCP adapter's `discover-abilities` and `get-ability-info`, and each server's
     * `tools/list`.
     *
     * WooCommerce's own abilities and the abilities of an order kind are refused when called
     * ({@see ability()}), so for them the listing and the call agree exactly.
     *
     * An action marked `personal` is different. The compiler marks it from the action's code, not
     * from what one call does: the handler, or a helper or action it calls, reads an order kind or
     * calls an ability that needs the grant, on any branch. The action is left out of the lists as a
     * whole, but a call to it is not refused as a whole: it runs until it reaches that read or that
     * ability, which is refused there. A call that takes a branch with neither still succeeds.
     *
     * @param array{write: bool, personal: bool, abilities: ?list<string>} $scope
     */
    private static function withheld(array $scope, \WP_Ability $ability): ?string
    {
        if (str_starts_with($ability->get_name(), 'woocommerce/')) {
            return 'administration';
        }
        $marked = ((array) $ability->get_meta_item('spacepress', []))['personal'] ?? false;

        return !$scope['personal'] && ($marked === true || self::orders($ability)) ? 'personal' : null;
    }

    /**
     * wp_get_abilities_item_include: every list core and the MCP adapter build from the registry
     * (`GET /wp-abilities/v1/abilities`, `discover-abilities`) leaves out what {@see withheld()} names,
     * before core pages the list.
     */
    public static function included(mixed $include, mixed $ability): mixed
    {
        $scope = $include && $ability instanceof \WP_Ability ? self::current() : null;

        return $scope === null ? $include : self::withheld($scope, $ability) === null;
    }

    /**
     * mcp_adapter_tools_list: a server's tools are named when it is built, not read from the filtered
     * registry, so the same rule is applied to them here. A credential limited to named abilities is
     * shown those tools only.
     */
    public static function tools(mixed $tools): mixed
    {
        $scope = self::current();
        if ($scope === null || !is_array($tools)) {
            return $tools;
        }
        // The adapter names a tool after its ability, with the slash as a hyphen. Read from the registry
        // itself: wp_get_abilities() has already left out what this credential isn't shown.
        $abilities = [];
        foreach (\WP_Abilities_Registry::get_instance()?->get_all_registered() ?? [] as $ability) {
            $abilities[str_replace('/', '-', $ability->get_name())][] = $ability;
        }

        return array_values(array_filter($tools, static function (mixed $tool) use ($scope, $abilities): bool {
            $name = is_object($tool) && method_exists($tool, 'getName') ? (string) $tool->getName() : (is_array($tool) ? (string) ($tool['name'] ?? '') : '');
            if (!isset($abilities[$name])) {
                // No ability behind it: nothing to judge it by, so a limited credential isn't shown it.
                return $scope['abilities'] === null && !str_starts_with($name, 'woocommerce-');
            }
            // Two ability names can spell one tool name: the tool is shown when every one of them is.
            foreach ($abilities[$name] as $ability) {
                if (self::withheld($scope, $ability) !== null || ($scope['abilities'] !== null && !self::listed($ability->get_name(), $scope['abilities']))) {
                    return false;
                }
            }

            return true;
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

                    return self::stored($stored);
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
                echo esc_html(self::describe(self::stored($item)));
            }
        }, 10, 2);
        add_action('manage_application-passwords-user_custom_column_js_template', static function (string $column): void {
            if ($column === self::KEY) {
                echo "{{ data.sp_scope ? ( data.sp_scope.write ? 'Read and write' : 'Read-only' ) + '; ' + ( data.sp_scope.personal ? 'personal data' : 'no personal data' )"
                    . " + ( data.sp_scope.abilities ? '; abilities: ' + ( data.sp_scope.abilities.join( ', ' ) || 'none' ) : '' ) : 'Unscoped: everything the user can do' }}";
            }
        });
    }

    /** Record a scope on one application password entry (null removes it); core's own fields stay as they are. */
    public static function store(int $userId, string $uuid, ?array $scope): bool|\WP_Error
    {
        $passwords = \WP_Application_Passwords::get_user_application_passwords($userId);
        foreach ($passwords as $i => $item) {
            if (($item['uuid'] ?? null) === $uuid) {
                // A new scope (or none) replaces the one a pre-release build stored there too.
                $passwords[$i] = BrandUpgrade::withEarlierScope($passwords[$i], $scope);
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
     * `wp spacepress identity agent …` (docs/agents.md): create, list, re-scope and revoke agent credentials.
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
            'scope' => self::stored($item),
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
        <div class="form-field spacepress-scope">
            <p class="description">A new application password is <strong>read-only</strong> and has no access to personal data. Grant more only to an agent that needs it:</p>
            <p><label><input type="checkbox" id="spacepress-scope-write" /> Allow writes (create, update and delete as this user)</label></p>
            <p><label><input type="checkbox" id="spacepress-scope-personal" /> Allow personal data (emails, restricted fields, account details)</label></p>
            <p><label for="spacepress-scope-abilities">Limit to these abilities (optional, one per line, such as <code>blog/posts-list</code> or <code>blog/*</code>)</label><br />
                <textarea id="spacepress-scope-abilities" rows="3" class="large-text code"></textarea></p>
        </div>
        <script>
        // Core's form script loads in the footer; it asks this filter for the request when the button is pressed.
        document.addEventListener('DOMContentLoaded', function () {
            wp.hooks.addFilter(<?php echo wp_json_encode($filter); ?>, 'sp/scope', function (request) {
                var abilities = document.getElementById('spacepress-scope-abilities').value.split(/\s+/).filter(Boolean);
                request[<?php echo wp_json_encode(self::KEY); ?>] = {
                    write: document.getElementById('spacepress-scope-write').checked,
                    personal: document.getElementById('spacepress-scope-personal').checked,
                    abilities: abilities.length ? abilities : null
                };
                return request;
            });
        });
        </script>
        <?php
    }
}
