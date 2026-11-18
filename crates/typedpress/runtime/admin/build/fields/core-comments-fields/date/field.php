<?php
/**
 * Date field definition for core comments fields collection
 *
 * @package TypedPress_Admin
 */

return array(
	'type'          => 'date',
	'header'        => __( 'Posted on', 'typedpress-admin' ),
	'label'         => __( 'Date', 'typedpress-admin' ),
	'enableSorting' => true,
	'filterBy'      => array(
		'operators' => array( 'on', 'beforeInc', 'afterInc', 'between' ),
	),
	'placeholder'   => __( 'No date', 'typedpress-admin' ),
);
