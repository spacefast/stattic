<?php
/**
 * Status field definition for core plugins fields collection
 *
 * @package Zero_Admin
 */

return array(
	'type'         => 'text',
	'label'        => __( 'Status', 'zero-admin' ),
	'enableHiding' => true,
	'filterBy'     => array(
		'operators' => array( 'isAny', 'isNone' ),
	),
	'elements'     => array(
		array(
			'value' => 'active',
			'label' => __( 'Active', 'zero-admin' ),
		),
		array(
			'value' => 'inactive',
			'label' => __( 'Inactive', 'zero-admin' ),
		),
	),
);
