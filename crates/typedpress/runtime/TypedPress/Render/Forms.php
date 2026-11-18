<?php

declare(strict_types=1);

namespace TypedPress\Render;

use TypedPress\Html;
use TypedPress\HttpError;
use TypedPress\Js;
use TypedPress\JsObject;

/**
 * Forms that work without JavaScript (docs/spec/rendering.md §4.2).
 *
 * `<form action={subscribe}>` renders `method="post"` to the action's facade URL, with a hidden
 * `_tp_return` (the page's path and query) and, when the page was rendered for a signed-in user,
 * the REST nonce. The facade reads a form-encoded body against the action's input fields
 * ({@see decode()}), runs the action exactly as a JSON call runs it, and answers `303 See Other`
 * back to the page with a flash ({@see redirect()}).
 *
 * The flash is a cookie sealed with a key only the site has, named by a marker the redirect adds to
 * the URL (`?_flash=<id>`). The marker makes the URL one no shared cache has seen, and the router
 * renders it `private, no-store` and records nothing for purges, so neither the result nor an
 * error is ever stored in Batcache or at the edge for someone else. The page reads it once with
 * `useFormState(action)`; the cookie is deleted in the same response. Nothing is stored on the
 * server: a flash older than {@see TTL} seconds, or one sealed before a salt change, reads as no flash.
 *
 * With JavaScript, the form's directive ({@see enhanced()}) sends the same body with `fetch`, which
 * follows the 303 and reads the flash in that one page request; the router swaps that HTML in place.
 */
final class Forms
{
    /** The hidden field with the page to go back to. */
    public const RETURN = '_tp_return';
    /** The query parameter naming this visitor's flash. */
    public const MARKER = '_flash';
    /** Seconds a flash lives. */
    public const TTL = 120;
    /** A flash larger than this drops the submitted values, then shortens messages. */
    private const MAX_BYTES = 3000;
    private const COOKIE = 'tp_flash_';

    /** @var array<string, array<string, mixed>>|null the flash this request carries, by action */
    private static ?array $flash = null;
    /** The flash cookie this request read, which its response expires. */
    private static ?string $name = null;

    /**
     * Expires the flash cookie this request read (AppRouter::document, before its headers go, with
     * the block template the page renders in), unless a JavaScript submit from a page in another
     * template and at another address fetched it (client/router.js sends `X-TP-Template` and
     * `X-TP-From`): that submit loads this page in full, which reads the flash then. Called once;
     * later calls do nothing.
     */
    public static function expire(?string $template): void
    {
        $name = self::$name;
        if ($name === null || headers_sent()) {
            return;
        }
        self::$name = null;
        // The page that submitted, and its template: the same check client/router.js makes.
        $from = $_SERVER['HTTP_X_TP_TEMPLATE'] ?? null;
        $page = $_SERVER['HTTP_X_TP_FROM'] ?? null;
        if (is_string($from) && $from !== '' && $template !== null && $from !== $template
            && is_string($page) && $page !== self::withoutMarker((string) ($_SERVER['REQUEST_URI'] ?? '/'))) {
            return;
        }
        header('Set-Cookie: ' . $name . '=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax' . (is_ssl() ? '; Secure' : ''), false);
    }

    /** The attributes `<form action={name}>` gets. */
    public static function attributes(string $action): array
    {
        return ['method' => 'post', 'action' => \TypedPress\Wp\Facade::url('/actions/' . rawurlencode($action))] + self::enhanced();
    }

    /**
     * The JavaScript submit's directive (client/router.js `actions.submit`): with scripts on, the
     * same post goes through `fetch` and the page it comes back to is swapped into the router region
     * in place. The router store is enqueued on any page that renders one.
     *
     * @return array<string, string>
     */
    public static function enhanced(): array
    {
        Navigation::used();

        return ['data-wp-interactive' => Navigation::NAMESPACE, 'data-wp-on--submit' => 'actions.submit'];
    }

    /** The hidden fields after the author's: where to come back to, and the nonce of a signed-in render. */
    public static function fields(string $action): Html
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $out = [Html::element('input', ['type' => 'hidden', 'name' => self::RETURN, 'value' => self::withoutMarker($uri)])];
        if (function_exists('is_user_logged_in') && is_user_logged_in()) {
            // A signed-in render is `private, no-store`: its nonce never reaches another visitor.
            $out[] = Html::element('input', ['type' => 'hidden', 'name' => '_wpnonce', 'value' => wp_create_nonce('wp_rest')]);
        }

        return Html::children(...$out);
    }

    /** Whether this request is a browser form post (form-encoded), not a JSON call. */
    public static function posted(): bool
    {
        $type = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''))[0]));

        return $type === 'application/x-www-form-urlencoded' || $type === 'multipart/form-data';
    }

    /**
     * A form post's body as the action's JSON input. Each field reads as its type reads in a URL
     * (`integer`: digits; `real`: a number; `boolean`: a checkbox, absent is false); an empty value
     * is absent. A value that doesn't parse is passed as text, so the action's own validation names
     * the field. Repeated names are lists, only for list fields. Uploads aren't read.
     *
     * @param array<string, array<string, mixed>> $fields the action's input fields
     */
    public static function decode(array $fields, string $body): string
    {
        if (str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data')) {
            throw new HttpError(415, 'unsupported_media_type', 'A form that posts to an action is sent form-encoded; uploads go through the media API');
        }
        $given = [];
        foreach (\TypedPress\QueryString::parse($body) as [$name, $value]) {
            if ($name === self::RETURN || $name === '_wpnonce' || $name === '_locale' || $name === '_method') {
                continue;
            }
            $name = str_ends_with($name, '[]') ? substr($name, 0, -2) : $name;
            $given[$name][] = $value;
        }
        $input = [];
        foreach ($given as $name => $values) {
            $field = $fields[$name] ?? null;
            $type = $field['type'] ?? 'text';
            $list = in_array($type, ['select', 'refList', 'mediaList'], true);
            if (!$list && count($values) > 1) {
                throw HttpError::invalidInput("$name is sent more than once", HttpError::seg($name));
            }
            $values = array_values(array_filter($values, static fn (string $v): bool => $v !== ''));
            if ($list) {
                $item = $type === 'select' ? 'text' : 'integer';
                $input[$name] = array_map(static fn (string $v) => self::scalar($item, $v), $values);
                continue;
            }
            if ($values !== []) {
                $input[$name] = self::scalar($type, $values[0]);
            }
        }
        // A checkbox that isn't checked isn't sent: a boolean field the form doesn't send is false.
        foreach ($fields as $name => $field) {
            if (($field['type'] ?? null) === 'boolean' && !array_key_exists($name, $input) && !isset($field['default'])) {
                $input[$name] = false;
            }
        }

        return $input === [] ? '{}' : (string) json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function scalar(string $type, string $value): mixed
    {
        $trimmed = trim($value);

        return match ($type) {
            'integer', 'media', 'file' => preg_match('/\A-?\d{1,15}\z/', $trimmed) === 1 ? (int) $trimmed : $value,
            'real' => is_numeric($trimmed) && preg_match('/\A[-+]?(\d+\.?\d*|\.\d+)([eE][-+]?\d+)?\z/', $trimmed) === 1 ? (float) $trimmed : $value,
            'boolean' => match (strtolower($trimmed)) { 'on', 'true', '1' => true, 'false', '0', 'off' => false, default => $value },
            default => $value,
        };
    }

    /**
     * The form post's answer: `303 See Other` to the page it came from, with the flash for that
     * page. Only a path on this site is a place to come back to.
     *
     * @param array<string, mixed>|null $problem the action's problem document, or null on success
     * @return array{int, array<string, string>, string}
     */
    public static function redirect(string $action, string $body, mixed $result, ?array $problem): array
    {
        $back = '/';
        foreach (\TypedPress\QueryString::parse($body) as [$name, $value]) {
            if ($name === self::RETURN) {
                $back = $value;
                break;
            }
        }
        if (!preg_match('#\A/(?![/\\\\])[^\x00-\x20\x7f]*\z#', $back)) {
            $back = '/';
        }
        // The return is the request URI, which carries a subdirectory install's home path
        // (`/blog/join`); home_url() below adds that path, so it is taken off once here.
        $base = rtrim((string) (wp_parse_url(home_url('/'), PHP_URL_PATH) ?? '/'), '/');
        if ($base !== '' && preg_match('#\A' . preg_quote($base, '#') . '(?=[/?\#]|\z)#', $back) === 1) {
            $back = '/' . ltrim(substr($back, strlen($base)), '/');
        }
        $values = [];
        if ($problem !== null) {
            // What the visitor typed, to fill the form again. Never a password.
            foreach (\TypedPress\QueryString::parse($body) as [$name, $value]) {
                if ($name[0] !== '_' && !preg_match('/pass(word)?|secret|token/i', $name) && !isset($values[$name])) {
                    $values[$name] = $value;
                }
            }
        }
        $flash = [
            'action' => $action,
            'at' => time(),
            'result' => $problem === null ? $result : null,
            'error' => $problem === null ? null : [
                'code' => (string) ($problem['code'] ?? 'rejected'),
                'message' => (string) ($problem['detail'] ?? $problem['title'] ?? ''),
                'fields' => isset($problem['pointer']) && is_string($problem['pointer']) && $problem['pointer'] !== ''
                    ? [self::field($problem['pointer']) => (string) ($problem['detail'] ?? '')]
                    : new \stdClass(),
            ],
            'values' => $values === [] ? new \stdClass() : $values,
        ];
        $sealed = self::seal($flash);
        if (strlen($sealed) > self::MAX_BYTES) {
            $flash['values'] = new \stdClass();
            $sealed = self::seal($flash);
        }
        if (strlen($sealed) > self::MAX_BYTES && $flash['error'] !== null) {
            $flash['error']['message'] = mb_substr($flash['error']['message'], 0, 200);
            $flash['error']['fields'] = new \stdClass();
            $sealed = self::seal($flash);
        }
        if (strlen($sealed) > self::MAX_BYTES) {
            // A result too large for a cookie: the page sees that the action ran, not its value.
            $flash['result'] = null;
            $sealed = self::seal($flash);
        }
        $id = bin2hex(random_bytes(6));
        // Back at the page's one address (`/notes/a/`): the other spelling would redirect, and the
        // page answers a flash where it is asked.
        $location = \TypedPress\Wp\AppRouter::href(self::withoutMarker($back));
        $location .= (str_contains($location, '?') ? '&' : '?') . self::MARKER . '=' . $id;
        $cookie = self::COOKIE . $id . '=' . $sealed . '; Max-Age=' . self::TTL . '; Path=/; HttpOnly; SameSite=Lax' . (is_ssl() ? '; Secure' : '');

        return [303, [
            'Location' => home_url($location),
            'Set-Cookie' => $cookie,
            'Cache-Control' => 'private, no-store',
        ], ''];
    }

    /** The first segment of an RFC 6901 pointer: the form field it names. */
    private static function field(string $pointer): string
    {
        $segment = explode('/', ltrim($pointer, '/'))[0];

        return str_replace(['~1', '~0'], ['/', '~'], $segment);
    }

    /** Whether this request carries a flash marker: its render is this visitor's alone. */
    public static function marked(): bool
    {
        return isset($_GET[self::MARKER]);
    }

    /**
     * `useFormState(action)`: the state the last submit of that action's form left for this page
     * (posted by the browser, or by the JavaScript submit, which follows the same 303). Without one: not pending, no error, no result, no values.
     */
    public static function state(string $action): JsObject
    {
        $flash = self::read()[$action] ?? null;
        $error = $flash['error'] ?? null;

        return new JsObject([
            'pending' => false,
            'error' => is_array($error) ? new JsObject([
                'code' => (string) ($error['code'] ?? ''),
                'message' => (string) ($error['message'] ?? ''),
                'fields' => new JsObject(array_map('strval', (array) ($error['fields'] ?? []))),
            ]) : null,
            'result' => $flash === null ? null : Js::decodeJson((string) json_encode($flash['result'] ?? null)),
            'values' => new JsObject(array_map('strval', (array) ($flash['values'] ?? []))),
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private static function read(): array
    {
        if (self::$flash !== null) {
            return self::$flash;
        }
        self::$flash = [];
        $id = $_GET[self::MARKER] ?? null;
        if (!is_string($id) || preg_match('/\A[0-9a-f]{12}\z/', $id) !== 1) {
            return self::$flash;
        }
        $name = self::COOKIE . $id;
        $sealed = $_COOKIE[$name] ?? null;
        if (!is_string($sealed)) {
            return self::$flash;
        }
        // Read once: the cookie goes with this response, unless the page that submitted the form is
        // in another block template than this one (client/router.js sends `X-TP-Template`). That
        // submit loads this page in full, which reads the flash then (Forms::keepFor()).
        if (!headers_sent()) {
            self::$name = $name;
            // The router expires it as it answers (AppRouter::document, once it knows the template);
            // any other answer that read it (a page outside the router) expires it at shutdown.
            add_action('shutdown', static fn () => self::expire(null), 0);
        }
        $flash = self::open($sealed);
        if ($flash !== null && is_string($flash['action'] ?? null) && (int) ($flash['at'] ?? 0) >= time() - self::TTL) {
            self::$flash[$flash['action']] = $flash;
        }

        return self::$flash;
    }

    private static function withoutMarker(string $uri): string
    {
        $parts = explode('?', $uri, 2);
        if (!isset($parts[1])) {
            return $uri;
        }
        $kept = array_filter(explode('&', $parts[1]), static fn (string $pair): bool => $pair !== '' && explode('=', $pair, 2)[0] !== self::MARKER);

        return $parts[0] . ($kept === [] ? '' : '?' . implode('&', $kept));
    }

    private static function seal(array $flash): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return rtrim(strtr(base64_encode($nonce . sodium_crypto_secretbox((string) json_encode($flash, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $nonce, self::key())), '+/', '-_'), '=');
    }

    private static function open(string $sealed): ?array
    {
        $raw = base64_decode(strtr($sealed, '-_', '+/'), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key());
        $flash = $plain === false ? null : json_decode($plain, true);

        return is_array($flash) ? $flash : null;
    }

    private static function key(): string
    {
        static $key = null;

        return $key ??= hash('sha256', "typedpress-form-flash\0" . wp_salt('auth'), true);
    }
}
