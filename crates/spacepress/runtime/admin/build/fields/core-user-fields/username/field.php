<?php
/**
 * Username field definition for core user fields collection
 *
 * @package SpacePress_Admin
 */

return array(
	'type'               => 'text',
	'label'              => __( 'Username', 'spacepress-admin' ),
	'enableSorting'      => true,
	'enableHiding'       => false,
	'enableGlobalSearch' => true,
	'isValid'            => array(
		'required' => true,
	),
);
