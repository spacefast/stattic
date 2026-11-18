<?php
/**
 * Roles field definition for core user fields collection
 *
 * @package TypedPress_Admin
 */

$tp_admin_roles_array = array();

if ( current_user_can( 'list_users' ) ) {
	global $wp_roles;

	foreach ( $wp_roles->get_names() as $tp_admin_role_id => $tp_admin_role_name ) {
		$tp_admin_roles_array[] = array(
			'label' => $tp_admin_role_name,
			'value' => $tp_admin_role_id,
		);
	}
}

return array(
	'type'          => 'array',
	'label'         => __( 'Role', 'typedpress-admin' ),
	'options'       => $tp_admin_roles_array,
	'defaultValue'  => get_option( 'default_role' ),
	'enableSorting' => false,
	'filterBy'      => array(
		'operators' => array( 'is', 'isNot' ),
	),
	'isValid'       => array(
		'required' => true,
	),
);
