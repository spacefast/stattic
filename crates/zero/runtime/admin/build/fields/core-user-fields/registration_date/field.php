<?php
/**
 * Registration Date field definition for core user fields collection
 *
 * @package Zero_Admin
 */

return array(
	'type'          => 'date',
	'label'         => __( 'Registration Date', 'zero-admin' ),
	'enableSorting' => true,
	'filterBy'      => array(
		'operators' => array( 'on', 'before', 'after', 'between' ),
	),
);
