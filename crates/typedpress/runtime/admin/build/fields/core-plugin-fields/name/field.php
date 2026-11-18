<?php
/**
 * Name field definition for core plugins fields collection
 *
 * @package TypedPress_Admin
 */

return array(
	'type'               => 'text',
	'label'              => __( 'Plugin Name', 'typedpress-admin' ),
	'enableHiding'       => true,
	'filterBy'           => array(
		'operators' => array( 'contains', 'startsWith', 'is', 'isNot' ),
	),
	'enableGlobalSearch' => true,
);
