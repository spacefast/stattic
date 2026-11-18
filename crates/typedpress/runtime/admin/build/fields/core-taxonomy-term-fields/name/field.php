<?php
/**
 * Name field definition for core taxonomy term fields collection
 *
 * @package TypedPress_Admin
 */

return array(
	'type'               => 'text',
	'label'              => __( 'Name', 'typedpress-admin' ),
	'filterBy'           => false,
	'enableGlobalSearch' => true,
	'enableHiding'       => false,
	'isValid'            => array(
		'required' => true,
	),
);
