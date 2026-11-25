<?php
/**
 * Post field definition for core user fields collection
 *
 * @package SpacePress_Admin
 */

return array(
	'type'          => 'integer',
	'label'         => __( 'Posts', 'spacepress-admin' ),
	'defaultValue'  => 0,
	'enableSorting' => true,
	'filterBy'      => array(
		'operators' => array( 'is', 'isNot', 'greaterThan', 'lessThan' ),
	),
);
