<?php
/**
 * Active field definition for core theme fields collection
 *
 * @package TypedPress_Admin
 */

return array(
	'type'     => 'boolean',
	'label'    => __( 'Active?', 'typedpress-admin' ),
	'filterBy' => false,
	'elements' => array(
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
