<?php
/**
 * First name field definition for core user fields collection
 *
 * @package Zero_Admin
 */

return array(
	'type'               => 'textarea',
	'label'              => __( 'Biography', 'zero-admin' ),
	'description'        => __( 'Biographical information to fill out the user’s profile. This may be shown publicly.', 'zero-admin' ),
	'enableSorting'      => true,
	'enableHiding'       => true,
	'enableGlobalSearch' => false,
);
