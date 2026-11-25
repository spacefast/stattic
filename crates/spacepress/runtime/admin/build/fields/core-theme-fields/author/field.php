<?php
/**
 * Author field definition for core theme fields collection
 *
 * @package SpacePress_Admin
 */

return array(
	'type'               => 'text',
	'label'              => __( 'Author', 'spacepress-admin' ),
	'enableSorting'      => false,
	'enableGlobalSearch' => true,
	'filterBy'           => array(
		'operators' => array( 'isAny', 'isNone' ),
	),
);
