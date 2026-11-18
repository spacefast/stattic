<?php

declare(strict_types=1);

namespace TypedPress\Render;

use TypedPress\Html;
use TypedPress\JsArray;
use TypedPress\JsObject;

/**
 * wp-login.php's sign-in view as `tp/app/login.tsx` renders it, and `<LoginForm />`
 * (docs/decisions/2026-11-15-one-sign-in-page.md).
 *
 * WordPress still runs the sign-in: the form posts to wp-login.php, so `authenticate`, the
 * loginErrors hardening, Identity's attempt limits and Two Factor's second step all apply as they
 * do to WordPress's own form. Only the markup of the view changes: {@see \TypedPress\Wp\AppRouter::login()}
 * takes the request after wp-login.php has decided what to show (`wp_login_errors`, the last step
 * before it prints its page) and renders the app's page instead.
 */
final class LoginView
{
    /**
     * What the view shows on this request, set by the router while it renders `login.tsx`; null
     * when `<LoginForm />` renders on any other page.
     *
     * @var ?array{errors: string, messages: string, errorList: list<string>, messageList: list<string>, login: string, remember: bool, redirectTo: string}
     */
    private static ?array $state = null;

    /** Whether this render printed what plugins add to the form (`login_form`): see {@see private()}. */
    private static bool $extra = false;

    /**
     * Whether this render carries per-request markup from `login_form` callbacks (a captcha
     * challenge, a nonce), which a shared cache must never serve to someone else.
     */
    public static function private(): bool
    {
        return self::$extra;
    }

    /**
     * Reads wp-login.php's answer into the view: its errors and messages as wp-login.php itself
     * prints them (each through `login_errors` / `login_messages`, the filters the loginErrors
     * hardening and other plugins use), the login to fill in, and where to go after.
     *
     * @return array{errors: string, messages: string, errorList: list<string>, messageList: list<string>, login: string, remember: bool, redirectTo: string}
     */
    public static function capture(\WP_Error $errors, string $redirectTo, string $login): array
    {
        $errorList = [];
        $messageList = [];
        foreach ($errors->get_error_codes() as $code) {
            $severity = $errors->get_error_data($code);
            foreach ($errors->get_error_messages($code) as $message) {
                if ($message === '') {
                    continue;
                }
                if ($severity === 'message') {
                    $messageList[] = (string) $message;
                } else {
                    $errorList[] = (string) $message;
                }
            }
        }
        // `login_message`: the notice plugins and themes put above the form, as login_header() shows it.
        $notice = (string) apply_filters('login_message', '');
        if (trim(wp_strip_all_tags($notice)) !== '') {
            array_unshift($messageList, $notice);
        }
        // As login_header() builds them.
        $errorsHtml = '';
        if ($errorList !== []) {
            $errorsHtml = count($errorList) > 1
                ? '<ul class="login-error-list">' . implode('', array_map(static fn (string $e): string => "<li>$e</li>", $errorList)) . '</ul>'
                : '<p>' . $errorList[0] . '</p>';
            $filtered = (string) apply_filters('login_errors', $errorsHtml);
            if ($filtered !== $errorsHtml) {
                // A filter rewrote them (the loginErrors hardening's one generic message): its text wins.
                $errorList = $filtered === '' ? [] : [$filtered];
            }
            $errorsHtml = $filtered;
        }
        $messagesHtml = '';
        if ($messageList !== []) {
            $messagesHtml = implode('', array_map(static fn (string $m): string => "<p>$m</p>", $messageList));
            $filtered = (string) apply_filters('login_messages', $messagesHtml);
            if ($filtered !== $messagesHtml) {
                $messageList = $filtered === '' ? [] : [$filtered];
            }
            $messagesHtml = $filtered;
        }
        return self::$state = [
            'errors' => $errorsHtml,
            'messages' => $messagesHtml,
            'errorList' => array_values(array_filter(array_map(self::text(...), $errorList), static fn (string $t): bool => $t !== '')),
            'messageList' => array_values(array_filter(array_map(self::text(...), $messageList), static fn (string $t): bool => $t !== '')),
            'login' => $login,
            'remember' => !empty($_POST['rememberme']),
            'redirectTo' => $redirectTo,
        ];
    }

    /** `login.tsx`'s props (types/typedpress/app.d.ts `LoginProps`). */
    public static function props(): JsObject
    {
        $state = self::$state ?? self::fresh();
        return new JsObject([
            'params' => new JsObject([]),
            'errors' => new JsArray($state['errorList']),
            'messages' => new JsArray($state['messageList']),
            'login' => $state['login'],
            'remember' => $state['remember'],
            'redirectTo' => $state['redirectTo'],
            'action' => self::action(),
            'lostPasswordUrl' => wp_lostpassword_url(),
            'registerUrl' => get_option('users_can_register') ? wp_registration_url() : null,
        ]);
    }

    /**
     * `<LoginForm className? submitLabel? notices? links? />`: the fields wp-login.php reads (`log`,
     * `pwd`, `rememberme`, `redirect_to`, `testcookie`), what plugins add through `login_form`, and
     * wp-login.php's errors and messages above them. On any page other than `login.tsx` it is a
     * sign-in form that comes back to the page it is on.
     */
    public static function form(mixed $class, mixed $submit, mixed $notices, mixed $links = null): Html
    {
        $state = self::$state ?? self::fresh();
        $out = '';
        if ($notices !== false) {
            if ($state['errors'] !== '') {
                $out .= '<div id="login_error" class="typedpress-login-error" role="alert">' . wp_kses_post($state['errors']) . '</div>';
            }
            if ($state['messages'] !== '') {
                $out .= '<div id="login-message" class="typedpress-login-message" role="status">' . wp_kses_post($state['messages']) . '</div>';
            }
        }
        $described = $state['errors'] !== '' ? ' aria-describedby="login_error"' : '';
        ob_start();
        // Fields plugins add to the sign-in form (a captcha, a nonce), as wp-login.php prints them.
        do_action('login_form');
        $extra = (string) ob_get_clean();
        if (has_action('login_form')) {
            self::$extra = true;
        }
        $class = trim('typedpress-login ' . (is_string($class) ? $class : ''));
        $label = is_string($submit) && $submit !== '' ? $submit : __('Log In');
        $out .= '<form name="loginform" id="loginform" class="' . esc_attr($class) . '" method="post" action="' . esc_url(self::action()) . '">'
            . '<p><label for="user_login">' . esc_html__('Username or Email Address') . '</label>'
            . '<input type="text" name="log" id="user_login"' . $described . ' value="' . esc_attr($state['login']) . '" autocapitalize="off" autocomplete="username" required></p>'
            . '<p><label for="user_pass">' . esc_html__('Password') . '</label>'
            . '<input type="password" name="pwd" id="user_pass"' . $described . ' value="" autocomplete="current-password" spellcheck="false" required></p>'
            . $extra
            . '<p class="forgetmenot"><input name="rememberme" type="checkbox" id="rememberme" value="forever"' . ($state['remember'] ? ' checked' : '') . '> '
            . '<label for="rememberme">' . esc_html__('Remember Me') . '</label></p>'
            . '<p class="submit"><button type="submit" name="wp-submit" id="wp-submit">' . esc_html($label) . '</button>'
            . '<input type="hidden" name="redirect_to" value="' . esc_attr($state['redirectTo']) . '">'
            // wp-login.php refuses a post with `testcookie` and no test cookie, which only wp-login.php
            // sets: the field belongs on its own page, not on a form elsewhere on the site.
            . (self::$state !== null ? '<input type="hidden" name="testcookie" value="1">' : '') . '</p>'
            . '</form>';
        if ($links !== false) {
            $nav = [];
            if (get_option('users_can_register')) {
                $nav[] = (string) apply_filters('register', '<a class="wp-login-register" href="' . esc_url(wp_registration_url()) . '">' . esc_html__('Register') . '</a>');
            }
            $nav[] = (string) apply_filters('lost_password_html_link', '<a class="wp-login-lost-password" href="' . esc_url(wp_lostpassword_url()) . '">' . esc_html__('Lost your password?') . '</a>');
            $out .= '<p id="nav" class="typedpress-login-links">' . implode(' | ', array_filter($nav)) . '</p>';
        }
        return Html::raw($out);
    }

    /** Where the form posts: wp-login.php, as WordPress's own form posts. */
    private static function action(): string
    {
        return site_url('wp-login.php', 'login_post');
    }

    /** The view outside wp-login.php: nothing to report, and back to this page after signing in. */
    private static function fresh(): array
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        // REQUEST_URI already holds a subdirectory install's path; home_url() would add it again.
        $here = (string) wp_parse_url(home_url('/'), PHP_URL_SCHEME) . '://' . (string) wp_parse_url(home_url('/'), PHP_URL_HOST)
            . (($port = wp_parse_url(home_url('/'), PHP_URL_PORT)) ? ":$port" : '') . (str_starts_with($uri, '/') ? $uri : '/');
        return [
            'errors' => '', 'messages' => '', 'errorList' => [], 'messageList' => [],
            'login' => '', 'remember' => false, 'redirectTo' => $here,
        ];
    }

    /** A message as plain text, for a page that prints it itself: tags dropped, entities read. */
    private static function text(string $html): string
    {
        return trim(html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
