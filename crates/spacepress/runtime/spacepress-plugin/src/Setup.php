<?php
declare(strict_types=1);

namespace SpacePressPlugin;

/** The SpacePress screen in wp-admin: what the site needs, how to deploy to it, and what has been deployed. */
final class Setup
{
    public const PAGE = 'spacepress-setup';
    private const ACTION = 'sp_setup';

    public static function menu(): void
    {
        add_menu_page('SpacePress', 'SpacePress', Site::CAPABILITY, self::PAGE, [self::class, 'render'], 'dashicons-cloud-upload', 3);
    }

    public static function notice(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!Site::mayDeploy() || ($screen !== null && str_contains((string) $screen->id, self::PAGE))) {
            return;
        }
        if (Mount::error() !== null) {
            printf('<div class="notice notice-error"><p><strong>SpacePress:</strong> the app is not running. %s <a href="%s">Open SpacePress</a></p></div>', esc_html(Mount::error()), esc_url(self::url()));
        } elseif (!Requirements::ready()) {
            printf('<div class="notice notice-warning"><p><strong>SpacePress</strong> needs a few plugins before an app can be deployed here. <a href="%s">Set up SpacePress</a></p></div>', esc_url(self::url()));
        }
    }

    public static function url(): string
    {
        return admin_url('admin.php?page=' . self::PAGE);
    }

    /**
     * Activating SpacePress installs the plugins it depends on: they are not a choice. A failure here
     * (no network, a host that blocks file changes) never stops the activation; the SpacePress screen
     * shows what is still missing and its button tries again.
     */
    public static function activated(bool $network = false): void
    {
        // Installing plugins is for whoever may deploy (on a network, its super admins: a subsite's
        // administrator activating SpacePress there installs nothing) and for WP-CLI, which is a shell.
        if (Site::closed() !== null || !((defined('WP_CLI') && WP_CLI) || Site::mayDeploy())) {
            return;
        }
        try {
            $wanted = array_filter(Requirements::checks(), static fn (array $c): bool => $c['plugin'] && !$c['ok'] && !$c['optional']);
            $installed = Requirements::install(array_intersect_key(Requirements::pinned(), array_flip(array_column($wanted, 'id'))), $network);
            $notice = ['type' => 'success', 'text' => $installed === [] ? '' : 'SpacePress installed and activated the plugins it needs: ' . implode(', ', $installed) . '.'];
        } catch (\Throwable $error) {
            $notice = ['type' => 'error', 'text' => $error->getMessage()];
        }
        set_transient('sp_setup_notice_' . get_current_user_id(), $notice, 300);
    }

    /** The screen's two buttons: install the plugins SpacePress needs, and make an earlier build live. */
    public static function handle(): void
    {
        if (!Site::mayDeploy()) {
            wp_die(esc_html('You are not allowed to change SpacePress on this site.'), 'SpacePress', ['response' => 403]);
        }
        check_admin_referer(self::ACTION);
        $notice = ['type' => 'success', 'text' => ''];
        try {
            if (Site::closed() !== null) {
                throw new \RuntimeException(Site::closed());
            }
            if (isset($_POST['install'])) {
                $also = array_map('sanitize_key', (array) wp_unslash($_POST['also'] ?? []));
                $wanted = array_filter(Requirements::checks(), static fn (array $c): bool => $c['plugin'] && !$c['ok'] && (!$c['optional'] || in_array($c['id'], $also, true)));
                $installed = Requirements::install(array_intersect_key(Requirements::pinned(), array_flip(array_column($wanted, 'id'))));
                $notice['text'] = $installed === [] ? 'Everything SpacePress needs is already installed.' : 'Installed and activated: ' . implode(', ', $installed) . '.';
            } elseif (isset($_POST['live'])) {
                $build = sanitize_key((string) wp_unslash($_POST['live']));
                Receiver::rollback($build);
                $notice['text'] = 'Build ' . substr($build, 0, 12) . ' is live again. Its code runs on the current database: nothing was migrated back.';
            }
        } catch (\Throwable $error) {
            $notice = ['type' => 'error', 'text' => $error->getMessage()];
        }
        set_transient('sp_setup_notice_' . get_current_user_id(), $notice, 60);
        wp_safe_redirect(self::url());
        exit;
    }

    public static function render(): void
    {
        if (!Site::mayDeploy()) {
            wp_die(esc_html('You are not allowed to manage SpacePress on this site.'));
        }
        $notice = get_transient('sp_setup_notice_' . get_current_user_id());
        delete_transient('sp_setup_notice_' . get_current_user_id());
        $checks = Requirements::checks();
        $ready = Requirements::ready();
        $closed = Site::closed();
        $live = Site::live();
        $manifest = null;
        $broken = Mount::error();
        try {
            $manifest = $live === null ? null : Site::manifest($live);
        } catch (\Throwable $error) {
            $broken ??= $error->getMessage();
        }
        $home = untrailingslashit(home_url());
        echo '<div class="wrap"><h1>SpacePress</h1>';
        if (is_array($notice) && ($notice['text'] ?? '') !== '') {
            printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $notice['type'] === 'error' ? 'error' : 'success', esc_html($notice['text']));
        }
        if ($broken !== null) {
            printf('<div class="notice notice-error"><p><strong>The app is not running.</strong> %s</p></div>', esc_html($broken));
        }
        foreach (array_filter([$closed, Site::conflict(), Site::exposure(), Site::existing()]) as $warning) {
            printf('<div class="notice notice-warning"><p>%s</p></div>', esc_html($warning));
        }
        if ($manifest !== null) {
            printf('<p>This site runs the SpacePress app <strong>%s</strong> (build <code>%s</code>, compiler %s).</p>', esc_html($manifest['name']), esc_html(substr($live, 0, 12)), esc_html((string) ($manifest['compiler'] ?? '')));
        } else {
            echo '<p>No SpacePress app is deployed to this site yet.</p>';
        }

        echo '<h2>1. What SpacePress needs</h2>';
        echo '<table class="widefat striped" style="max-width:820px"><thead><tr><th>Requirement</th><th>Needs</th><th>This site</th><th></th></tr></thead><tbody>';
        foreach ($checks as $check) {
            $state = $check['ok'] ? 'Ready' : ($check['optional'] ? 'Only for apps with locales' : ($check['plugin'] ? 'Not installed' : 'Not met'));
            printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html($check['label']), esc_html($check['required'] . ($check['required'] === 'enabled' ? '' : ' or newer')), esc_html($check['found'] ?? 'missing'), esc_html($state));
        }
        echo '</tbody></table>';
        $missing = array_filter($checks, static fn (array $c): bool => $c['plugin'] && !$c['ok']);
        $blocked = array_filter($checks, static fn (array $c): bool => !$c['plugin'] && !$c['ok']);
        if ($blocked !== []) {
            echo '<p>' . esc_html('SpacePress can\'t run here until the server meets the rows marked "Not met". Those are changed by the host, not from this screen.') . '</p>';
        }
        if ($missing !== [] && $closed === null) {
            printf('<form method="post" action="%s" style="margin-top:12px">', esc_url(admin_url('admin-post.php')));
            wp_nonce_field(self::ACTION);
            printf('<input type="hidden" name="action" value="%s">', esc_attr(self::ACTION));
            foreach ($missing as $check) {
                if ($check['optional']) {
                    printf('<p><label><input type="checkbox" name="also[]" value="%s"> Also install %s (needed only by an app with more than one language)</label></p>', esc_attr($check['id']), esc_html($check['label']));
                }
            }
            if (array_filter($missing, static fn (array $c): bool => !$c['optional']) !== []) {
                echo '<p>Each plugin is downloaded from wordpress.org at the version above and installed only if its checksum is the one SpacePress pins.</p>';
            }
            submit_button(array_filter($missing, static fn (array $c): bool => !$c['optional']) !== [] ? 'Install what is missing' : 'Install the selected plugin', 'primary', 'install', false);
            echo '</form>';
        }

        echo '<h2>2. Deploy an app</h2>';
        if (!Site::passwords()) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html('WordPress only accepts application passwords over HTTPS, and this site is not on HTTPS, so spacepress login can\'t sign in here yet.') . '</p></div>';
        }
        echo '<p>In your SpacePress project, on your own machine:</p>';
        printf('<pre style="background:#f6f7f7;padding:12px;max-width:796px;overflow:auto">spacepress login %s
spacepress push</pre>', esc_html($home));
        echo '<p>' . esc_html('spacepress login opens this site so you can approve an application password for the command line. spacepress push compiles the project, uploads the build, shows what it will change here, then makes it live.') . ($ready ? '' : ' <strong>' . esc_html('Finish step 1 first.') . '</strong>') . '</p>';

        $history = array_reverse(Site::history());
        if ($history !== []) {
            echo '<h2>Deployments</h2><table class="widefat striped" style="max-width:820px"><thead><tr><th>When (UTC)</th><th>Build</th><th>By</th><th>How</th><th></th></tr></thead><tbody>';
            foreach (array_slice($history, 0, 30) as $entry) {
                $user = get_userdata((int) ($entry['user'] ?? 0));
                $build = (string) ($entry['build'] ?? '');
                echo '<tr>';
                printf('<td>%s</td><td><code>%s</code></td><td>%s</td><td>%s</td><td>', esc_html(str_replace(['T', '+00:00'], [' ', ''], (string) ($entry['at'] ?? ''))), esc_html(substr($build, 0, 12)), esc_html($user ? $user->user_login : '-'), esc_html(($entry['kind'] ?? '') === 'rollback' ? 'Made live again' : 'Deployed'));
                if ($build === $live) {
                    echo '<strong>Live</strong>';
                } elseif ($closed === null && Site::ready($build)) {
                    printf('<form method="post" action="%s">', esc_url(admin_url('admin-post.php')));
                    wp_nonce_field(self::ACTION);
                    printf('<input type="hidden" name="action" value="%s"><button class="button" name="live" value="%s">Make live</button></form>', esc_attr(self::ACTION), esc_attr($build));
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
            echo '<p class="description">' . esc_html('Making an earlier build live switches the code only. Database migrations are not undone.') . '</p>';
        }
        echo '</div>';
    }
}
