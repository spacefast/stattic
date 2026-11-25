<?php
/**
 * SpacePress Admin SPA
 *
 * This file provides the API for registering init modules in the SpacePress Admin SPA.
 *
 * @package SpacePress_Admin
 */

// Global storage for registered init modules.
global $sp_admin_init_modules;
$sp_admin_init_modules = array();

/**
 * Register a module for the SpacePress Admin SPA. These are loaded during boot.
 *
 * @since 1.0.0
 *
 * @param array $module The module data.
 */
function sp_admin_register_init_module( $module ) {
	global $sp_admin_init_modules;
	$sp_admin_init_modules[] = $module;
}

/**
 * Get all registered init modules.
 *
 * @return array Array of registered init modules.
 */
function sp_admin_get_init_modules() {
	global $sp_admin_init_modules;
	return $sp_admin_init_modules;
}
