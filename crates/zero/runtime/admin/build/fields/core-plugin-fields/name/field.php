<?php
/**
 * Name field definition for core plugins fields collection
 *
 * @package Zero_Admin
 */

return array(
	'type'               => 'text',
	'label'              => __( 'Plugin Name', 'zero-admin' ),
	'enableHiding'       => true,
	'filterBy'           => array(
		'operators' => array( 'contains', 'startsWith', 'is', 'isNot' ),
	),
	'enableGlobalSearch' => true,
);
