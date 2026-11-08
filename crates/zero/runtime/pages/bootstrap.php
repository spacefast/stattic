<?php
/**
 * Zero pages runtime, copied into each generated `zero-pages-<app>` plugin.
 *
 * Self-contained on purpose: Zero core does not depend on the optional pages
 * add-on, and the add-on uses only Zero's public runtime seams (Runtime::plugin,
 * Db reads with a Viewer, Connection locks/transactions, Extensions::onWrite).
 */
declare(strict_types=1);
defined('ABSPATH') || exit;

foreach (['Request', 'Renderer', 'Routes', 'Plugin'] as $zeroPagesClass) {
    if (!class_exists('Zero\\Pages\\' . $zeroPagesClass, false)) {
        require_once __DIR__ . '/' . $zeroPagesClass . '.php';
    }
}
unset($zeroPagesClass);
