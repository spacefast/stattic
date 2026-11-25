<?php

declare(strict_types=1);

namespace SpacePress\Identity;

/**
 * Identity's authenticator (TOTP and recovery codes) as a Two Factor provider, so an account with
 * Identity MFA finishes signing in on wp-login.php: the password there, then Two Factor's second
 * step, which checks the code against Identity's own factor ({@see Mfa::verify}, current timestep
 * only, no reuse, recovery codes single use). Two Factor sets the WordPress cookies only after the
 * code checks out (docs/decisions/2026-11-15-one-sign-in-page.md).
 *
 * Loaded only through Two Factor's `two_factor_providers` filter, after Two Factor's own provider
 * base class exists. Two Factor names a provider by its class: this one is
 * `SpacePress\Identity\TwoFactorProvider`.
 */
final class TwoFactorProvider extends \Two_Factor_Provider
{
    public function get_key()
    {
        return Plugin::TWO_FACTOR_KEY;
    }

    public function get_label()
    {
        return __('Authenticator app or recovery code', 'spacepress-identity');
    }

    public function get_alternative_provider_label()
    {
        return __('Use your authenticator app or a recovery code', 'spacepress-identity');
    }

    public function is_available_for_user($user)
    {
        try {
            return $user instanceof \WP_User && (new Mfa(Plugin::$db, Plugin::$security))->enabled((int) $user->ID);
        } catch (Failure) {
            // Storage can't say: count the factor as there, so Two Factor keeps asking for it.
            return $user instanceof \WP_User;
        }
    }

    public function authentication_page($user)
    {
        require_once ABSPATH . '/wp-admin/includes/template.php';
        do_action('two_factor_before_authentication_prompt', $this);
        echo '<p class="two-factor-prompt">' . esc_html__('Enter the code from your authenticator app, or a recovery code.', 'spacepress-identity') . '</p>';
        do_action('two_factor_after_authentication_prompt', $this);
        echo '<p><label for="authcode">' . esc_html__('Code:', 'spacepress-identity') . '</label>'
            . '<input type="text" name="authcode" id="authcode" class="input authcode" value="" size="20" autocomplete="one-time-code" autocapitalize="off" spellcheck="false"></p>';
        do_action('two_factor_after_authentication_input', $this);
        submit_button(__('Verify', 'spacepress-identity'));
    }

    public function validate_authentication($user)
    {
        $code = self::sanitize_code_from_request('authcode');
        if (!$user instanceof \WP_User || !is_string($code) || $code === '' || strlen($code) > 100) {
            return false;
        }
        try {
            // Identity's own limit on second-factor attempts per address, besides Two Factor's per account.
            Plugin::$security->limit('mfa:' . ($_SERVER['REMOTE_ADDR'] ?? ''));
            return (new Mfa(Plugin::$db, Plugin::$security))->verify((int) $user->ID, $code);
        } catch (Failure) {
            return false;
        }
    }
}
