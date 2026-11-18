<?php
/**
 * Locale field definition for core user fields collection
 *
 * @package TypedPress_Admin
 */

return array(
	'type'          => 'text',
	'label'         => __( 'Admin language', 'typedpress-admin' ),
	'enableSorting' => false,
	'filterBy'      => false,
	'enableHiding'  => false,
	'isValid'       => array(
		'required' => true,
	),
);
