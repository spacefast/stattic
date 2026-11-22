<?php

declare(strict_types=1);

namespace TypedPress\Wp;

/**
 * The comment policy of every kind with `comments` on (spec §2.3), enforced in one place on core's
 * public hooks, so the three ways a comment arrives get the same answer: `<Comments>`'s form, the
 * facade's `POST /wp-json/typedpress/v1/comments` (core's `/wp/v2/comments` under it), and core's
 * `wp-comments-post.php` behind the comment blocks of a WordPress-rendered site. Each one creates
 * through core's own path (`wp_new_comment` or the REST controller, both through
 * `wp_allow_comment`), so duplicate and flood checks, Akismet when the site has it, and
 * notifications behave as on any WordPress site.
 *
 * The policy is the app's, never the Discussion settings: a site admin changing those changes
 * nothing an app declared. Each kind's policy is its `comments` option ({@see policy()}); `true`
 * is the spec's defaults:
 * - signed-in users only unless `guests: true` (`rest_allow_anonymous_comments` follows `guests`,
 *   and a signed-out submission to a kind without guests is refused before it is saved); a guest
 *   gives a name and a valid email whatever `require_name_email` says;
 * - first-time moderation: a commenter's comment is approved when they already have an approved
 *   comment on the site, and held otherwise; users who may moderate are approved, as core does;
 * - threaded to depth 3: a reply below that is refused, not flattened, so a reply never shows
 *   somewhere its author didn't put it;
 * - never closed.
 *
 * Hooks: `pre_comment_on_post` (wp-comments-post.php, before anything is saved), `preprocess_comment`
 * (every path into `wp_new_comment`), `rest_pre_insert_comment` (the REST controller, which inserts
 * with `wp_insert_comment`), `pre_comment_approved` (core's moderation decision, last), and
 * `get_comment`-free reads through {@see visible()}.
 */
final class Comments
{
    /** @var array<string, string> post type => collection, for kinds with comments on */
    private static array $types = [];

    /** @var array<string, array{guests: bool, moderation: string, threaded: int, closeAfterDays: ?int, types: list<string>}> collection => policy */
    private static array $policies = [];

    public static function register(Plugin $plugin): void
    {
        foreach ($plugin->schema->collections() as $name => $collection) {
            if (($collection['comments'] ?? false) === true && isset($collection['wp']['postType'])) {
                self::$types[(string) $collection['wp']['postType']] = (string) $name;
                self::$policies[(string) $name] = $plugin->schema->commentPolicy((string) $name);
            }
        }
        if (self::$types === []) {
            return;
        }
        // What visitors see of a thread changed: the item's cached pages go. Core's own comment hooks,
        // so every path (the form, REST, wp-admin, wp-comments-post.php, other plugins) is covered.
        add_action('transition_comment_status', static function (mixed $new, mixed $old, mixed $comment): void {
            if ($comment instanceof \WP_Comment && self::visible((string) $old, (string) $new, $comment)) {
                self::seen($comment);
            }
        }, 10, 3);
        add_action('wp_insert_comment', static function (int $id, \WP_Comment $comment): void {
            if (self::visible('new', (string) $comment->comment_approved, $comment)) {
                self::seen($comment);
            }
        }, 10, 2);
        add_action('edit_comment', static function (int $id): void {
            $comment = get_comment($id);
            // An edit keeps the status (wp_set_comment_status reports the change itself): visible
            // only while approved.
            if ($comment instanceof \WP_Comment && self::visible((string) $comment->comment_approved, (string) $comment->comment_approved, $comment)) {
                self::seen($comment);
            }
        });
        add_action('delete_comment', static function (mixed $id, mixed $comment): void {
            if ($comment instanceof \WP_Comment && self::visible((string) $comment->comment_approved, 'deleted', $comment)) {
                self::seen($comment);
            }
        }, 10, 2);
        // wp-comments-post.php: refused before core reads the submission any further.
        add_action('pre_comment_on_post', static function (int $postId): void {
            $error = self::refusal($postId, (int) ($_POST['comment_parent'] ?? 0), 0, (string) ($_POST['author'] ?? ''), (string) ($_POST['email'] ?? ''));
            if ($error !== null) {
                wp_die('<p>' . esc_html($error->get_error_message()) . '</p>', esc_html__('Comment Submission Failure'), ['response' => (int) ($error->get_error_data()['status'] ?? 403), 'back_link' => true]);
            }
        }, PHP_INT_MAX);
        // Every path into wp_new_comment (core's form handler, other plugins calling it).
        add_filter('preprocess_comment', static function (array $data): array {
            $error = self::refusal((int) ($data['comment_post_ID'] ?? 0), (int) ($data['comment_parent'] ?? 0), (int) ($data['user_id'] ?? 0), (string) ($data['comment_author'] ?? ''), (string) ($data['comment_author_email'] ?? ''));
            if ($error !== null) {
                wp_die(esc_html($error->get_error_message()), esc_html__('Comment Submission Failure'), ['response' => (int) ($error->get_error_data()['status'] ?? 403)]);
            }
            return $data;
        }, PHP_INT_MAX);
        // The REST controller (the facade dispatches to it): its own WP_Error, so the API answers 4xx.
        add_filter('rest_pre_insert_comment', static function (mixed $prepared, \WP_REST_Request $request): mixed {
            if (!is_array($prepared) || ($prepared['comment_type'] ?? 'comment') === 'note') {
                return $prepared;
            }
            return self::refusal((int) ($prepared['comment_post_ID'] ?? 0), (int) ($prepared['comment_parent'] ?? 0), (int) ($prepared['user_id'] ?? 0), (string) ($prepared['comment_author'] ?? ''), (string) ($prepared['comment_author_email'] ?? '')) ?? $prepared;
        }, PHP_INT_MAX, 2);
        // `guests: true`: core's REST controller lets an anonymous visitor comment on that kind's items.
        add_filter('rest_allow_anonymous_comments', static function (mixed $allow, \WP_REST_Request $request): mixed {
            $collection = self::collection((int) $request->get_param('post'));
            return $collection === null ? $allow : self::policy($collection)['guests'];
        }, PHP_INT_MAX, 2);
        // Moderation, after core's own checks (the disallowed list still sends a comment to spam).
        add_filter('pre_comment_approved', static function (mixed $approved, array $data): mixed {
            $collection = self::collection((int) ($data['comment_post_ID'] ?? 0));
            if ($collection === null || is_wp_error($approved) || in_array($approved, ['spam', 'trash'], true)) {
                return $approved;
            }
            return self::moderate($collection, $data);
        }, PHP_INT_MAX, 2);
    }

    /**
     * Whether a comment event changes what visitors see of a thread: a comment on a kind with comments
     * on becomes approved, stops being approved (held, spam, trash, deleted), or is edited while
     * approved. A held or spam submission changes nothing a visitor sees, so it purges nothing and
     * announces nothing: an anonymous form post can't make every cached copy and every open page
     * render again.
     */
    public static function visible(string $from, string $to, \WP_Comment $comment): bool
    {
        if (self::collection((int) $comment->comment_post_ID) === null || ($comment->comment_type !== '' && $comment->comment_type !== 'comment')) {
            return false;
        }
        $approved = static fn (string $s): bool => in_array($s, ['1', 'approved', 'approve'], true);

        return $approved($from) || $approved($to);
    }

    /**
     * The change notice key for core comments: never a kind's export name, so it can't be mistaken
     * for an app collection's. Replaced when `wp.comments` is a kind announced through the write path.
     */
    public const NOTICE = 'wp:comments';

    /** A visible change to $comment's thread: its item's cached pages are purged, and live pages told. */
    private static function seen(\WP_Comment $comment): void
    {
        \TypedPress\Realtime\Module::changed(self::NOTICE, 'update');
        $post = get_post((int) $comment->comment_post_ID);
        if ($post instanceof \WP_Post && $post->post_status === 'publish') {
            // The item's own URL and comments feed, and every page that rendered this item: a
            // tp/app/ page can show the thread somewhere else, and a kind without a url has
            // only WordPress's query-string permalink, which no page is cached at. Pages that only
            // listed the kind keep their copy (a comment count there updates within the window).
            $collection = self::collection($post->ID);
            $urls = $collection === null ? [] : \TypedPress\Render\Cache::itemUrls($collection, $post->ID);
            $link = get_permalink($post);
            if (is_string($link)) {
                $urls[] = $link;
            }
            $urls[] = get_post_comments_feed_link($post->ID);
            Purges::announce($urls);
        }
    }

    /** The collection whose item $postId is, when that kind has comments on. */
    public static function collection(int $postId): ?string
    {
        $type = $postId > 0 ? get_post_type($postId) : false;

        return is_string($type) ? (self::$types[$type] ?? null) : null;
    }

    /**
     * A kind's comment policy: its `comments` option (spec §2.3), defaults filled
     * ({@see \TypedPress\Schema::commentPolicy()}).
     *
     * @return array{guests: bool, moderation: string, threaded: int, closeAfterDays: ?int, types: list<string>}
     */
    public static function policy(string $collection): array
    {
        return self::$policies[$collection]
            ?? ['guests' => false, 'moderation' => 'first-time', 'threaded' => 3, 'closeAfterDays' => null, 'types' => []];
    }

    /**
     * Why a comment on $postId (as a reply to $parent) is refused, or null when the policy allows it.
     * An anonymous comment on a `guests` kind needs a name and a valid email: the policy decides
     * that, never the Discussion setting `require_name_email`.
     */
    private static function refusal(int $postId, int $parent, int $userId = 0, string $name = '', string $email = ''): ?\WP_Error
    {
        $collection = self::collection($postId);
        if ($collection === null) {
            return null; // not a kind of the app's: Hardening's comments_open decides
        }
        $policy = self::policy($collection);
        $anonymous = max($userId, get_current_user_id()) === 0;
        if (!$policy['guests'] && $anonymous) {
            return new \WP_Error('tp_comment_sign_in', __('Sign in to comment.'), ['status' => 401]);
        }
        if ($anonymous && (trim($name) === '' || !is_email(trim($email)))) {
            return new \WP_Error('tp_comment_name_email', __('Please fill the required fields (name, email).'), ['status' => 400]);
        }
        if ($parent > 0) {
            $depth = 1;
            $comment = get_comment($parent);
            if (!$comment instanceof \WP_Comment || (int) $comment->comment_post_ID !== $postId) {
                return new \WP_Error('tp_comment_parent', __('The comment you reply to is not on this item.'), ['status' => 400]);
            }
            while ($comment instanceof \WP_Comment && (int) $comment->comment_parent > 0 && $depth < 64) {
                $depth++;
                $comment = get_comment((int) $comment->comment_parent);
            }
            // $depth is the parent's depth; the reply would be one below it.
            if ($depth + 1 > $policy['threaded']) {
                return new \WP_Error('tp_comment_depth', sprintf(__('Replies go %d levels deep here.'), $policy['threaded']), ['status' => 400]);
            }
        }
        if ($policy['closeAfterDays'] !== null) {
            $post = get_post($postId);
            if ($post instanceof \WP_Post && time() - (int) strtotime($post->post_date_gmt . ' UTC') > $policy['closeAfterDays'] * DAY_IN_SECONDS) {
                return new \WP_Error('tp_comment_closed', __('Comments are closed on this item.'), ['status' => 403]);
            }
        }
        return null;
    }

    /** The approval of a comment the policy allows: 1 approved, 0 held. */
    private static function moderate(string $collection, array $data): int
    {
        $userId = (int) ($data['user_id'] ?? 0);
        if ($userId > 0 && user_can($userId, 'moderate_comments')) {
            return 1;
        }
        return match (self::policy($collection)['moderation']) {
            'none' => 1,
            'all' => 0,
            // first-time: held until one of the commenter's comments has been approved anywhere on the site.
            default => $userId > 0 && get_comments(['user_id' => $userId, 'status' => 'approve', 'count' => true, 'number' => 1]) > 0 ? 1 : 0,
        };
    }

    /**
     * The thread of $postId as $userId may read it: approved comments, and the reader's own held
     * ones, oldest first, as rows `{ id, parent, author, authorName, content, status, createdAt }`.
     * The caller has already decided the reader may read the item.
     *
     * @return list<array<string, mixed>>
     */
    public static function thread(int $postId, int $userId): array
    {
        $args = ['post_id' => $postId, 'type' => 'comment', 'orderby' => 'comment_date_gmt', 'order' => 'ASC', 'update_comment_meta_cache' => false];
        $comments = get_comments($args + ['status' => 'approve']);
        if ($userId > 0) {
            $comments = [...$comments, ...get_comments($args + ['status' => 'hold', 'user_id' => $userId])];
            usort($comments, static fn (\WP_Comment $a, \WP_Comment $b): int => [$a->comment_date_gmt, (int) $a->comment_ID] <=> [$b->comment_date_gmt, (int) $b->comment_ID]);
        }
        return array_map(static fn (\WP_Comment $c): array => [
            'id' => (int) $c->comment_ID,
            'parent' => (int) $c->comment_parent ?: null,
            'author' => (int) $c->user_id ?: null,
            'authorName' => $c->comment_author,
            'content' => $c->comment_content,
            'status' => $c->comment_approved === '1' ? 'approved' : 'pending',
            'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z', (int) strtotime($c->comment_date_gmt . ' UTC')),
        ], $comments);
    }
}
