<?php

declare(strict_types=1);

namespace SpacePress;

/** Runtime variables, isolated to the host's explicit values for managed apps. */
final class Env
{
    /** @var array<string, string>|null Null retains standalone environment lookup. */
    private static ?array $variables = null;

    /**
     * WordPress's own secrets: its database login, its keys and salts (wp-config.php constants,
     * including the pre-2.7 `SECRET_KEY`/`SECRET_SALT` that `wp_salt()` still reads), the site
     * key of core's Secrets API (`WP_SECRETS_KEY`, and `WP_SECRETS_KEY_PREVIOUS` during rotation),
     * and the credentials core reads for its filesystem (`FTP_*`) and outgoing proxy
     * (`WP_PROXY_USERNAME`/`WP_PROXY_PASSWORD`).
     * They are never an app variable on any host. The compiler refuses the same names
     * (`src/compile.rs`, `HOST_SECRETS`); a unit test there keeps the two lists equal.
     */
    public const HOST_SECRETS = [
        'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST',
        'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
        'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT',
        'SECRET_KEY', 'SECRET_SALT', 'WP_CACHE_KEY_SALT',
        'WP_SECRETS_KEY', 'WP_SECRETS_KEY_PREVIOUS',
        'FTP_USER', 'FTP_PASS', 'FTP_PUBKEY', 'FTP_PRIVKEY', 'WP_PROXY_USERNAME', 'WP_PROXY_PASSWORD',
    ];

    /**
     * What PHP-FPM puts in the process environment for each request: the FastCGI parameters
     * nginx (or any FastCGI server) passes, which `getenv()` returns. That is the request headers
     * (`HTTP_COOKIE`, `HTTP_AUTHORIZATION`, `HTTP_PROXY`), the visitor's basic-auth credentials,
     * and the CGI meta-variables (`QUERY_STRING`, `REQUEST_URI`, `PATH_INFO`, `CONTENT_TYPE`,
     * `REMOTE_ADDR` …). They are visitor input, not settings, so the process environment fallback
     * never returns them. They are exact names, so an app's own `SERVER_URL` isn't one. A
     * wp-config.php constant can't come from a request, so the constant fallback still reads
     * these names, and an isolated host's own variables may use them too.
     */
    private const REQUEST_PREFIXES = ['HTTP_', 'PHP_AUTH_'];
    /** CGI/1.1 meta-variables (RFC 3875 §4.1) and the ones nginx's fastcgi_params and PHP-FPM add. */
    private const REQUEST_NAMES = [
        'AUTH_TYPE', 'CONTENT_LENGTH', 'CONTENT_TYPE', 'GATEWAY_INTERFACE', 'PATH_INFO',
        'PATH_TRANSLATED', 'QUERY_STRING', 'REMOTE_ADDR', 'REMOTE_HOST', 'REMOTE_IDENT',
        'REMOTE_PORT', 'REMOTE_USER', 'REQUEST_METHOD', 'REQUEST_SCHEME', 'REQUEST_URI',
        'SCRIPT_NAME', 'SCRIPT_FILENAME', 'SERVER_ADDR', 'SERVER_NAME', 'SERVER_PORT',
        'SERVER_PROTOCOL', 'SERVER_SOFTWARE', 'DOCUMENT_ROOT', 'DOCUMENT_URI', 'HTTPS',
        'ORIG_PATH_INFO', 'ORIG_PATH_TRANSLATED', 'ORIG_SCRIPT_NAME', 'ORIG_SCRIPT_FILENAME',
        'REDIRECT_STATUS', 'FCGI_ROLE',
    ];

    public static function hostSecret(string $name): bool
    {
        return in_array(strtoupper($name), self::HOST_SECRETS, true);
    }

    public static function requestValue(string $name): bool
    {
        $upper = strtoupper($name);
        if (in_array($upper, self::REQUEST_NAMES, true)) {
            return true;
        }
        foreach (self::REQUEST_PREFIXES as $prefix) {
            if (str_starts_with($upper, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Bind a managed app before loading its schema or booting its runtime. */
    public static function isolate(array $variables): void
    {
        self::$variables = [];
        foreach ($variables as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new \InvalidArgumentException('Runtime variables must map names to strings');
            }
        }
        self::$variables = $variables;
    }

    public static function get(string $name): ?string
    {
        if (self::hostSecret($name)) {
            return null;
        }
        if (self::$variables !== null) {
            return self::$variables[$name] ?? null;
        }
        $value = self::requestValue($name) ? false : getenv($name);
        if ($value !== false) {
            return $value;
        }
        if (defined($name)) {
            $constant = constant($name);

            return is_scalar($constant) ? (string) $constant : null;
        }

        return null;
    }

    /** A schema.php value that is either a literal string or `['env' => NAME]`. */
    public static function resolve(mixed $value): ?string
    {
        if (is_array($value) && isset($value['env'])) {
            return self::get((string) $value['env']);
        }

        return is_string($value) ? $value : null;
    }
}
