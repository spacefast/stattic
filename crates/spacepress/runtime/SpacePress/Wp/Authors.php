<?php

declare(strict_types=1);

namespace SpacePress\Wp;

use SpacePress\Access;
use SpacePress\Schema;
use SpacePress\Viewer;

/**
 * The site's users aren't listed to the public (spec.md §6). WordPress ships with several ways for a
 * visitor to enumerate them, each showing a login or the display name WordPress fills from it:
 * `/wp/v2/users` and `/wp/v2/users/<id>` (and every embed of a post's author, which dispatches the
 * same route), `?author=<id>` (which redirects to `/author/<login>/`), author archives and their
 * feeds and Markdown twins, the users sitemap, and the author fields of a post's oEmbed. While a SpacePress
 * app is active they are closed, unless the app makes its authors public: `publicAuthors: true` in the
 * config, or a users kind whose read rule lets anyone read users.
 *
 * A users kind whose read rule gives a signed-out visitor some users' rows makes those users public
 * authors, one by one ({@see visible()}): their archive, `?author=<id>`, sitemap entry (narrowed by
 * {@see Sitemap}) and oEmbed fields answer as WordPress ships them, and every other user's stay
 * closed. Core's `/wp/v2/users` stays closed either way; SpacePress's API serves the kind.
 *
 * Signed-in users keep core's answers on the REST routes (the block editor lists authors), and the
 * users kind's own rules decide what SpacePress's API shows ({@see UserMeta}). A guest session is not
 * signed in for this purpose: any visitor can start one. The front-end pages (archives, the sitemap,
 * oEmbed) answer the same for everyone, so a page cache can hold them. Nothing is written.
 */
final class Authors
{
    private const USERS = '#^/wp/v2/users(/|$)#i';

    private static ?bool $public = null;

    public static function apply(Schema $schema): void
    {
        add_filter('rest_pre_dispatch', static fn (mixed $result, mixed $server, mixed $request): mixed => self::route($schema, $result, $request), 5, 3);
        add_filter('request', static fn (array $vars): array => self::archive($schema, $vars), PHP_INT_MAX);
        // With a users kind the sitemap stays and lists only the users a visitor may read ({@see Sitemap}).
        add_filter('wp_sitemaps_add_provider', static fn (mixed $provider, string $name): mixed => $name === 'users' && !self::public($schema) && self::kind($schema) === null ? false : $provider, 10, 2);
        add_filter('oembed_response_data', static function (mixed $data, mixed $post = null) use ($schema): mixed {
            if (!is_array($data) || self::visible($schema, $post instanceof \WP_Post ? (int) $post->post_author : 0)) {
                return $data;
            }

            // Core's own answer for a post with no author: the site's name and address.
            return ['author_name' => get_bloginfo('name'), 'author_url' => get_home_url()] + $data;
        }, PHP_INT_MAX, 2);
        // The other places a theme prints a user's nicename, which WordPress derives from the login:
        // a registered commenter's `comment-author-<nicename>` class, and a byline's archive link.
        add_filter('comment_class', static fn (mixed $classes): mixed => !is_array($classes) || self::public($schema) ? $classes
            : array_values(array_filter($classes, static fn (mixed $class): bool => !is_string($class) || !str_starts_with($class, 'comment-author-'))), PHP_INT_MAX);
        add_filter('author_link', static fn (mixed $link, mixed $author = 0): mixed => self::$archiveUrl || is_admin() || self::visible($schema, (int) $author) ? $link : home_url('/'), PHP_INT_MAX, 2);
        // Whether a user's archive answers depends on their row: a write to it purges the archive.
        \SpacePress\Extensions::onWritten(static function (string $op, string $collection, array $row, ?array $previous = null) use ($schema): void {
            $id = (int) ($row['id'] ?? $previous['id'] ?? 0);
            if ($collection !== self::kind($schema) || $id <= 0) {
                return;
            }
            unset(self::$seen[$id]);
            Purges::announce([self::archiveUrl($id)]);
        });
    }

    /**
     * A user's real archive URL, whoever may see it: what a purge names. A page shows a hidden
     * user's link as the home page ({@see apply()}), but a cache may still hold the archive itself
     * from when the user was visible, or holds its 404.
     */
    public static function archiveUrl(int $user): string
    {
        self::$archiveUrl = true;
        try {
            return get_author_posts_url($user);
        } finally {
            self::$archiveUrl = false;
        }
    }

    /** True while {@see archiveUrl()} asks for the real URL. */
    private static bool $archiveUrl = false;
    /** @var array<int, bool> {@see visible()} by user id, for this request */
    private static array $seen = [];

    /** The app's users kind, or null when it has none. */
    private static function kind(Schema $schema): ?string
    {
        $users = $schema->auth()['collection'] ?? null;

        return is_string($users) ? $users : null;
    }

    /** Whether the app makes its authors public: the config's flag, or a users kind anyone may read. */
    public static function public(Schema $schema): bool
    {
        if (self::$public === null) {
            $users = self::kind($schema);
            $rule = $users === null ? null : Access::collectionRules($schema->collection($users))['read'];
            self::$public = $schema->publicAuthors()
                || ($rule !== null && Access::isShorthand($rule) && Access::evaluate($rule, new Viewer(null), 'read') === true);
        }

        return self::$public;
    }

    /**
     * Whether a signed-out visitor may see this user as an author: the app's authors are public, or
     * its users kind's read rule gives a visitor that user's row. While storage is behind the build
     * the rule can't be asked, so nobody is.
     */
    public static function visible(Schema $schema, int $user): bool
    {
        if (self::public($schema)) {
            return true;
        }
        $users = self::kind($schema);
        $plugin = Plugin::instance();
        if ($users === null || $user <= 0 || $plugin === null || $plugin->unavailable() !== null) {
            return false;
        }
        if (!isset(self::$seen[$user])) {
            try {
                self::$seen[$user] = $plugin->db()->findFirst($users, ['where' => ['id' => $user]], new Viewer(null)) !== null;
            } catch (\Throwable) {
                // The rule refuses signed-out visitors, or fails for one (a coded rule that reads a
                // user who isn't there): nobody is shown.
                self::$seen[$user] = false;
            }
        }

        return self::$seen[$user];
    }

    /**
     * Whether the caller is a visitor for this purpose: not signed in, or in a guest session, which
     * any visitor can start without credentials.
     */
    private static function visitor(): bool
    {
        return !is_user_logged_in() || in_array('sp_guest', (array) wp_get_current_user()->roles, true);
    }

    /**
     * rest_pre_dispatch: a visitor reads no user from core's routes, listed or one by one, directly,
     * through `?rest_route=`, a batch or an embed. Core answers a signed-out request for a user it
     * won't show this way. `OPTIONS` keeps core's route description, which names no user (a browser's
     * CORS preflight is one, sent without credentials).
     *
     * The answer is a response, not a WP_Error: core's batch endpoint hands this filter's result to
     * `rest_post_dispatch` callbacks that take responses only.
     */
    private static function route(Schema $schema, mixed $result, mixed $request): mixed
    {
        if ($result !== null || !$request instanceof \WP_REST_Request || !preg_match(self::USERS, $request->get_route())
            || $request->get_method() === 'OPTIONS' || !self::visitor() || self::public($schema) || self::ruled($schema, $request)) {
            return $result;
        }

        return rest_convert_error_to_response(new \WP_Error('rest_user_cannot_view', __('Sorry, you are not allowed to list users.'), ['status' => rest_authorization_required_code()]));
    }

    /**
     * Whether this is SpacePress's API reading users on a visitor's behalf, where SpacePress's own rules decide.
     *
     * A guest keeps its own record (`/users/me`, on core's route too), and with a users kind the
     * kind's rules decide for it on SpacePress's API as for any viewer. For a signed-out visitor, only reads of a users kind pass: a list SpacePress selected
     * by the kind's read rule (its own 401 when the rule needs a signed-in user, else the rows it gives
     * a signed-out viewer), and one user only when the rule gives that row to a signed-out viewer;
     * every other id, real or not, gets the same 401, so the answer doesn't say which ids exist.
     */
    private static function ruled(Schema $schema, \WP_REST_Request $request): bool
    {
        $route = strtolower(untrailingslashit($request->get_route()));
        if (is_user_logged_in() && $route === '/wp/v2/users/me') {
            return true; // a guest's own record, on core's route as on SpacePress's
        }
        if (!Facade::dispatching()) {
            return false;
        }
        if (is_user_logged_in()) {
            return self::kind($schema) !== null;
        }
        if (self::kind($schema) === null || $request->get_method() !== 'GET') {
            return false;
        }
        if ($route === '/wp/v2/users') {
            // The API's own list always carries `where`, which is what makes SpacePress select the rows.
            return $request->get_param('where') !== null;
        }

        return preg_match('#^/wp/v2/users/(\d+)$#', $route, $m) === 1 && self::visible($schema, (int) $m[1]);
    }

    /**
     * request: an author archive (`/author/<login>/`, `?author=<id>`, `?author_name=`, their feeds and
     * Markdown twins) is a 404, before core resolves it or redirects `?author=<id>` to the login,
     * unless it names one user a visitor may see as an author ({@see visible()}). Every other form
     * (a list of ids, an excluded id, a login nobody has) gets the same 404.
     *
     * Only front-end requests: `author` and `author_name` are public query vars, so WordPress copies
     * them into a REST request's vars too (`/wp/v2/posts?author=5`, a comment's `author_name`), where
     * they are that route's own parameters. An empty `author` or `0` is no author query, as in core.
     *
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    private static function archive(Schema $schema, array $vars): array
    {
        if (!empty($vars['rest_route']) || self::public($schema) || is_admin()) {
            return $vars;
        }
        $named = [];
        $author = $vars['author'] ?? '';
        if (!in_array($author, ['', '0', 0, null], true)) {
            $id = is_scalar($author) ? (string) $author : '';
            $named[] = ctype_digit($id) ? (int) $id : 0;
        }
        if (isset($vars['author_name']) && $vars['author_name'] !== '') {
            // As core reads it: the last segment of the path, as a user's nicename.
            $parts = is_string($vars['author_name']) ? array_values(array_filter(explode('/', $vars['author_name']), static fn (string $p): bool => $p !== '')) : [];
            $user = $parts === [] ? false : get_user_by('slug', sanitize_title_for_query($parts[count($parts) - 1]));
            $named[] = $user instanceof \WP_User ? (int) $user->ID : 0;
        }
        foreach ($named as $id) {
            if (!self::visible($schema, $id)) {
                return ['error' => '404'];
            }
        }

        return $vars;
    }
}
