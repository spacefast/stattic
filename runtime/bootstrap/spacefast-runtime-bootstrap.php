<?php
declare(strict_types=1);

/**
 * Plugin Name: Spacefast Runtime Bootstrap
 * Description: Installs the first Spacefast runtime release on a new wp.cloud site.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit(1);
}

/** @return array{revision:string,zip_url:string,md5:string,native_sha256:string,data_liberation:array{url:string,version:string,archive_sha256:string,phar_sha256:string}} */
function spacefast_runtime_bootstrap_config(): array
{
    $receipt = __DIR__ . '/release.php';
    if (!is_file($receipt)) {
        throw new RuntimeException('runtime_bootstrap_receipt_missing');
    }
    require_once $receipt;
    $config = [
        'revision' => defined('SPACEFAST_RUNTIME_BOOTSTRAP_REVISION') ? SPACEFAST_RUNTIME_BOOTSTRAP_REVISION : null,
        'zip_url' => defined('SPACEFAST_RUNTIME_BOOTSTRAP_ZIP_URL') ? SPACEFAST_RUNTIME_BOOTSTRAP_ZIP_URL : null,
        'md5' => defined('SPACEFAST_RUNTIME_BOOTSTRAP_MD5') ? SPACEFAST_RUNTIME_BOOTSTRAP_MD5 : null,
        'native_sha256' => defined('SPACEFAST_RUNTIME_BOOTSTRAP_NATIVE_SHA256') ? SPACEFAST_RUNTIME_BOOTSTRAP_NATIVE_SHA256 : null,
        'data_liberation' => [
            'url' => defined('SPACEFAST_RUNTIME_BOOTSTRAP_DATA_LIBERATION_URL') ? SPACEFAST_RUNTIME_BOOTSTRAP_DATA_LIBERATION_URL : null,
            'version' => defined('SPACEFAST_RUNTIME_BOOTSTRAP_DATA_LIBERATION_VERSION') ? SPACEFAST_RUNTIME_BOOTSTRAP_DATA_LIBERATION_VERSION : null,
            'archive_sha256' => defined('SPACEFAST_RUNTIME_BOOTSTRAP_DATA_LIBERATION_ARCHIVE_SHA256') ? SPACEFAST_RUNTIME_BOOTSTRAP_DATA_LIBERATION_ARCHIVE_SHA256 : null,
            'phar_sha256' => defined('SPACEFAST_RUNTIME_BOOTSTRAP_DATA_LIBERATION_PHAR_SHA256') ? SPACEFAST_RUNTIME_BOOTSTRAP_DATA_LIBERATION_PHAR_SHA256 : null,
        ],
    ];
    if (
        !is_string($config['revision'])
        || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,190}$/', $config['revision'])
        || !is_string($config['zip_url'])
        || !str_starts_with($config['zip_url'], 'https://')
        || !is_string($config['md5'])
        || !preg_match('/^[a-f0-9]{32}$/', $config['md5'])
        || !is_string($config['native_sha256'])
        || !preg_match('/^[a-f0-9]{64}$/', $config['native_sha256'])
        || !is_string($config['data_liberation']['url'])
        || !str_starts_with($config['data_liberation']['url'], 'https://')
        || !is_string($config['data_liberation']['version'])
        || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,190}$/', $config['data_liberation']['version'])
        || !is_string($config['data_liberation']['archive_sha256'])
        || !preg_match('/^[a-f0-9]{64}$/', $config['data_liberation']['archive_sha256'])
        || !is_string($config['data_liberation']['phar_sha256'])
        || !preg_match('/^[a-f0-9]{64}$/', $config['data_liberation']['phar_sha256'])
    ) {
        throw new RuntimeException('runtime_bootstrap_config_invalid');
    }
    return $config;
}

/** @param array{url:string,version:string,archive_sha256:string,phar_sha256:string} $component */
function spacefast_runtime_bootstrap_install_data_liberation(array $component): void
{
    if (!defined('WP_PLUGIN_DIR')) {
        throw new RuntimeException('runtime_bootstrap_plugin_root_missing');
    }
    $pluginFile = 'data-liberation/plugin.php';
    $phar = rtrim((string) WP_PLUGIN_DIR, '/\\') . '/data-liberation/php-toolkit.phar';
    $installedHash = is_file($phar) ? hash_file('sha256', $phar) : false;

    if (!is_string($installedHash) || !hash_equals($component['phar_sha256'], $installedHash)) {
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!class_exists('Plugin_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }
        $archive = download_url($component['url'], 120);
        if (is_wp_error($archive) || !is_string($archive)) {
            throw new RuntimeException('runtime_bootstrap_data_liberation_download_failed');
        }
        try {
            $archiveHash = hash_file('sha256', $archive);
            if (!is_string($archiveHash) || !hash_equals($component['archive_sha256'], $archiveHash)) {
                throw new RuntimeException('runtime_bootstrap_data_liberation_archive_mismatch');
            }
            $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
            $installed = $upgrader->install($archive, ['overwrite_package' => true]);
            if (is_wp_error($installed) || $installed !== true) {
                throw new RuntimeException('runtime_bootstrap_data_liberation_install_failed');
            }
        } finally {
            if (is_file($archive)) {
                unlink($archive);
            }
        }
        if (function_exists('wp_clean_plugins_cache')) {
            wp_clean_plugins_cache(true);
        }
        $installedHash = is_file($phar) ? hash_file('sha256', $phar) : false;
        if (!is_string($installedHash) || !hash_equals($component['phar_sha256'], $installedHash)) {
            throw new RuntimeException('runtime_bootstrap_data_liberation_receipt_mismatch');
        }
    }

    if (function_exists('is_plugin_active') && is_plugin_active($pluginFile)) {
        deactivate_plugins($pluginFile, true);
    }
    if (function_exists('is_plugin_active') && is_plugin_active($pluginFile)) {
        throw new RuntimeException('runtime_bootstrap_data_liberation_active');
    }
}

function spacefast_runtime_bootstrap_public_root(): string
{
    if (!defined('WP_CONTENT_DIR')) {
        throw new RuntimeException('runtime_bootstrap_content_root_missing');
    }
    $publicRoot = dirname(WP_CONTENT_DIR);
    if (!is_dir($publicRoot)) {
        throw new RuntimeException('runtime_bootstrap_public_root_invalid');
    }
    return untrailingslashit($publicRoot);
}

function spacefast_runtime_bootstrap_install(): void
{
    $config = spacefast_runtime_bootstrap_config();
    $installer = __DIR__ . '/installer.php';
    if (!is_file($installer)) {
        throw new RuntimeException('runtime_bootstrap_installer_missing');
    }

    spacefast_runtime_bootstrap_install_data_liberation($config['data_liberation']);

    putenv('SPACEFAST_RUNTIME_ENGINE_MD5=' . $config['md5']);
    putenv('SPACEFAST_RUNTIME_ENGINE_REVISION=' . $config['revision']);
    putenv('SPACEFAST_RUNTIME_ENGINE_NATIVE_SHA256=' . $config['native_sha256']);
    putenv('SPACEFAST_RUNTIME_PUBLIC_ROOT=' . spacefast_runtime_bootstrap_public_root());
    define('SPACEFAST_RUNTIME_INSTALLER_EMBEDDED', true);
    $argv = [$installer, $config['zip_url']];
    require $installer;
}

register_activation_hook(__FILE__, 'spacefast_runtime_bootstrap_install');
