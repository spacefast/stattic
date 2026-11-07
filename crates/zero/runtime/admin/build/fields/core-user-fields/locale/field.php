<?php
/**
 * Locale field definition for core user fields collection
 *
 * @package Zero_Admin
 */

return array(
	'type'          => 'text',
	'label'         => __( 'Admin language', 'zero-admin' ),
	'enableSorting' => false,
	'filterBy'      => false,
	'enableHiding'  => false,
	'isValid'       => array(
		'required' => true,
	),
);
