<?php
/**
 * Zero Admin SPA
 *
 * This file provides the API for registering admin menu items in the Zero Admin SPA.
 *
 * @package Zero_Admin
 */

// Global storage for menu items.
global $zero_admin_menu_items, $zero_admin_meta_menu_items;
$zero_admin_menu_items      = array();
$zero_admin_meta_menu_items = array();

/**
 * Register a menu item for the Zero Admin SPA.
 *
 * @since 1.0.0
 *
 * @param string $id The unique identifier for the menu item.
 * @param array  $args {
 *     Menu item arguments.
 *
 *     @type string      $to The route path this menu item links to (e.g., '/settings/general').
 *     @type string      $label The label for the menu item (e.g., 'General Settings').
 *     @type string|null $icon Optional. The icon identifier for the menu item.
 *     @type string|int|null $badge Optional. The badge content to display next to the menu item.
 *     @type string|null $parent Optional. The unique ID of the parent menu item, if this is a sub-menu item.
 *     @type string      $parent_type Optional. How parent items with children should behave: 'drilldown' or 'dropdown'. Defaults to 'drilldown'.
 *     @type int|null    $position Optional. The position/order of the menu item. Lower numbers appear first. Defaults to null (appears as they are registered).
 * }
 * @param bool   $is_meta Optional. Whether this is a meta menu item (default: false). Meta items are displayed in the footer or header of the sidebar.
 */
function zero_admin_register_menu_item( $id, $args, $is_meta = false ) {
	global $zero_admin_menu_items, $zero_admin_meta_menu_items;

	$defaults = array(
		'to'          => '',
		'label'       => '',
		'icon'        => null,
		'badge'       => null,
		'parent'      => null,
		'parent_type' => null,
		'position'    => null,
	);

	$args = wp_parse_args( $args, $defaults );

	if ( ( empty( $args['to'] ) && empty( $args['parent_type'] ) ) ) {
		_doing_it_wrong( __FUNCTION__, 'Menu item must have both "to" or "parent_type" arguments.', '1.0.0' );
		return;
	}

	$menu_item = array(
		'id'          => $id,
		'to'          => $args['to'],
		'label'       => $args['label'],
		'parent_type' => $args['parent_type'],
		'position'    => $args['position'],
	);

	if ( ! empty( $args['icon'] ) ) {
		$menu_item['icon'] = $args['icon'];
	}

	if ( array_key_exists( 'badge', $args ) && null !== $args['badge'] ) {
		$menu_item['badge'] = $args['badge'];
	}

	if ( ! empty( $args['parent'] ) ) {
		$menu_item['parent'] = $args['parent'];
	}

	if ( $is_meta ) {
		$zero_admin_meta_menu_items[] = $menu_item;
	} else {
		$zero_admin_menu_items[] = $menu_item;
	}
}

/**
 * Deregister a menu item from the Zero Admin SPA.
 *
 * @since 1.0.0
 *
 * @param string $id The unique identifier of the menu item to remove.
 * @param bool   $is_meta Optional. Whether this is a meta menu item (default: false).
 * @return bool True if the menu item was found and removed, false otherwise.
 */
function zero_admin_deregister_menu_item( $id, $is_meta = false ) {
	global $zero_admin_menu_items, $zero_admin_meta_menu_items;

	if ( $is_meta ) {
		$menu_items = &$zero_admin_meta_menu_items;
	} else {
		$menu_items = &$zero_admin_menu_items;
	}

	foreach ( $menu_items as $index => $menu_item ) {
		if ( isset( $menu_item['id'] ) && $menu_item['id'] === $id ) {
			unset( $menu_items[ $index ] );
			// Re-index the array to avoid gaps.
			$menu_items = array_values( $menu_items );
			return true;
		}
	}

	return false;
}

/**
 * Switch/replace a menu item with another one in the Zero Admin SPA.
 *
 * This function removes an existing menu item and replaces it with a new one.
 * Useful for customizing existing menu items while maintaining their position.
 *
 * @since 1.0.0
 *
 * @param string $old_id The unique identifier of the menu item to replace.
 * @param string $new_id The unique identifier for the replacement menu item.
 * @param array  $new_args {
 *     Menu item arguments for the replacement.
 *
 *     @type string          $to The route path this menu item links to (e.g., '/settings/general').
 *     @type string          $label The label for the menu item (e.g., 'General Settings').
 *     @type string|null     $icon Optional. The icon identifier for the menu item.
 *     @type string|int|null $badge Optional. The badge content to display next to the menu item.
 *     @type string|null     $parent Optional. The unique ID of the parent menu item, if this is a sub-menu item.
 *     @type string          $parent_type Optional. How parent items with children should behave: 'drilldown' or 'dropdown'. Defaults to 'drilldown'.
 *     @type int|null        $position Optional. The position/order of the menu item. Lower numbers appear first. Defaults to null (appears as they are registered).
 * }
 * @param bool   $is_meta Optional. Whether this is a meta menu item (default: false).
 * @return bool True if the menu item was found and replaced, false otherwise.
 */
function zero_admin_switch_menu_item( $old_id, $new_id, $new_args, $is_meta = false ) {
	global $zero_admin_menu_items, $zero_admin_meta_menu_items;

	if ( $is_meta ) {
		$menu_items = &$zero_admin_meta_menu_items;
	} else {
		$menu_items = &$zero_admin_menu_items;
	}

	// Find the position of the old menu item.
	$position = false;
	foreach ( $menu_items as $index => $menu_item ) {
		if ( isset( $menu_item['id'] ) && $menu_item['id'] === $old_id ) {
			$position = $index;
			break;
		}
	}

	// If old item not found, return false.
	if ( false === $position ) {
		return false;
	}

	// Prepare the new menu item.
	$defaults = array(
		'to'          => '',
		'label'       => '',
		'icon'        => null,
		'badge'       => null,
		'parent'      => null,
		'parent_type' => null,
		'position'    => null,
	);

	$new_args = wp_parse_args( $new_args, $defaults );

	$new_menu_item = array(
		'id'          => $new_id,
		'to'          => $new_args['to'],
		'label'       => $new_args['label'],
		'parent_type' => $new_args['parent_type'],
		'position'    => $new_args['position'],
	);

	if ( ! empty( $new_args['icon'] ) ) {
		$new_menu_item['icon'] = $new_args['icon'];
	}

	if ( array_key_exists( 'badge', $new_args ) && null !== $new_args['badge'] ) {
		$new_menu_item['badge'] = $new_args['badge'];
	}

	if ( ! empty( $new_args['parent'] ) ) {
		$new_menu_item['parent'] = $new_args['parent'];
	}

	// Replace the old menu item with the new one at the same position.
	$menu_items[ $position ] = $new_menu_item;

	return true;
}

/**
 * Get all registered admin menu items.
 *
 * @return array Array of registered admin menu items, sorted by position.
 */
function zero_admin_get_admin_menu_items() {
	global $zero_admin_menu_items;

	// Sort menu items by position (null positions go to the end).
	$sorted_items = $zero_admin_menu_items;
	usort(
		$sorted_items,
		function ( $a, $b ) {
			$pos_a = isset( $a['position'] ) ? $a['position'] : PHP_INT_MAX;
			$pos_b = isset( $b['position'] ) ? $b['position'] : PHP_INT_MAX;

			if ( $pos_a === $pos_b ) {
				return 0;
			}
			return ( $pos_a < $pos_b ) ? -1 : 1;
		}
	);

	/**
	 * Filter the menu items before they are passed to JavaScript.
	 *
	 * Allows plugins to modify menu items (e.g., change routes, labels, etc.)
	 * before they are sent to the client.
	 *
	 * @since 1.0.0
	 *
	 * @param array $sorted_items The sorted menu items array.
	 */
	return apply_filters( 'zero_admin_get_admin_menu_items', $sorted_items );
}

/**
 * Get all registered admin meta menu items.
 *
 * @return array Array of registered admin meta menu items, sorted by position.
 */
function zero_admin_get_admin_meta_menu_items() {
	global $zero_admin_meta_menu_items;

	// Sort meta menu items by position (null positions go to the end).
	$sorted_items = $zero_admin_meta_menu_items;
	usort(
		$sorted_items,
		function ( $a, $b ) {
			$pos_a = isset( $a['position'] ) ? $a['position'] : PHP_INT_MAX;
			$pos_b = isset( $b['position'] ) ? $b['position'] : PHP_INT_MAX;

			if ( $pos_a === $pos_b ) {
				return 0;
			}
			return ( $pos_a < $pos_b ) ? -1 : 1;
		}
	);

	return $sorted_items;
}
