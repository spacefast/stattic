<?php
declare(strict_types=1);

// Loaded only by the disposable loopback Playground MU plugin. This is a real
// native session for the deliberately seeded test user, never a production API.
add_action('rest_api_init', static function (): void {
    register_rest_route('spacefast-context-preview/v1', '/session', [
        'methods' => 'POST', 'permission_callback' => '__return_true',
        'callback' => static function (): array {
            wp_set_current_user(1);
            $cookie = wp_generate_auth_cookie(1, time() + 86400, 'logged_in');
            $_COOKIE[LOGGED_IN_COOKIE] = $cookie;
            return ['cookie' => LOGGED_IN_COOKIE, 'value' => $cookie, 'nonce' => wp_create_nonce('wp_rest')];
        },
    ]);
});
