<?php
/**
 * Author field definition for core theme fields collection
 *
 * @package TypedPress_Admin
 */

return array(
	'type'               => 'text',
	'label'              => __( 'Author', 'typedpress-admin' ),
	'enableSorting'      => false,
	'enableGlobalSearch' => true,
	'filterBy'           => array(
		'operators' => array( 'isAny', 'isNone' ),
	),
);
