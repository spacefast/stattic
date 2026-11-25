<?php
/**
 * Password field definition for core user fields collection
 *
 * @package SpacePress_Admin
 */

return array(
	'type'          => 'password',
	'label'         => __( 'Password', 'spacepress-admin' ),
	'enableSorting' => true,
	'enableHiding'  => false,
	'isValid'       => array(
		'required' => true,
	),
);
