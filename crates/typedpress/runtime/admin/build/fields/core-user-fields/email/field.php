<?php
/**
 * Email field definition for core user fields collection
 *
 * @package TypedPress_Admin
 */

return array(
	'type'          => 'email',
	'label'         => __( 'Email', 'typedpress-admin' ),
	'enableSorting' => true,
	'isValid'       => array(
		'required' => true,
	),
);
