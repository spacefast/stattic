<?php
/**
 * Date field definition for core comments fields collection
 *
 * @package Zero_Admin
 */

return array(
	'type'          => 'date',
	'header'        => __( 'Posted on', 'zero-admin' ),
	'label'         => __( 'Date', 'zero-admin' ),
	'enableSorting' => true,
	'filterBy'      => array(
		'operators' => array( 'on', 'beforeInc', 'afterInc', 'between' ),
	),
	'placeholder'   => __( 'No date', 'zero-admin' ),
);
