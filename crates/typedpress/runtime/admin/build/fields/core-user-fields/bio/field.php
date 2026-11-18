<?php
/**
 * First name field definition for core user fields collection
 *
 * @package TypedPress_Admin
 */

return array(
	'type'               => 'textarea',
	'label'              => __( 'Biography', 'typedpress-admin' ),
	'description'        => __( 'Biographical information to fill out the user’s profile. This may be shown publicly.', 'typedpress-admin' ),
	'enableSorting'      => true,
	'enableHiding'       => true,
	'enableGlobalSearch' => false,
);
