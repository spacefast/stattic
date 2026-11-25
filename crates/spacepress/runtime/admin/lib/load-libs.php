<?php
/**
 * SpacePress Admin load Libraries.
 *
 * This file loads all the libraries and registration needed for SpacePress Admin.
 * Loaded for the admin page and for REST requests alike.
 *
 * @package SpacePress_Admin
 */

define( 'SP_ADMIN_PLUGIN_DIR', plugin_dir_path( __DIR__ ) );
define( 'SP_ADMIN_PLUGIN_URL', plugin_dir_url( __DIR__ ) );

// Registration APIs.
require_once __DIR__ . '/api/dashboard-context.php';
require_once __DIR__ . '/api/dashboard-widgets.php';
require_once __DIR__ . '/api/dashboard-layout.php';
require_once __DIR__ . '/api/field-collections.php';
require_once __DIR__ . '/api/view-config.php';
require_once __DIR__ . '/view-configs.php';
require_once __DIR__ . '/api/menu-items.php';
require_once __DIR__ . '/api/init-modules.php';
require_once __DIR__ . '/api/routes.php';

// Core and plugins can request a controller before REST initializes (for example, for attachments).
spl_autoload_register(
	static function ( $class ) {
		static $files = array(
			'spacepress_admin_rest_attachments_controller'       => 'class-spacepress-admin-rest-attachments-controller.php',
			'spacepress_admin_rest_field_collections_controller' => 'class-spacepress-admin-rest-field-collections-controller.php',
			'spacepress_admin_rest_view_config_controller'       => 'class-spacepress-admin-rest-view-config-controller.php',
			'spacepress_admin_rest_editor_controller'            => 'class-spacepress-admin-rest-editor-controller.php',
			'spacepress_admin_rest_themes_controller'            => 'class-wp-rest-themes-controller.php',
			'spacepress_admin_rest_plugins_controller'           => 'class-spacepress-admin-rest-plugins-controller.php',
			'spacepress_admin_rest_resolved_template_controller' => 'class-spacepress-admin-rest-resolved-template-controller.php',
		);
		$key = strtolower( $class );
		if ( isset( $files[ $key ] ) ) {
			require_once __DIR__ . '/rest-api/' . $files[ $key ];
		}
	}
);

add_action(
	'rest_api_init',
	static function () {
		( new SpacePress_Admin_REST_Field_Collections_Controller() )->register_routes();
		( new SpacePress_Admin_REST_View_Config_Controller() )->register_routes();
		( new SpacePress_Admin_REST_Editor_Controller() )->register_routes();
	}
);
require_once __DIR__ . '/rest-api/core-rest-api-overrides.php';

add_action(
	'rest_api_init',
	static function () {
		( new SpacePress_Admin_REST_Themes_Controller() )->register_routes();
		( new SpacePress_Admin_REST_Plugins_Controller() )->register_routes();
		( new SpacePress_Admin_REST_Resolved_Template_Controller() )->register_routes();
	}
);
require_once __DIR__ . '/rest-api/plugins-rest-api-overrides.php';

// Core Entities overrides.
require_once __DIR__ . '/post.php';
require_once __DIR__ . '/taxonomies.php';
require_once __DIR__ . '/taxonomy-hierarchy.php';
require_once __DIR__ . '/site-preview.php';

// i18n support in SpacePress Admin.
require_once __DIR__ . '/i18n.php';

// Classic wp-admin pages framed in SpacePress Admin (/classic/<slug>), and developer mode.
require_once __DIR__ . '/classic-route.php';

// Default SpacePress Admin menu registration.
require_once __DIR__ . '/spacepress-admin-menu.php';
require_once __DIR__ . '/menu.php';

// Screens declared once for both admins.
require_once __DIR__ . '/screens.php';

// Composable dashboards and declarative widgets.
require_once __DIR__ . '/dashboards.php';

// Build output: registers the modules, routes and fields. Absent until the build is committed.
if ( is_file( __DIR__ . '/../build/index.php' ) ) {
	require_once __DIR__ . '/../build/index.php';
}
