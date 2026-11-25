<?php
/**
 * Date field definition for core comments fields collection
 *
 * @package SpacePress_Admin
 */

return array(
	'type'          => 'date',
	'header'        => __( 'Posted on', 'spacepress-admin' ),
	'label'         => __( 'Date', 'spacepress-admin' ),
	'enableSorting' => true,
	'filterBy'      => array(
		'operators' => array( 'on', 'beforeInc', 'afterInc', 'between' ),
	),
	'placeholder'   => __( 'No date', 'spacepress-admin' ),
);
