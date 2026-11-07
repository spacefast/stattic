<?php
/**
 * Password field definition for core user fields collection
 *
 * @package Zero_Admin
 */

return array(
	'type'          => 'password',
	'label'         => __( 'Password', 'zero-admin' ),
	'enableSorting' => true,
	'enableHiding'  => false,
	'isValid'       => array(
		'required' => true,
	),
);
