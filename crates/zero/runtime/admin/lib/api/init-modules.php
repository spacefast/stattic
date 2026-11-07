<?php
/**
 * Zero Admin SPA
 *
 * This file provides the API for registering init modules in the Zero Admin SPA.
 *
 * @package Zero_Admin
 */

// Global storage for registered init modules.
global $zero_admin_init_modules;
$zero_admin_init_modules = array();

/**
 * Register a module for the Zero Admin SPA. These are loaded during boot.
 *
 * @since 1.0.0
 *
 * @param array $module The module data.
 */
function zero_admin_register_init_module( $module ) {
	global $zero_admin_init_modules;
	$zero_admin_init_modules[] = $module;
}

/**
 * Get all registered init modules.
 *
 * @return array Array of registered init modules.
 */
function zero_admin_get_init_modules() {
	global $zero_admin_init_modules;
	return $zero_admin_init_modules;
}
