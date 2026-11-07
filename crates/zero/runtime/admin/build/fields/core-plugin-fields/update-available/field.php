<?php
/**
 * Description field definition for core plugins fields collection
 *
 * @package Zero_Admin
 */

return array(
	'type'         => 'boolean',
	'label'        => __( 'Update Available', 'zero-admin' ),
	'enableHiding' => true,
	'filterBy'     => array(
		'operators' => array( 'is', 'isNot' ),
	),
	'elements'     => array(
		array(
			'value' => true,
			'label' => __( 'Yes', 'zero-admin' ),
		),
		array(
			'value' => false,
			'label' => __( 'No', 'zero-admin' ),
		),
	),
);
