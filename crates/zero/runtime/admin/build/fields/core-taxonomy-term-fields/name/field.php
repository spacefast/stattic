<?php
/**
 * Name field definition for core taxonomy term fields collection
 *
 * @package Zero_Admin
 */

return array(
	'type'               => 'text',
	'label'              => __( 'Name', 'zero-admin' ),
	'filterBy'           => false,
	'enableGlobalSearch' => true,
	'enableHiding'       => false,
	'isValid'            => array(
		'required' => true,
	),
);
