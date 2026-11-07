<?php
/**
 * Author field definition for core theme fields collection
 *
 * @package Zero_Admin
 */

return array(
	'type'               => 'text',
	'label'              => __( 'Author', 'zero-admin' ),
	'enableSorting'      => false,
	'enableGlobalSearch' => true,
	'filterBy'           => array(
		'operators' => array( 'isAny', 'isNone' ),
	),
);
