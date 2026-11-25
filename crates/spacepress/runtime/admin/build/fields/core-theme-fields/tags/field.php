<?php
/**
 * Tags field definition for core theme fields collection
 *
 * @package SpacePress_Admin
 */

return array(
	'type'               => 'array',
	'label'              => __( 'Tags', 'spacepress-admin' ),
	'enableGlobalSearch' => true,
	'enableSorting'      => false,
	'filterBy'           => array(
		'operators' => array( 'isAny', 'isNone', 'isAll', 'isNotAll' ),
	),
);
