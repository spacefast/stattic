<?php
/**
 * Layout View Config Filter System
 *
 * This file provides the filter-based API for layout view configs in Zero Admin.
 * Layout view configs contain entity configurations like default_view, default_layouts,
 * quick_edit_form, views, etc.
 *
 * @package ZeroAdmin
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
function zero_admin_get_entity_view_config( $kind, $name, $variant = null ) {
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
	$config = apply_filters( "zero_admin_entity_view_config_{$kind}_{$name}", $config, $kind, $name );

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
		$config = apply_filters( "zero_admin_entity_view_config_{$kind}_{$name}_{$variant}", $config, $kind, $name, $variant );
	}

	return $config;
}
