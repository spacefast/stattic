<?php
/**
 * First name field definition for core user fields collection
 *
 * @package SpacePress_Admin
 */

return array(
	'type'               => 'textarea',
	'label'              => __( 'Biography', 'spacepress-admin' ),
	'description'        => __( 'Biographical information to fill out the user’s profile. This may be shown publicly.', 'spacepress-admin' ),
	'enableSorting'      => true,
	'enableHiding'       => true,
	'enableGlobalSearch' => false,
);
