<?php
/**
 * Name field definition for core taxonomy term fields collection
 *
 * @package SpacePress_Admin
 */

return array(
	'type'               => 'text',
	'label'              => __( 'Name', 'spacepress-admin' ),
	'filterBy'           => false,
	'enableGlobalSearch' => true,
	'enableHiding'       => false,
	'isValid'            => array(
		'required' => true,
	),
);
