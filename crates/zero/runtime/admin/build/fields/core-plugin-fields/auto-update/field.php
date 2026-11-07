<?php
/**
 * Auto update field definition for core plugins fields collection
 *
 * @package Zero_Admin
 */

// TODO: Add the field to core-data types, and re-implement this field spec.
return array(
	'type'         => 'boolean',
	'label'        => __( 'Auto-Updates', 'zero-admin' ),
	'enableHiding' => true,
	'filterBy'     => array(
		'operators' => array( 'is', 'isNot' ),
	),
	'elements'     => array(
		array(
			'value' => true,
			'label' => __( 'Enabled', 'zero-admin' ),
		),
		array(
			'value' => false,
			'label' => __( 'Disabled', 'zero-admin' ),
		),
	),
);
