<?php
/**
 * Description field definition for core plugins fields collection
 *
 * @package SpacePress_Admin
 */

return array(
	'type'         => 'boolean',
	'label'        => __( 'Update Available', 'spacepress-admin' ),
	'enableHiding' => true,
	'filterBy'     => array(
		'operators' => array( 'is', 'isNot' ),
	),
	'elements'     => array(
		array(
			'value' => true,
			'label' => __( 'Yes', 'spacepress-admin' ),
		),
		array(
			'value' => false,
			'label' => __( 'No', 'spacepress-admin' ),
		),
	),
);
