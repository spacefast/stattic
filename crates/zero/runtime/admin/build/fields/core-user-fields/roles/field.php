<?php
/**
 * Roles field definition for core user fields collection
 *
 * @package Zero_Admin
 */

$zero_admin_roles_array = array();

if ( current_user_can( 'list_users' ) ) {
	global $wp_roles;

	foreach ( $wp_roles->get_names() as $zero_admin_role_id => $zero_admin_role_name ) {
		$zero_admin_roles_array[] = array(
			'label' => $zero_admin_role_name,
			'value' => $zero_admin_role_id,
		);
	}
}

return array(
	'type'          => 'array',
	'label'         => __( 'Role', 'zero-admin' ),
	'options'       => $zero_admin_roles_array,
	'defaultValue'  => get_option( 'default_role' ),
	'enableSorting' => false,
	'filterBy'      => array(
		'operators' => array( 'is', 'isNot' ),
	),
	'isValid'       => array(
		'required' => true,
	),
);
