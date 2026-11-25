<?php
/**
 * Email field definition for core user fields collection
 *
 * @package SpacePress_Admin
 */

return array(
	'type'          => 'email',
	'label'         => __( 'Email', 'spacepress-admin' ),
	'enableSorting' => true,
	'isValid'       => array(
		'required' => true,
	),
);
