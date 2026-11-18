<?php
/**
 * TypedPress Admin SPA
 *
 * This file provides the API for registering init modules in the TypedPress Admin SPA.
 *
 * @package TypedPress_Admin
 */

// Global storage for registered init modules.
global $tp_admin_init_modules;
$tp_admin_init_modules = array();

/**
 * Register a module for the TypedPress Admin SPA. These are loaded during boot.
 *
 * @since 1.0.0
 *
 * @param array $module The module data.
 */
function tp_admin_register_init_module( $module ) {
	global $tp_admin_init_modules;
	$tp_admin_init_modules[] = $module;
}

/**
 * Get all registered init modules.
 *
 * @return array Array of registered init modules.
 */
function tp_admin_get_init_modules() {
	global $tp_admin_init_modules;
	return $tp_admin_init_modules;
}
