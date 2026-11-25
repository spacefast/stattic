<?php
/**
 * Layout View Config Filter System
 *
 * This file provides the filter-based API for layout view configs in SpacePress Admin.
 * Layout view configs contain entity configurations like default_view, default_layouts,
 * quick_edit_form, views, etc.
 *
 * @package SpacePressAdmin
 */

/**
 * Get the configuration for a specific entity.
 *
 * This function retrieves the configuration for an entity by applying filters,
 * allowing plugins to modify the configuration.
 *
 * @since 1.0.0
 *
 * @param string      $kind    Entity kind (postType, taxonomy, root, etc.).
 * @param string      $name    Entity name (post, product, user, etc.).
 * @param string|null $variant Optional. View config identifier for variant-specific configurations. Default null.
 * @return array Configuration array.
 */
function sp_admin_get_entity_view_config( $kind, $name, $variant = null ) {
	// Start with default configuration structure.
	$config = array(
		'default_view'    => null,
		'default_layouts' => null,
		'quick_edit_form' => null,
		'add_form'        => null,
		'edit_form'       => null,
		'tabs'            => array(),
	);

	// Sanitize kind and name for use in dynamic hook names (defense in depth).
	// Note: sanitize_key() cannot be used here because it lowercases the value,
	// which breaks camelCase entity kinds like 'postType'.
	$kind = preg_replace( '/[^a-zA-Z0-9_-]/', '', $kind );
	$name = preg_replace( '/[^a-zA-Z0-9_-]/', '', $name );

	// Sanitize variant string.
	$variant = $variant ? preg_replace( '/[^a-z0-9_\/-]/', '', $variant ) : null;
	if ( ! $variant ) {
		$variant = null; // Normalize to null if invalid.
	}

	/**
	 * Filter entity layout view configuration.
	 *
	 * The dynamic portions of the hook name, `$kind` and `$name`, refer to
	 * the entity kind (postType, taxonomy, root, etc.) and entity name
	 * (post, product, user, etc.) respectively.
	 *
	 * @since 1.0.0
	 *
	 * @param array       $config  The configuration array.
	 * @param string      $kind    Entity kind.
	 * @param string      $name    Entity name.
	 */
	$config = apply_filters( "sp_admin_entity_view_config_{$kind}_{$name}", $config, $kind, $name );

	// If variant is provided, also apply variant-specific filter for more granular control.
	if ( $variant ) {
		/**
		 * Filter entity layout view configuration for a specific variant.
		 *
		 * This filter allows plugins to override configuration for specific view config identifiers
		 * within an entity.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $config  The configuration array (already filtered by entity).
		 * @param string $kind    Entity kind.
		 * @param string $name    Entity name.
		 * @param string $variant View config identifier.
		 */
		$config = apply_filters( "sp_admin_entity_view_config_{$kind}_{$name}_{$variant}", $config, $kind, $name, $variant );
	}

	return sp_admin_compose_core_view_config( $config, $kind, $name );
}

/**
 * SpacePress: WordPress 7.1's view config filter (`get_entity_view_config_{kind}_{name}`, through
 * WP_View_Config_Data) runs over SpacePress Admin's configuration, so a plugin that patches an entity's
 * view with core's API changes it here too. The shapes map one to one where they overlap:
 *
 * | SpacePress Admin       | core                                        |
 * |-------------------|---------------------------------------------|
 * | `default_view`    | `default_view`                              |
 * | `default_layouts` | `default_layouts`                           |
 * | `tabs`            | `view_list` (`label` ↔ `title`, `filters` ↔ `view.filters`) |
 * | `edit_form`       | `form`                                      |
 *
 * `quick_edit_form`, `add_form`, `labels` and `actions` have no core counterpart and pass through.
 *
 * @param array  $config SpacePress Admin's configuration.
 * @param string $kind   Entity kind.
 * @param string $name   Entity name.
 * @return array
 */
function sp_admin_compose_core_view_config( $config, $kind, $name ) {
	if ( ! class_exists( 'WP_View_Config_Data' ) ) {
		return $config;
	}
	$tabs = array();
	foreach ( (array) ( $config['tabs'] ?? array() ) as $tab ) {
		// The tab's other keys (a `default_view`, say) ride along and come back unchanged.
		$tabs[] = array(
			'slug'  => $tab['slug'] ?? '',
			'title' => $tab['label'] ?? ( $tab['slug'] ?? '' ),
			'view'  => array( 'filters' => $tab['filters'] ?? array() ),
			'spacepress' => array_diff_key( (array) $tab, array( 'slug' => 1, 'label' => 1, 'filters' => 1 ) ),
		);
	}
	$data = new WP_View_Config_Data(
		array(
			'default_view'    => (array) ( $config['default_view'] ?? array() ),
			'default_layouts' => (array) ( $config['default_layouts'] ?? array() ),
			'view_list'       => $tabs,
			'form'            => (array) ( $config['edit_form'] ?? array() ),
		)
	);
	$core = $data->apply_filters( $kind, $name );
	$back = static fn ( $value ) => is_array( $value ) && array() === $value ? null : $value;
	$config['default_view']    = $back( $core['default_view'] ?? array() );
	$config['default_layouts'] = $back( $core['default_layouts'] ?? array() );
	$config['edit_form']       = $back( $core['form'] ?? array() );
	$config['tabs']            = array_map(
		static fn ( $item ) => array_merge(
			(array) ( $item['spacepress'] ?? array() ),
			// A view another plugin added through core keeps its own `view` (type, layout) beside it.
			isset( $item['spacepress'] ) ? array() : array( 'view' => $item['view'] ?? array() ),
			array(
				'slug'    => $item['slug'] ?? '',
				'label'   => $item['title'] ?? ( $item['slug'] ?? '' ),
				'filters' => $item['view']['filters'] ?? array(),
			)
		),
		array_values( (array) ( $core['view_list'] ?? array() ) )
	);
	return $config;
}
