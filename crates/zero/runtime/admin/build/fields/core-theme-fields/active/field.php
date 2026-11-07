<?php
/**
 * Active field definition for core theme fields collection
 *
 * @package Zero_Admin
 */

return array(
	'type'     => 'boolean',
	'label'    => __( 'Active?', 'zero-admin' ),
	'filterBy' => false,
	'elements' => array(
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
