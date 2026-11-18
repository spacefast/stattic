<?php
/**
 * Status field definition for core comments fields collection
 *
 * @package TypedPress_Admin
 */

$tp_admin_comment_statuses = array(
	array(
		'value' => 'approved',
		'label' => __( 'Approved', 'typedpress-admin' ),
	),
	array(
		'value' => 'hold',
		'label' => __( 'Unapproved', 'typedpress-admin' ),
	),
	array(
		'value' => 'spam',
		'label' => __( 'Spam', 'typedpress-admin' ),
	),
	array(
		'value' => 'trash',
		'label' => __( 'Trash', 'typedpress-admin' ),
	),
);

return array(
	'type'          => 'select',
	'label'         => __( 'Status', 'typedpress-admin' ),
	'enableSorting' => false,
	'filterBy'      => false,
	'elements'      => $tp_admin_comment_statuses,
);
