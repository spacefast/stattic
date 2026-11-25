<?php
/**
 * Auto update field definition for core plugins fields collection
 *
 * @package SpacePress_Admin
 */

// TODO: Add the field to core-data types, and re-implement this field spec.
return array(
	'type'         => 'boolean',
	'label'        => __( 'Auto-Updates', 'spacepress-admin' ),
	'enableHiding' => true,
	'filterBy'     => array(
		'operators' => array( 'is', 'isNot' ),
	),
	'elements'     => array(
		array(
			'value' => true,
			'label' => __( 'Enabled', 'spacepress-admin' ),
		),
		array(
			'value' => false,
			'label' => __( 'Disabled', 'spacepress-admin' ),
		),
	),
);
