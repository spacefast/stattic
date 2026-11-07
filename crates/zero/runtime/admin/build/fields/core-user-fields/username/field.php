<?php
/**
 * Username field definition for core user fields collection
 *
 * @package Zero_Admin
 */

return array(
	'type'               => 'text',
	'label'              => __( 'Username', 'zero-admin' ),
	'enableSorting'      => true,
	'enableHiding'       => false,
	'enableGlobalSearch' => true,
	'isValid'            => array(
		'required' => true,
	),
);
