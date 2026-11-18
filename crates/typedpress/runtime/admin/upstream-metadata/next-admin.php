<?php
/**
 * Plugin Name: Zero Admin
 * Description: A plugin that provides a modern admin interface for CIAB.
 * Version: 0.0.0
 * Author: Automattic
 * Author URI: https://automattic.com/
 * Requires at least: 6.8
 * Requires PHP: 7.4
 * Requires Plugins: gutenberg
 * Text Domain: zero-admin
 * Domain Path: /languages
 *
 * @package Zero_Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if current request is for Zero Admin context.
 *
 * @return bool True if this is a Zero Admin request.
 */
function zero_admin_is_ciab_admin_request() {
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	$page        = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Runs before nonce available.

	return str_contains( $request_uri, '/wp-admin/new' ) || 'zero-admin' === $page;
}

// Disable Big Sky agents in Zero Admin context.
// Agents Manager provides the AI experience in CIAB — Big Sky dock should not load.
// This must run early, before admin_enqueue_scripts fires.
if ( zero_admin_is_ciab_admin_request() ) {
	add_filter( 'big_sky_enable_wp_orchestrator_wp_admin_agent', '__return_false', 999 );
	add_filter( 'big_sky_enable_wp_orchestrator_ciab_admin_agent', '__return_false', 999 );
}

/**
 * Include dependencies
 */
require_once __DIR__ . '/lib/load-libs.php';

define( 'ZERO_ADMIN_PLUGIN_REDIRECT_URL', str_replace( home_url(), '', plugin_dir_url( __FILE__ ) ) );

register_activation_hook( __FILE__, 'zero_admin_activate' );
register_deactivation_hook( __FILE__, 'zero_admin_deactivate' );



/**
 * Plugin activation function.
 *
 * Sets up rewrite rules and flushes them on activation.
 */
function zero_admin_activate() {
	zero_admin_add_rewrite_rules();
	flush_rewrite_rules();
}

/**
 * Plugin deactivation function.
 *
 * Cleans up rewrite rules on deactivation.
 */
function zero_admin_deactivate() {
	flush_rewrite_rules();
}

add_action( 'init', 'zero_admin_add_rewrite_rules' );

/**
 * Adds custom rewrite rules for the admin area.
 *
 * Creates a rewrite rule to handle /wp-admin/new URLs and redirect to our custom admin page.
 */
function zero_admin_add_rewrite_rules() {
	$plugin = plugin_basename( __FILE__ );
	// Skip adding rewrite rules if deactivating plugin.
	if (
		is_admin() &&
		isset( $_GET['action'] ) && 'deactivate' === $_GET['action'] &&
		isset( $_GET['plugin'] ) && $plugin === $_GET['plugin'] &&
		check_admin_referer( 'deactivate-plugin_' . $plugin )
	) {
		return;
	}

	add_rewrite_rule(
		'wp-admin/new(/.*)?$',
		'wp-admin/admin.php?page=zero-admin',
		'top'
	);
}

add_filter( 'mod_rewrite_rules', 'zero_admin_remove_qsa_flag' );

/**
 * Removes unwanted QSA flag from the `/wp-admin/new` rewrite rule.
 *
 * @param string $rules The rewrite rules.
 */
function zero_admin_remove_qsa_flag( $rules ) {
	return preg_replace(
		'/^(RewriteRule.*wp-admin\/new.*)\[QSA,L\]$/m',
		'$1[L]',
		$rules
	);
}

add_filter( 'plugin_row_meta', 'zero_admin_plugin_meta_links', 10, 2 );

/**
 * Construct an URL for a Zero Admin page. It's abstracted so that it can possibly be switched
 * to pretty permalinks if the site is set up accordingly.
 *
 * @param string $path Optional. Zero Admin route. Default '/'.
 */
function zero_admin_url( $path = '/' ) {
	// Pretty permalinks are supported but currently disabled.
	// Can be switched to `return admin_url( 'new' . $path )`.
	return add_query_arg( 'p', $path, admin_url( 'admin.php?page=zero-admin' ) );
}

/**
 * Add relevant plugin meta links, like one to the next admin dashboard
 *
 * @param string[] $links Plugin metadata.
 * @param string   $file  Path to plugin file.
 */
function zero_admin_plugin_meta_links( $links, $file ) {
	if ( plugin_basename( __FILE__ ) === $file ) {
		$links[] = '<a href="' . zero_admin_url( '/' ) . '">' . __( 'Go to Dashboard', 'zero-admin' ) . '</a>';
	}
	return $links;
}

add_action( 'admin_menu', 'zero_admin_menu_page' );

/**
 * Adds a dummy wp-admin menu page for the next admin.
 * Not actually used as a menu page; we redirect from it to the actual dashboard.
 *
 * @see zero_admin_menu_redirect()
 */
function zero_admin_menu_page() {
	add_menu_page(
		__( 'Zero Admin', 'zero-admin' ),
		__( 'Zero Admin', 'zero-admin' ),
		'read',
		'zero-admin',
		'__return_null',
		'dashicons-dashboard'
	);
}

add_action( 'admin_init', 'zero_admin_render_page' );
add_action( 'admin_init', 'zero_admin_handle_iframe_mode' );

/**
 * Handles redirection from the dummy menu page
 */
function zero_admin_render_page() {
	if ( filter_input( INPUT_GET, 'page' ) === 'zero-admin' ) {
		$new_admin_request_uri = wp_parse_url( admin_url( '/' ), PHP_URL_PATH ) . 'new/';
		// When visiting `admin.php?page=zero-admin` with no route, redirects to the `/` route, either in plain or pretty format.
		if (
			// Use $_SERVER['REQUEST_URI'] instead of filter_input( INPUT_SERVER, 'REQUEST_URI' ) as the latter may return null (e.g. on FastCGI).
			// Unsafe - never output this variable, only usable for comparisons.
			// If we are handling a pretty URL then we have a route.
			// phpcs:ignore
			! ( isset( $_SERVER['REQUEST_URI'] ) &&
			// phpcs:ignore
			str_starts_with( $_SERVER['REQUEST_URI'], $new_admin_request_uri ) ) &&
			// Otherwise if there is a `p` query param, then we have a route, too.
			! filter_input( INPUT_GET, 'p' )
		) {
			wp_safe_redirect( zero_admin_url( '/' ) );
			exit;
		}

		require plugin_dir_path( __FILE__ ) . '/lib/pages/admin.php';
		exit;
	}
}

/**
 * Configure layout views for core WordPress entities.
 */
add_filter(
	'zero_admin_entity_view_config_root_user',
	function ( $config ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		// Return complete configuration object for users.
		return array(
			'labels'          => array(
				'title'            => __( 'Users', 'zero-admin' ),
				'description'      => __( 'Manage user accounts and permissions', 'zero-admin' ),
				'add_button_label' => __( 'Add New User', 'zero-admin' ),
			),
			'default_view'    => array(
				'type'       => 'table',
				'search'     => '',
				'filters'    => array(),
				'perPage'    => 20,
				'mediaField' => 'avatar',
				'titleField' => 'username',
				'fields'     => array(
					'email',
					'roles',
					'posts',
				),
				'showLevels' => false,
				'showMedia'  => true,
			),
			'default_layouts' => array(
				'table' => array(
					'primaryField' => 'username',
					'density'      => 'comfortable',
				),
			),
			'quick_edit_form' => array(
				'layout' => array( 'type' => 'panel' ),
				'fields' => array(
					'username',
					'first_name',
					'last_name',
					'nickname',
					'email',
					'url',
					'bio',
					'roles',
				),
			),
			'add_form'        => array(
				'layout' => array(
					'type'          => 'card',
					'labelPosition' => 'top',
					'isCollapsible' => false,
				),
				'fields' => array(
					array(
						'id'       => 'user_info',
						'label'    => __( 'User details', 'zero-admin' ),
						'children' => array(
							array(
								'id'       => 'user_email',
								'children' => array( 'username', 'email' ),
							),
							array(
								'id'       => 'user_names',
								'children' => array( 'first_name', 'last_name' ),
							),
							'url',
							'password',
							'confirm_password',
							'roles',
						),
					),
				),
			),
			'edit_form'       => array(
				'layout' => array(
					'type'          => 'card',
					'labelPosition' => 'top',
					'isCollapsible' => false,
				),
				'fields' => array(
					array(
						'id'       => 'user_info',
						'label'    => __( 'User details', 'zero-admin' ),
						'children' => array(
							'avatar',
							'username',
							array(
								'id'       => 'user_names',
								'children' => array( 'first_name', 'last_name' ),
							),
							array(
								'id'       => 'user_nicknames',
								'children' => array( 'nickname', 'display_name' ),
							),
							array(
								'id'       => 'contact_info',
								'children' => array( 'email', 'url' ),
							),
							'roles',
							'bio',
						),
					),
				),
			),
			'actions'         => array( 'quick_edit', 'full_edit', 'delete', 'update_role' ),
			'tabs'            => array(
				array(
					'slug'    => 'all',
					'label'   => __( 'All', 'zero-admin' ),
					'filters' => array(),
				),
				array(
					'slug'    => 'administrator',
					'label'   => __( 'Administrator', 'zero-admin' ),
					'filters' => array(
						array(
							'field'    => 'roles',
							'operator' => 'is',
							'value'    => 'administrator',
						),
					),
				),
				array(
					'slug'    => 'editor',
					'label'   => __( 'Editor', 'zero-admin' ),
					'filters' => array(
						array(
							'field'    => 'roles',
							'operator' => 'is',
							'value'    => 'editor',
						),
					),
				),
				array(
					'slug'    => 'author',
					'label'   => __( 'Author', 'zero-admin' ),
					'filters' => array(
						array(
							'field'    => 'roles',
							'operator' => 'is',
							'value'    => 'author',
						),
					),
				),
				array(
					'slug'    => 'contributor',
					'label'   => __( 'Contributor', 'zero-admin' ),
					'filters' => array(
						array(
							'field'    => 'roles',
							'operator' => 'is',
							'value'    => 'contributor',
						),
					),
				),
				array(
					'slug'    => 'subscriber',
					'label'   => __( 'Subscriber', 'zero-admin' ),
					'filters' => array(
						array(
							'field'    => 'roles',
							'operator' => 'is',
							'value'    => 'subscriber',
						),
					),
				),
			),
		);
	}
);

/**
 * Register taxonomy view configuration for a specific taxonomy slug.
 *
 * @param string $taxonomy Taxonomy slug.
 */
function zero_admin_register_taxonomy_view_config( $taxonomy ) {
	static $registered_taxonomy_view_configs = array();

	if ( isset( $registered_taxonomy_view_configs[ $taxonomy ] ) ) {
		return;
	}

	$registered_taxonomy_view_configs[ $taxonomy ] = true;

	add_filter(
		"zero_admin_entity_view_config_taxonomy_{$taxonomy}",
		function ( $config ) use ( $taxonomy ) {
			$taxonomy_object = get_taxonomy( $taxonomy );

			if ( ! $taxonomy_object ) {
				return $config;
			}

			return array(
				'default_view'    => array(
					'type'             => 'table',
					'filters'          => array(),
					'perPage'          => 20,
					'sort'             => array(
						'field'     => 'slug',
						'direction' => 'asc',
					),
					'fields'           => array( 'slug', 'count' ),
					'titleField'       => 'name',
					'descriptionField' => 'description',
					'showDescription'  => true,
					'showLevels'       => ! empty( $taxonomy_object->hierarchical ),
				),
				'default_layouts' => array(
					'table' => array(
						'primaryField' => 'name',
						'density'      => 'comfortable',
					),
				),
				'quick_edit_form' => array(
					'layout' => array( 'type' => 'regular' ),
					'fields' => array( 'name', 'description', 'slug' ),
				),
				'edit_form'       => array(
					'layout' => array(
						'type' => 'card',
					),
					'fields' => array(
						array(
							'id'       => 'term_details',
							'layout'   => array(
								'type' => 'card',
							),
							/* translators: %s: Taxonomy singular name. */
							'label'    => sprintf( __( '%s details', 'zero-admin' ), $taxonomy_object->labels->singular_name ?? $taxonomy ),
							'children' => array( 'name', 'description', 'parent' ),
						),
						array(
							'id'       => 'additional_options',
							'layout'   => array(
								'type' => 'card',
							),
							'label'    => __( 'Additional options', 'zero-admin' ),
							'children' => array( 'slug' ),
						),
					),
				),
				'add_form'        => array(
					'layout' => array(
						'type' => 'card',
					),
					'fields' => array(
						array(
							'id'       => 'term_details',
							'layout'   => array(
								'type' => 'card',
							),
							/* translators: %s: Taxonomy singular name. */
							'label'    => sprintf( __( '%s details', 'zero-admin' ), $taxonomy_object->labels->singular_name ?? $taxonomy ),
							'children' => array( 'name', 'description', 'parent' ),
						),
						array(
							'id'       => 'additional_options',
							'layout'   => array(
								'type' => 'card',
							),
							'label'    => __( 'Additional options', 'zero-admin' ),
							'children' => array( 'slug' ),
						),
					),
				),
				'actions'         => array( 'quick_edit', 'full_edit', 'delete' ),
				'tabs'            => array(),
			);
		}
	);
}

add_action(
	'registered_taxonomy',
	/**
	 * Register taxonomy view config when a taxonomy is created.
	 *
	 * @param string $taxonomy Newly registered taxonomy slug.
	 */
	function ( $taxonomy ) {
		zero_admin_register_taxonomy_view_config( $taxonomy );
	}
);
