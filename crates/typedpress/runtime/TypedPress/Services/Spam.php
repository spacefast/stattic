<?php

declare(strict_types=1);

namespace TypedPress\Services;

use TypedPress\Wp\Platform;

/**
 * Akismet's comment-check for a form submission, modelled on the request Jetpack Forms makes: the
 * submission is a `contact-form` comment (Akismet's documented type), its first email field the
 * author's email, a `name` field the author, and every other text value the content
 * (https://akismet.com/developers/detailed-docs/comment-check/). Request headers are not sent
 * beyond the user agent and referrer.
 *
 * Runs only while Akismet has a key (Platform::spamChecks()), the same rule comment creates follow.
 * It fails open: an unreachable or unsure Akismet lets the submission through, and the honeypot and
 * rate window still apply.
 */
final class Spam
{
    /** @param array<string, array> $fields the form's fields @param array<string, mixed> $values validated input */
    public static function isSpam(array $fields, array $values): bool
    {
        if (!Platform::spamChecks()) {
            return false;
        }
        $referrer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $check = ['comment_type' => 'contact-form', 'blog' => get_option('home'), 'blog_lang' => get_locale(), 'blog_charset' => get_option('blog_charset'),
            'user_ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '', 'referrer' => $referrer, 'permalink' => $referrer,
            'comment_date_gmt' => gmdate(DATE_ATOM), 'honeypot_field_name' => 'website'];
        $user = wp_get_current_user();
        if ($user->exists()) {
            $check['user_role'] = implode(',', $user->roles);
        }
        $content = [];
        foreach ($fields as $key => $field) {
            $value = $values[$key] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }
            match (true) {
                ($field['type'] ?? '') === 'email' => $check['comment_author_email'] ??= $value,
                $key === 'name' => $check['comment_author'] = $value,
                default => $content[] = $value,
            };
        }
        $check['comment_content'] = implode("\n\n", $content);
        if (method_exists('Akismet', 'is_test_mode') && \Akismet::is_test_mode()) {
            $check['is_test'] = 'true';
        }
        $response = \Akismet::http_post(http_build_query($check, '', '&'), 'comment-check');

        return trim((string) ($response[1] ?? '')) === 'true';
    }
}
