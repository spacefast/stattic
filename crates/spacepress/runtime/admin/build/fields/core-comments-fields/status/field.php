<?php
/**
 * Status field definition for core comments fields collection
 *
 * @package SpacePress_Admin
 */

$sp_admin_comment_statuses = array(
	array(
		'value' => 'approved',
		'label' => __( 'Approved', 'spacepress-admin' ),
	),
	array(
		'value' => 'hold',
		'label' => __( 'Unapproved', 'spacepress-admin' ),
	),
	array(
		'value' => 'spam',
		'label' => __( 'Spam', 'spacepress-admin' ),
	),
	array(
		'value' => 'trash',
		'label' => __( 'Trash', 'spacepress-admin' ),
	),
);

return array(
	'type'          => 'select',
	'label'         => __( 'Status', 'spacepress-admin' ),
	'enableSorting' => false,
	'filterBy'      => false,
	'elements'      => $sp_admin_comment_statuses,
);
