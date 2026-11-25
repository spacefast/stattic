<?php
/**
 * Zero Admin load Libraries.
 *
 * This file loads all the libraries and registration needed for Zero Admin.
 * This file is loaded both in the next admin page but also for REST APIs.
 *
 * @package Zero_Admin
 */

define( 'ZERO_ADMIN_PLUGIN_DIR', plugin_dir_path( __DIR__ ) );
define( 'ZERO_ADMIN_PLUGIN_URL', plugin_dir_url( __DIR__ ) );

// Registration APIs.
require_once __DIR__ . '/api/dashboard-layout.php';
require_once __DIR__ . '/api/dashboard-widgets.php';
require_once __DIR__ . '/api/dashboard-context.php';
require_once __DIR__ . '/api/field-collections.php';
require_once __DIR__ . '/api/view-config.php';
require_once __DIR__ . '/api/menu-items.php';
require_once __DIR__ . '/api/init-modules.php';
require_once __DIR__ . '/api/routes.php';

// REST API overrides and endpoints.
require_once __DIR__ . '/rest-api/class-zero-admin-rest-attachments-controller.php';
require_once __DIR__ . '/rest-api/class-wp-rest-themes-controller.php';
require_once __DIR__ . '/rest-api/class-zero-admin-rest-plugins-controller.php';
require_once __DIR__ . '/rest-api/class-zero-admin-rest-field-collections-controller.php';
require_once __DIR__ . '/rest-api/class-zero-admin-rest-view-config-controller.php';
require_once __DIR__ . '/rest-api/class-zero-admin-rest-editor-controller.php';
require_once __DIR__ . '/rest-api/class-zero-admin-rest-resolved-template-controller.php';
require_once __DIR__ . '/rest-api/core-rest-api-overrides.php';
require_once __DIR__ . '/rest-api/plugins-rest-api-overrides.php';

// Core Entities overrides.
require_once __DIR__ . '/post.php';
require_once __DIR__ . '/taxonomies.php';
require_once __DIR__ . '/taxonomy-hierarchy.php';

// i18n support in Zero Admin.
require_once __DIR__ . '/i18n.php';

// Legacy routes support in Zero Admin.
require_once __DIR__ . '/legacy-route.php';

// Default Zero Admin menu registration.
require_once __DIR__ . '/zero-admin-menu.php';

// Site preview support (theme preview without admin bar).
require_once __DIR__ . '/site-preview.php';

// WP-Build output (registering default routes, modules, fields, dashboards...).
require_once __DIR__ . '/../build/index.php';

// Load Abilities API if not already present through Composer autoload.
if ( file_exists( dirname( __DIR__ ) . '/vendor/autoload.php' ) ) {
	require_once dirname( __DIR__ ) . '/vendor/autoload.php';
}
