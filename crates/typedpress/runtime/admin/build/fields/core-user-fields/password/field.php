<?php
/**
 * Password field definition for core user fields collection
 *
 * @package TypedPress_Admin
 */

return array(
	'type'          => 'password',
	'label'         => __( 'Password', 'typedpress-admin' ),
	'enableSorting' => true,
	'enableHiding'  => false,
	'isValid'       => array(
		'required' => true,
	),
);
