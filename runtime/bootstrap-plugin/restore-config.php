<?php
/** CLI entrypoint: file execution preserves the provider's auto_prepend_file. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('WP_CLI', true);
define('WP_CONTENT_DIR', getcwd() . '/wp-content');
require __DIR__ . '/spacefast-bootstrap.php';

try {
    $config = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
    $status = spacefast_bootstrap_reconcile_config($config);
    $persistentStorageKeys = [];
    foreach (new Atomic_Persistent_Data() as $key => $value) {
        if (is_string($key) && spacefast_bootstrap_is_shared_storage_key($key)) {
            $persistentStorageKeys[] = $key;
        }
    }
    echo json_encode(['status' => $status, 'persistent_storage_keys' => $persistentStorageKeys]);
} catch (SpacefastBootstrapConfigError $error) {
    echo json_encode(['error' => $error->getMessage()]);
    exit(1);
} catch (Throwable $error) {
    // Provider exceptions and input errors can carry credentials.
    echo json_encode(['error' => 'bootstrap_config_restore_failed']);
    exit(1);
}
