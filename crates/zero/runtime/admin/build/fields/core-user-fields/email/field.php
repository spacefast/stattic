<?php
/**
 * Email field definition for core user fields collection
 *
 * @package Zero_Admin
 */

return array(
	'type'          => 'email',
	'label'         => __( 'Email', 'zero-admin' ),
	'enableSorting' => true,
	'isValid'       => array(
		'required' => true,
	),
);
