<?php
/**
 * View configurations for core WordPress entities: users and taxonomies.
 *
 * From the upstream bootstrap (upstream-metadata/next-admin.php), names normalized; the admin page
 * and the user and term routes read them through the view-config route.
 *
 * @package SpacePress_Admin
 */

/**
 * Configure layout views for core WordPress entities.
 */
add_filter(
	'sp_admin_entity_view_config_root_user',
	function ( $config ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		// Return complete configuration object for users.
		return array(
			'labels'          => array(
				'title'            => __( 'Users', 'spacepress-admin' ),
				'description'      => __( 'Manage user accounts and permissions', 'spacepress-admin' ),
				'add_button_label' => __( 'Add New User', 'spacepress-admin' ),
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
						'label'    => __( 'User details', 'spacepress-admin' ),
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
						'label'    => __( 'User details', 'spacepress-admin' ),
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
					array(
						'id'       => 'admin_preferences',
						'label'    => __( 'Admin', 'spacepress-admin' ),
						'children' => ( class_exists( '\\SpacePress\\Wp\\Runtime' ) && \SpacePress\Wp\Runtime::managed() )
							? array()
							: array( 'sp_admin_developer_mode' ),
					),
				),
			),
			'actions'         => array( 'quick_edit', 'full_edit', 'delete', 'update_role' ),
			'tabs'            => array(
				array(
					'slug'    => 'all',
					'label'   => __( 'All', 'spacepress-admin' ),
					'filters' => array(),
				),
				array(
					'slug'    => 'administrator',
					'label'   => __( 'Administrator', 'spacepress-admin' ),
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
					'label'   => __( 'Editor', 'spacepress-admin' ),
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
					'label'   => __( 'Author', 'spacepress-admin' ),
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
					'label'   => __( 'Contributor', 'spacepress-admin' ),
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
					'label'   => __( 'Subscriber', 'spacepress-admin' ),
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
function sp_admin_register_taxonomy_view_config( $taxonomy ) {
	static $registered_taxonomy_view_configs = array();

	if ( isset( $registered_taxonomy_view_configs[ $taxonomy ] ) ) {
		return;
	}

	$registered_taxonomy_view_configs[ $taxonomy ] = true;

	add_filter(
		"sp_admin_entity_view_config_taxonomy_{$taxonomy}",
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
							'label'    => sprintf( __( '%s details', 'spacepress-admin' ), $taxonomy_object->labels->singular_name ?? $taxonomy ),
							'children' => array( 'name', 'description', 'parent' ),
						),
						array(
							'id'       => 'additional_options',
							'layout'   => array(
								'type' => 'card',
							),
							'label'    => __( 'Additional options', 'spacepress-admin' ),
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
							'label'    => sprintf( __( '%s details', 'spacepress-admin' ), $taxonomy_object->labels->singular_name ?? $taxonomy ),
							'children' => array( 'name', 'description', 'parent' ),
						),
						array(
							'id'       => 'additional_options',
							'layout'   => array(
								'type' => 'card',
							),
							'label'    => __( 'Additional options', 'spacepress-admin' ),
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
		sp_admin_register_taxonomy_view_config( $taxonomy );
	}
);
