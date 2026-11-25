<?php
/**
 * Registration Date field definition for core user fields collection
 *
 * @package SpacePress_Admin
 */

return array(
	'type'          => 'date',
	'label'         => __( 'Registration Date', 'spacepress-admin' ),
	'enableSorting' => true,
	'filterBy'      => array(
		'operators' => array( 'on', 'before', 'after', 'between' ),
	),
);
