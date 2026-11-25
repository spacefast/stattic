<?php
/**
 * Locale field definition for core user fields collection
 *
 * @package SpacePress_Admin
 */

return array(
	'type'          => 'text',
	'label'         => __( 'Admin language', 'spacepress-admin' ),
	'enableSorting' => false,
	'filterBy'      => false,
	'enableHiding'  => false,
	'isValid'       => array(
		'required' => true,
	),
);
