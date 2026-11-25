<?php
/**
 * Status field definition for core plugins fields collection
 *
 * @package SpacePress_Admin
 */

return array(
	'type'         => 'text',
	'label'        => __( 'Status', 'spacepress-admin' ),
	'enableHiding' => true,
	'filterBy'     => array(
		'operators' => array( 'isAny', 'isNone' ),
	),
	'elements'     => array(
		array(
			'value' => 'active',
			'label' => __( 'Active', 'spacepress-admin' ),
		),
		array(
			'value' => 'inactive',
			'label' => __( 'Inactive', 'spacepress-admin' ),
		),
	),
);
