<?php
/**
 * Post field definition for core user fields collection
 *
 * @package TypedPress_Admin
 */

return array(
	'type'          => 'integer',
	'label'         => __( 'Posts', 'typedpress-admin' ),
	'defaultValue'  => 0,
	'enableSorting' => true,
	'filterBy'      => array(
		'operators' => array( 'is', 'isNot', 'greaterThan', 'lessThan' ),
	),
);
