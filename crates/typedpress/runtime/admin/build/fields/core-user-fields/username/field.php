<?php
/**
 * Username field definition for core user fields collection
 *
 * @package TypedPress_Admin
 */

return array(
	'type'               => 'text',
	'label'              => __( 'Username', 'typedpress-admin' ),
	'enableSorting'      => true,
	'enableHiding'       => false,
	'enableGlobalSearch' => true,
	'isValid'            => array(
		'required' => true,
	),
);
