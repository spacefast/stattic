<?php

declare(strict_types=1);

namespace TypedPress\Wp;

/**
 * The request a `tp/app/` HTTP handler receives (`route.ts`, or a page's own POST/PUT/…): who
 * calls, its URL and its query, as WordPress REST decides them (suspension refused like every
 * other channel):
 * - a typedpress-identity Bearer token authenticates every method;
 * - a cookie session authenticates GET and HEAD, and an unsafe method only with the REST nonce in
 *   `X-WP-Nonce`, so a cross-site form cannot act as a signed-in visitor;
 * - an application password is never a route credential, on any method.
 * Anything else runs anonymously. A `signature` handler ignores the session entirely.
 */
final class RouteRequest
{
    /** Whether core accepted an application password on this request (`application_password_did_authenticate`). */
    private static bool $applicationPassword = false;

    /** Core's own application-password actions, registered as the runtime loads (before any user is resolved). */
    public static function register(): void
    {
        add_action('application_password_did_authenticate', static function (): void {
            self::$applicationPassword = true;
        });
        add_action('application_password_failed_authentication', static function (): void {
            self::$applicationPassword = false;
        });
    }

    /**
     * The route caller per the class comment. WpAuth::current() still runs first, so a failed
     * Bearer or a suspended account answers its problem rather than running anonymously.
     *
     * @param array<string, string> $headers
     */
    public static function caller(string $method, array $headers): ?array
    {
        $user = WpAuth::current();
        if ($user === null) {
            return null;
        }
        if (IdentityBridge::available() && \TypedPress\Identity\Bearer::token() !== null) {
            return $user;
        }
        // An application password core accepted is never a route credential.
        if (self::$applicationPassword) {
            return null;
        }
        // The remaining channel is the cookie: unsafe methods need the REST nonce.
        if (!in_array($method, ['GET', 'HEAD'], true)
            && wp_verify_nonce((string) ($headers['x-wp-nonce'] ?? ''), 'wp_rest') === false) {
            return null;
        }

        return $user;
    }

    /** The query parameter that carries a Spacefast share-link secret. */
    private const ACCESS_PARAM = '__';

    /**
     * `req.url` uses `https://` and the hostname from `Host` (port and
     * trailing dots dropped, lowercase), then the request URI with share-link secrets redacted, so a
     * handler that echoes its own URL never echoes a share token: the `token=`, `purchaseToken=` and
     * `__=` query values and the `/__/<token>` path segment (the `/__/collab` shell is not a secret).
     */
    public static function url(string $host, string $uri): string
    {
        $host = trim($host, " \t\n\f\r");
        // Only a bracketed IPv6 literal may give up a trailing `:digits`; `::1` has no port.
        if (preg_match('/\A(.*):\d+\z/s', $host, $match) === 1 && (!str_contains($match[1], ':') || str_ends_with($match[1], ']'))) {
            $host = $match[1];
        }
        $host = strtolower(rtrim(trim($host, " \t\n\f\r"), '.'));
        $uri = str_starts_with($uri, '/') ? $uri : '/' . $uri;
        $uri = (string) preg_replace('~([?&](?:purchaseToken|token)=)[^&#]*~', '$1[redacted]', $uri);
        $uri = (string) preg_replace('~(^|[?&])' . self::ACCESS_PARAM . '=[^&#]*~', '$1' . self::ACCESS_PARAM . '=[redacted]', $uri);
        $uri = (string) preg_replace('~^/__/(?!collab([/?#]|$))[^/?#]+~', '/__/[redacted]', $uri);

        return 'https://' . $host . $uri;
    }

    /** `req.query` without the share-link secret, so it is not a second way to read the token. */
    public static function query(string $query): string
    {
        if ($query === '' || !str_contains($query, self::ACCESS_PARAM)) {
            return $query;
        }
        $kept = array_filter(explode('&', $query), static function (string $part): bool {
            $name = explode('=', $part, 2)[0];

            return rawurldecode(str_replace('+', ' ', $name)) !== self::ACCESS_PARAM;
        });

        return implode('&', $kept);
    }
}
