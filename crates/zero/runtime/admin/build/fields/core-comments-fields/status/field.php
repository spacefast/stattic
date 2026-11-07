<?php
/**
 * Status field definition for core comments fields collection
 *
 * @package Zero_Admin
 */

$zero_admin_comment_statuses = array(
	array(
		'value' => 'approved',
		'label' => __( 'Approved', 'zero-admin' ),
	),
	array(
		'value' => 'hold',
		'label' => __( 'Unapproved', 'zero-admin' ),
	),
	array(
		'value' => 'spam',
		'label' => __( 'Spam', 'zero-admin' ),
	),
	array(
		'value' => 'trash',
		'label' => __( 'Trash', 'zero-admin' ),
	),
);

return array(
	'type'          => 'select',
	'label'         => __( 'Status', 'zero-admin' ),
	'enableSorting' => false,
	'filterBy'      => false,
	'elements'      => $zero_admin_comment_statuses,
);
