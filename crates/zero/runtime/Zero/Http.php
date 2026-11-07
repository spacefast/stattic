<?php

declare(strict_types=1);

namespace Zero;

/** Outbound HTTP for webhook delivery through `wp_safe_remote_post`. */
final class Http
{
    public const TIMEOUT = 15;

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string} status 0 when the request never got a response
     */
    public static function post(string $url, string $body, array $headers): array
    {
        if (!function_exists('wp_safe_remote_post')) {
            throw new \LogicException('webhook delivery needs WordPress loaded (wp_safe_remote_post)');
        }
        // The URL comes from the app's config/environment, not from a request, so its own host and
        // port pass WordPress's private-address and safe-port checks for this one call; redirects
        // stay off, so nothing else gets through.
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $port = (int) (parse_url($url, PHP_URL_PORT) ?? 0);
        $external = static fn (bool $is, string $h): bool => $is || strtolower($h) === $host;
        $ports = static fn (array $allowed): array => $port === 0 ? $allowed : [...$allowed, $port];
        add_filter('http_request_host_is_external', $external, 10, 2);
        add_filter('http_allowed_safe_ports', $ports);
        try {
            $response = wp_safe_remote_post($url, ['body' => $body, 'headers' => $headers, 'timeout' => self::TIMEOUT, 'redirection' => 0]);
        } finally {
            remove_filter('http_request_host_is_external', $external, 10);
            remove_filter('http_allowed_safe_ports', $ports);
        }
        if (is_wp_error($response)) {
            throw new \RuntimeException('webhook request failed: ' . $response->get_error_message());
        }

        return ['status' => (int) wp_remote_retrieve_response_code($response), 'body' => (string) wp_remote_retrieve_body($response)];
    }
}
