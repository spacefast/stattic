<?php
/**
 * Field Collections Registration System
 *
 * This file provides the API for registering field collections in Zero Admin.
 *
 * @package ZeroAdmin
 */

// Global storage for registered field collections.
global $zero_admin_field_collections;
$zero_admin_field_collections = array();

/**
 * Register a field collection for Zero Admin.
 *
 * This function registers a field collection that will be available
 * for specific entities (postType, taxonomy, user, etc.).
 *
 * @since 1.0.0
 *
 * @param string      $id            The collection identifier (e.g., 'core/media-fields').
 * @param string      $kind          The entity kind (postType, taxonomy, user, etc.).
 * @param string|null $name          The entity name (attachment, product, etc.) or null for universal.
 * @param array       $fields        Array of field definitions keyed by field ID.
 * @param string|null $fields_module The script module handle for field extensions.
 */
function zero_admin_register_field_collection( $id, $kind, $name, $fields, $fields_module = null ) {
	global $zero_admin_field_collections;

	if ( empty( $id ) || empty( $kind ) || empty( $fields ) ) {
		_doing_it_wrong( __FUNCTION__, 'Field collection must have "id", "kind", and "fields".', '1.0.0' );
		return;
	}

	// Check if collection with same ID already exists.
	foreach ( $zero_admin_field_collections as $existing_collection ) {
		if ( $existing_collection['id'] === $id ) {
			return;
		}
	}

	$collection = array(
		'id'            => $id,
		'kind'          => $kind,
		'name'          => $name,
		'fields'        => $fields,
		'fields_module' => $fields_module,
	);

	$zero_admin_field_collections[] = $collection;
}

/**
 * Get field collections for a specific entity kind and name.
 *
 * @param string $kind Entity kind (postType, taxonomy, user, etc.).
 * @param string $name Entity name (attachment, product, etc.).
 * @return array Array of matching collections.
 */
function zero_admin_get_field_collections( $kind, $name ) {
	global $zero_admin_field_collections;

	$matching_collections = array();

	foreach ( $zero_admin_field_collections as $collection ) {
		// Must match kind.
		if ( $collection['kind'] !== $kind ) {
			continue;
		}

		// Must match specific name or be universal (collection name is null).
		if ( $collection['name'] === $name || null === $collection['name'] ) {
			$matching_collections[] = $collection;
		}
	}

	return $matching_collections;
}

/**
 * Get all registered field collections.
 *
 * @return array Array of all registered collections.
 */
function zero_admin_get_all_field_collections() {
	global $zero_admin_field_collections;
	return $zero_admin_field_collections ?? array();
}
