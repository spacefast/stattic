<?php

declare(strict_types=1);

namespace TypedPress\Render;

use TypedPress\Html;
use TypedPress\JsObject;
use TypedPress\Wp\Comments;

/**
 * `<Comments for={item} />` (docs/decisions/2026-11-14-comments.md): the item's thread as the visitor
 * may read it, and a form that posts a comment without JavaScript.
 *
 * - The thread is approved comments, plus the visitor's own held ones marked as awaiting
 *   moderation, nested by reply. It is read only when the visitor may read the item: the page
 *   already read the item as the visitor, and this checks again, so a thread is never shown for
 *   an item the visitor can't see.
 * - A signed-out visitor sees the thread and a sign-in link, not a form. A signed-in visitor gets a
 *   form that posts form-encoded to `POST /wp-json/typedpress/v1/comments` ({@see Forms}), and a reply
 *   form under each comment that may take a reply. The policy itself ({@see Comments}) is enforced
 *   where comments are created, never here.
 * - The page shows a signed-in visitor's held comment and the form's flash, so it is never shared:
 *   a signed-in render already is `private, no-store`, and a signed-out one shows only approved
 *   comments, the same for everyone.
 */
final class CommentsView
{
    public static function render(string $collection, mixed $item, ?string $class): Html
    {
        $id = (int) ($item instanceof JsObject ? ($item->p['id'] ?? 0) : (is_array($item) ? ($item['id'] ?? 0) : 0));
        $viewer = \TypedPress\Wp\CoreAccess::viewer();
        if ($id <= 0 || $viewer === null || !\TypedPress\Wp\CoreAccess::covers($collection, 'read', $id, $viewer)) {
            return Html::raw('');
        }
        $userId = (int) ($viewer->user['id'] ?? 0);
        // The thread is core's comment query, which no TypedPress counter follows: a live region around
        // it is rendered and compared on every poll, so a new approved comment shows on the next one.
        Live::unseen();
        // A live region around the thread asks again when a comment's approval changes (Comments::seen).
        Live::reads(\TypedPress\Wp\Comments::NOTICE);
        $rows = Comments::thread($id, $userId);
        $depth = Comments::policy($collection)['threaded'];
        $byParent = [];
        foreach ($rows as $row) {
            $byParent[(int) ($row['parent'] ?? 0)][] = $row;
        }
        $state = Forms::state('comments');
        $error = $state->p['error'] instanceof JsObject ? (string) $state->p['error']->p['message'] : null;
        $posted = $state->p['result'] instanceof JsObject ? (string) ($state->p['result']->p['status'] ?? '') : null;
        $count = count(array_filter($rows, static fn (array $r): bool => $r['status'] === 'approved'));

        return Html::element('section', ['className' => trim('typedpress-comments ' . ($class ?? '')), 'aria-label' => __('Comments')],
            Html::element('h2', [], sprintf(_n('%d comment', '%d comments', $count), $count)),
            $error !== null ? Html::element('p', ['className' => 'typedpress-comments-error', 'role' => 'alert'], $error) : null,
            $posted === 'pending' ? Html::element('p', ['className' => 'typedpress-comments-held', 'role' => 'status'], __('Your comment is awaiting moderation.')) : null,
            self::list($byParent, 0, 1, $depth, $id, $userId),
            $userId > 0
                ? self::form($id, 0, __('Add a comment'))
                : Html::element('p', ['className' => 'typedpress-comments-sign-in'], Html::element('a', ['href' => wp_login_url((string) ($_SERVER['REQUEST_URI'] ?? '/'))], __('Sign in to comment'))),
        );
    }

    /** @param array<int, list<array<string, mixed>>> $byParent */
    private static function list(array $byParent, int $parent, int $level, int $depth, int $postId, int $userId): ?Html
    {
        $rows = $byParent[$parent] ?? [];
        if ($rows === []) {
            return null;
        }
        $items = [];
        foreach ($rows as $row) {
            $held = $row['status'] !== 'approved';
            $items[] = Html::element('li', ['id' => 'comment-' . $row['id'], 'className' => $held ? 'typedpress-comment typedpress-comment-held' : 'typedpress-comment'],
                Html::element('p', ['className' => 'typedpress-comment-meta'],
                    Html::element('strong', [], (string) $row['authorName']), ' ',
                    Html::element('time', ['dateTime' => (string) $row['createdAt']], wp_date(get_option('date_format') ?: 'Y-m-d', (int) strtotime((string) $row['createdAt']))),
                    $held ? Html::children(' ', Html::element('em', [], __('Awaiting moderation'))) : null,
                ),
                Html::element('div', ['className' => 'typedpress-comment-body'], Markup::kses(wpautop(esc_html((string) $row['content'])))),
                // A reply goes one level down: offered only where the policy has room, and not on a held comment.
                $userId > 0 && !$held && $level < $depth ? self::form($postId, (int) $row['id'], __('Reply')) : null,
                self::list($byParent, (int) $row['id'], $level + 1, $depth, $postId, $userId),
            );
        }

        return Html::element($parent === 0 ? 'ol' : 'ol', ['className' => $parent === 0 ? 'typedpress-comments-list' : 'typedpress-comments-replies'], ...$items);
    }

    private static function form(int $postId, int $parent, string $label): Html
    {
        $field = 'typedpress-comment-' . $postId . '-' . $parent;
        $form = Html::element('form', ['method' => 'post', 'action' => \TypedPress\Wp\Facade::url('/comments'), 'className' => $parent === 0 ? 'typedpress-comments-form' : 'typedpress-comments-reply'] + Forms::enhanced(),
            Html::element('input', ['type' => 'hidden', 'name' => 'on', 'value' => (string) $postId]),
            $parent > 0 ? Html::element('input', ['type' => 'hidden', 'name' => 'parent', 'value' => (string) $parent]) : null,
            Html::element('label', ['htmlFor' => $field], $label),
            Html::element('textarea', ['id' => $field, 'name' => 'content', 'required' => true, 'rows' => $parent === 0 ? 4 : 2]),
            Html::element('button', ['type' => 'submit'], $parent === 0 ? __('Post comment') : __('Post reply')),
            Forms::fields('comments'),
        );

        return $parent === 0 ? $form : Html::element('details', [], Html::element('summary', [], $label), $form);
    }
}
