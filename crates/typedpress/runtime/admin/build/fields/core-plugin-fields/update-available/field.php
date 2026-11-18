<?php
/**
 * Description field definition for core plugins fields collection
 *
 * @package TypedPress_Admin
 */

return array(
	'type'         => 'boolean',
	'label'        => __( 'Update Available', 'typedpress-admin' ),
	'enableHiding' => true,
	'filterBy'     => array(
		'operators' => array( 'is', 'isNot' ),
	),
	'elements'     => array(
		array(
			'value' => true,
			'label' => __( 'Yes', 'typedpress-admin' ),
		),
		array(
			'value' => false,
			'label' => __( 'No', 'typedpress-admin' ),
		),
	),
);
