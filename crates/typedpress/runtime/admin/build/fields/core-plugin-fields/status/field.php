<?php
/**
 * Status field definition for core plugins fields collection
 *
 * @package TypedPress_Admin
 */

return array(
	'type'         => 'text',
	'label'        => __( 'Status', 'typedpress-admin' ),
	'enableHiding' => true,
	'filterBy'     => array(
		'operators' => array( 'isAny', 'isNone' ),
	),
	'elements'     => array(
		array(
			'value' => 'active',
			'label' => __( 'Active', 'typedpress-admin' ),
		),
		array(
			'value' => 'inactive',
			'label' => __( 'Inactive', 'typedpress-admin' ),
		),
	),
);
