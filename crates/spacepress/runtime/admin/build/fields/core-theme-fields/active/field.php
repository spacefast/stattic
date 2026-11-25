<?php
/**
 * Active field definition for core theme fields collection
 *
 * @package SpacePress_Admin
 */

return array(
	'type'     => 'boolean',
	'label'    => __( 'Active?', 'spacepress-admin' ),
	'filterBy' => false,
	'elements' => array(
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
