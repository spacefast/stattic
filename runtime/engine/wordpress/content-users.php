<?php
declare(strict_types=1);

/**
 * The users feature: a Space's WordPress users, reachable through WordPress's
 * own Abilities API.
 *
 * There is no Spacefast-side user service. A "user" here is a WordPress user
 * row on the Space's site — the same durable principal content-principals.php
 * establishes for an (issuer, subject) authority — and every ability below is a
 * plain call onto WP core (get_users, get_userdata, wp_update_user). The
 * Abilities API is the one dispatcher, so registering here is what makes these
 * callable by an agent through the MCP adapter and by the dashboard through
 * wp-abilities, with no second dispatch path to keep in step.
 *
 * Two rules this surface deliberately does not break:
 *
 *  - Authorization is not stored. The WordPress role is projected per request
 *    from the already-admitted Grant decision (content-principals.php's
 *    user_has_cap filter), so no ability here reads or writes a role. Who may
 *    do what changes through Grants, and these abilities answer to whatever
 *    WordPress capability that projection carries — `list_users`,
 *    `create_users`, `edit_user` — exactly as WordPress applies them.
 *
 *  - One authority, one user. Creating a user IS establishing the durable
 *    principal for an (issuer, subject) pair, through the same function the
 *    request-time lane uses, so the admin door and the sign-in door land on the
 *    same WordPress row rather than two accounts for one person.
 *
 * Space scoping is not optional: one wp.cloud site hosts many Spaces, so a user
 * belongs to the Spaces it has appeared in, recorded on the same
 * `_spacefast_space_id` meta key a Space's posts carry. Every read and write
 * below is fenced by that membership.
 */

const SPACEFAST_CONTENT_USERS_ABILITY_CATEGORY = 'zero-wp-users';
const SPACEFAST_CONTENT_USERS_PAGE_SIZE_MAX = 100;
const SPACEFAST_CONTENT_USERS_DEFAULT_PAGE_SIZE = 20;
const SPACEFAST_CONTENT_USERS_AUTHORITY_MAX_BYTES = 512;
const SPACEFAST_CONTENT_USERS_DISPLAY_NAME_MAX_BYTES = 250;

if (function_exists('add_action')) {
    // The Abilities API refuses registrations made anywhere else:
    // wp_register_ability_category and wp_register_ability each check
    // doing_action() for their own hook and return null otherwise.
    add_action('wp_abilities_api_categories_init', 'spacefast_content_users_register_ability_category');
    add_action('wp_abilities_api_init', 'spacefast_content_users_register_abilities');
}

/** Every Space this WordPress user has appeared in on this site. */
function spacefast_content_users_space_ids(int $userId): array
{
    if ($userId < 1 || !function_exists('get_user_meta')) {
        return [];
    }
    $values = get_user_meta($userId, SPACEFAST_CONTENT_SPACE_META, false);
    return is_array($values)
        ? array_values(array_filter($values, static fn (mixed $value): bool => is_string($value)))
        : [];
}

function spacefast_content_users_in_space(int $userId): bool
{
    $spaceId = spacefast_content_space_id();
    if ($spaceId === '') {
        return false;
    }
    foreach (spacefast_content_users_space_ids($userId) as $candidate) {
        if (hash_equals($spaceId, $candidate)) {
            return true;
        }
    }
    return false;
}

/**
 * Record that this durable principal is one of the request Space's users.
 *
 * Called from the principal lane the moment a WordPress user is established, so
 * membership follows whichever door the person came through. Additive: the same
 * authority reaching a second Space on the same site joins that one too, and
 * keeps the one user row.
 */
function spacefast_content_users_join_space(int $userId): void
{
    $spaceId = spacefast_content_space_id();
    if (
        $userId < 1
        || $spaceId === ''
        || !function_exists('add_user_meta')
        || spacefast_content_users_in_space($userId)
    ) {
        return;
    }
    add_user_meta($userId, SPACEFAST_CONTENT_SPACE_META, $spaceId);
}

/** The wire shape of one user: identity and authority, never authorization. */
function spacefast_content_users_projection(object $user): array
{
    $userId = (int) ($user->ID ?? 0);
    $authority = spacefast_content_principal_user_authority($userId);
    return [
        'id' => $userId,
        'login' => (string) ($user->user_login ?? ''),
        'displayName' => (string) ($user->display_name ?? ''),
        'principal' => $authority,
    ];
}

function spacefast_content_users_error(int $status, string $code, string $message): object
{
    return new WP_Error($code, $message, ['status' => $status]);
}

function spacefast_content_users_input(mixed $input): array
{
    return is_array($input) ? $input : [];
}

function spacefast_content_users_list(mixed $input): mixed
{
    $input = spacefast_content_users_input($input);
    $spaceId = spacefast_content_space_id();
    if ($spaceId === '' || !function_exists('get_users')) {
        return spacefast_content_users_error(503, 'zero_wp_users_unavailable', 'The Space user directory is unavailable.');
    }
    $page = (int) ($input['page'] ?? 1);
    $perPage = (int) ($input['perPage'] ?? SPACEFAST_CONTENT_USERS_DEFAULT_PAGE_SIZE);
    if ($page < 1 || $perPage < 1 || $perPage > SPACEFAST_CONTENT_USERS_PAGE_SIZE_MAX) {
        return spacefast_content_users_error(400, 'zero_wp_users_page_invalid', 'The user page request is out of range.');
    }
    $query = [
        'meta_key' => SPACEFAST_CONTENT_SPACE_META,
        'meta_value' => $spaceId,
        'number' => $perPage,
        'offset' => ($page - 1) * $perPage,
        'orderby' => 'ID',
        'order' => 'ASC',
    ];
    $search = trim((string) ($input['search'] ?? ''));
    if ($search !== '') {
        $query['search'] = '*' . $search . '*';
        $query['search_columns'] = ['user_login', 'display_name'];
    }
    $users = get_users($query);
    return [
        'page' => $page,
        'perPage' => $perPage,
        'users' => array_values(array_map(
            'spacefast_content_users_projection',
            array_filter(is_array($users) ? $users : [], static fn (mixed $user): bool => is_object($user))
        )),
    ];
}

function spacefast_content_users_resolve(mixed $input): object|false
{
    $userId = (int) (spacefast_content_users_input($input)['id'] ?? 0);
    if ($userId < 1 || !function_exists('get_userdata') || !spacefast_content_users_in_space($userId)) {
        return false;
    }
    $user = get_userdata($userId);
    return is_object($user) ? $user : false;
}

function spacefast_content_users_get(mixed $input): mixed
{
    $user = spacefast_content_users_resolve($input);
    return $user === false
        ? spacefast_content_users_error(404, 'zero_wp_users_not_found', 'No such user belongs to this Space.')
        : spacefast_content_users_projection($user);
}

function spacefast_content_users_create(mixed $input): mixed
{
    $input = spacefast_content_users_input($input);
    if (spacefast_content_space_id() === '') {
        return spacefast_content_users_error(503, 'zero_wp_users_unavailable', 'The Space user directory is unavailable.');
    }
    $issuer = trim((string) ($input['issuer'] ?? ''));
    $subject = trim((string) ($input['subject'] ?? ''));
    if (
        $issuer === ''
        || $subject === ''
        || strlen($issuer) > SPACEFAST_CONTENT_USERS_AUTHORITY_MAX_BYTES
        || strlen($subject) > SPACEFAST_CONTENT_USERS_AUTHORITY_MAX_BYTES
    ) {
        return spacefast_content_users_error(400, 'zero_wp_users_authority_invalid', 'A user is created for an issuer and subject.');
    }
    $displayName = trim((string) ($input['displayName'] ?? ''));
    if (strlen($displayName) > SPACEFAST_CONTENT_USERS_DISPLAY_NAME_MAX_BYTES) {
        return spacefast_content_users_error(400, 'zero_wp_users_display_name_invalid', 'The display name is too long.');
    }
    // One site hosts many Spaces, so the authority may already have a WordPress
    // user that belongs only to other Spaces. Create stays inside this Space's
    // fence, exactly as get and update do: it will not reach across to rename
    // that row or stamp this Space onto it. The row is shared the moment the
    // person actually signs in here (the request-time lane joins them then).
    $existingId = spacefast_content_principal_find_user(
        spacefast_content_principal_authority($issuer, $subject),
        $issuer,
        $subject
    );
    if ($existingId > 0 && !spacefast_content_users_in_space($existingId)) {
        return spacefast_content_users_error(
            409,
            'zero_wp_users_authority_elsewhere',
            'That identity already has a user on this site, outside this Space.'
        );
    }
    // The same call the request-time lane makes, so an authority that later
    // signs in to this Space arrives at the row created here. Profile sync is
    // off: create names a user it inserts, but never rewrites an existing one
    // (renaming a user in this Space is what the update Ability is for).
    $userId = spacefast_content_principal_ensure_user([
        'kind' => 'user',
        'issuer' => $issuer,
        'subject' => $subject,
        'principal_id' => spacefast_content_principal_authority($issuer, $subject),
        'profile' => $displayName === '' ? [] : ['display_name' => $displayName],
    ], false);
    if ($userId < 1) {
        return spacefast_content_users_error(503, 'zero_wp_users_create_failed', 'The user could not be created.');
    }
    $user = function_exists('get_userdata') ? get_userdata($userId) : false;
    return is_object($user)
        ? spacefast_content_users_projection($user)
        : spacefast_content_users_error(503, 'zero_wp_users_create_failed', 'The user could not be created.');
}

function spacefast_content_users_update(mixed $input): mixed
{
    $user = spacefast_content_users_resolve($input);
    if ($user === false) {
        return spacefast_content_users_error(404, 'zero_wp_users_not_found', 'No such user belongs to this Space.');
    }
    $input = spacefast_content_users_input($input);
    $displayName = trim((string) ($input['displayName'] ?? ''));
    if ($displayName === '' || strlen($displayName) > SPACEFAST_CONTENT_USERS_DISPLAY_NAME_MAX_BYTES) {
        return spacefast_content_users_error(400, 'zero_wp_users_display_name_invalid', 'A user update sets a display name.');
    }
    if (!function_exists('wp_update_user')) {
        return spacefast_content_users_error(503, 'zero_wp_users_unavailable', 'The Space user directory is unavailable.');
    }
    $updated = wp_update_user(['ID' => (int) $user->ID, 'display_name' => $displayName]);
    if (function_exists('is_wp_error') && is_wp_error($updated)) {
        return spacefast_content_users_error(503, 'zero_wp_users_update_failed', 'The user could not be updated.');
    }
    $refreshed = function_exists('get_userdata') ? get_userdata((int) $user->ID) : false;
    return spacefast_content_users_projection(is_object($refreshed) ? $refreshed : $user);
}

function spacefast_content_users_object_schema(array $properties, array $required = []): array
{
    return [
        'type' => 'object',
        'properties' => $properties,
        'required' => $required,
        'additionalProperties' => false,
    ];
}

function spacefast_content_users_projection_schema(): array
{
    return spacefast_content_users_object_schema([
        'id' => ['type' => 'integer'],
        'login' => ['type' => 'string'],
        'displayName' => ['type' => 'string'],
        'principal' => [
            'type' => ['object', 'null'],
            'properties' => [
                'kind' => ['type' => 'string'],
                'issuer' => ['type' => 'string'],
                'subject' => ['type' => 'string'],
            ],
        ],
    ], ['id', 'login', 'displayName', 'principal']);
}

/**
 * The `zero/wp/users/*` set, keyed by the name the Abilities API publishes each
 * one under.
 *
 * WP_Abilities_Registry::register() accepts `^[a-z0-9-]+/[a-z0-9-]+$` — one
 * namespace, one slug, no further slashes — so the set's `zero/wp/users/…`
 * name is spelled `zero/wp-users-…` on the wire. The MCP adapter renames the
 * separator again, publishing them as `zero-wp-users-…` tools.
 */
function spacefast_content_users_abilities(): array
{
    $user = spacefast_content_users_projection_schema();
    return [
        'zero/wp-users-list' => [
            'label' => 'List Space users',
            'description' => 'Lists the WordPress users belonging to this Space.',
            'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            // Listing takes no required input, and the Abilities API validates
            // a bare call's null input against this schema before executing it.
            'input_schema' => [
                'default' => [],
                ...spacefast_content_users_object_schema([
                    'page' => ['type' => 'integer', 'minimum' => 1],
                    'perPage' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => SPACEFAST_CONTENT_USERS_PAGE_SIZE_MAX,
                    ],
                    'search' => ['type' => 'string'],
                ]),
            ],
            'output_schema' => spacefast_content_users_object_schema([
                'page' => ['type' => 'integer'],
                'perPage' => ['type' => 'integer'],
                'users' => ['type' => 'array', 'items' => $user],
            ], ['page', 'perPage', 'users']),
            'permission_callback' => static fn (): bool => spacefast_content_users_may('list_users'),
            'execute_callback' => 'spacefast_content_users_list',
        ],
        'zero/wp-users-get' => [
            'label' => 'Get a Space user',
            'description' => 'Reads one WordPress user belonging to this Space.',
            'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            'input_schema' => spacefast_content_users_object_schema([
                'id' => ['type' => 'integer', 'minimum' => 1],
            ], ['id']),
            'output_schema' => $user,
            'permission_callback' => static fn (): bool => spacefast_content_users_may('list_users'),
            'execute_callback' => 'spacefast_content_users_get',
        ],
        'zero/wp-users-create' => [
            'label' => 'Create a Space user',
            'description' =>
                'Establishes the WordPress user for an (issuer, subject) authority and joins it to this Space. '
                . "Roles are not set here: a caller's WordPress role is projected from their Grants. "
                . 'Only this Space is touched: if the authority already has a user that belongs solely to other '
                . 'Spaces on the site, the call is refused rather than reaching across to it.',
            // Creating for an authority that already belongs to this Space
            // returns that same user unchanged, so repeating the call is a
            // no-op; it never rewrites an existing user's profile (that is the
            // update Ability), so it is not destructive.
            'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            'input_schema' => spacefast_content_users_object_schema([
                'issuer' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => SPACEFAST_CONTENT_USERS_AUTHORITY_MAX_BYTES,
                ],
                'subject' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => SPACEFAST_CONTENT_USERS_AUTHORITY_MAX_BYTES,
                ],
                'displayName' => [
                    'type' => 'string',
                    'maxLength' => SPACEFAST_CONTENT_USERS_DISPLAY_NAME_MAX_BYTES,
                ],
            ], ['issuer', 'subject']),
            'output_schema' => $user,
            'permission_callback' => static fn (): bool => spacefast_content_users_may('create_users'),
            'execute_callback' => 'spacefast_content_users_create',
        ],
        'zero/wp-users-update' => [
            'label' => 'Update a Space user',
            'description' => "Updates one WordPress user's profile in this Space.",
            'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
            'input_schema' => spacefast_content_users_object_schema([
                'id' => ['type' => 'integer', 'minimum' => 1],
                'displayName' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => SPACEFAST_CONTENT_USERS_DISPLAY_NAME_MAX_BYTES,
                ],
            ], ['id', 'displayName']),
            'output_schema' => $user,
            'permission_callback' => static fn (mixed $input): bool => spacefast_content_users_may(
                'edit_user',
                (int) (spacefast_content_users_input($input)['id'] ?? 0)
            ),
            'execute_callback' => 'spacefast_content_users_update',
        ],
    ];
}

/**
 * WordPress decides. current_user_can runs the projection content-principals.php
 * installs on user_has_cap, so the answer is this request's Grant-derived role
 * read through WordPress's own capability rules, never a parallel check.
 */
function spacefast_content_users_may(string $capability, ...$arguments): bool
{
    $access = $GLOBALS['SPACEFAST_CONTENT_ADMIN_ACCESS'] ?? null;
    if (
        is_array($access)
        && ($access['surface'] ?? null) === 'zero'
        && !in_array('users', $access['allowed_screens'] ?? [], true)
    ) {
        return false;
    }
    return function_exists('current_user_can') && current_user_can($capability, ...$arguments);
}

function spacefast_content_users_register_ability_category(): void
{
    if (function_exists('wp_register_ability_category') && spacefast_content_space_id() !== '') {
        wp_register_ability_category(SPACEFAST_CONTENT_USERS_ABILITY_CATEGORY, [
            'label' => 'Space users',
            'description' => "A Space's WordPress users.",
        ]);
    }
}

function spacefast_content_users_register_abilities(): void
{
    if (!function_exists('wp_register_ability') || spacefast_content_space_id() === '') {
        return;
    }
    foreach (spacefast_content_users_abilities() as $name => $ability) {
        wp_register_ability($name, [
            'label' => $ability['label'],
            'description' => $ability['description'],
            'category' => SPACEFAST_CONTENT_USERS_ABILITY_CATEGORY,
            'input_schema' => $ability['input_schema'],
            'output_schema' => $ability['output_schema'],
            'permission_callback' => $ability['permission_callback'],
            'execute_callback' => $ability['execute_callback'],
            // `public` is what carries these to clients — the REST surface and,
            // through the MCP adapter, agents. Agent parity is this one
            // registration rather than a second surface to keep in step.
            'meta' => ['public' => true, 'annotations' => $ability['annotations']],
        ]);
    }
}
