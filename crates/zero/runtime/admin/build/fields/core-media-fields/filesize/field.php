<?php
/**
 * Filesize field definition for core media fields collection
 *
 * @package Zero_Admin
 */

return array(
	'type'          => 'text',
	'label'         => __( 'File size', 'zero-admin' ),
	'enableSorting' => false, // File size is not sortable as it exists in the database within a serialized value in `_wp_attachment_metadata`.
	'filterBy'      => false,
	'readOnly'      => true,
);
