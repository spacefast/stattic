<?php
/**
 * Registration Date field definition for core user fields collection
 *
 * @package TypedPress_Admin
 */

return array(
	'type'          => 'date',
	'label'         => __( 'Registration Date', 'typedpress-admin' ),
	'enableSorting' => true,
	'filterBy'      => array(
		'operators' => array( 'on', 'before', 'after', 'between' ),
	),
);
