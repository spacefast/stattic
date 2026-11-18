<?php
/**
 * Tags field definition for core theme fields collection
 *
 * @package TypedPress_Admin
 */

return array(
	'type'               => 'array',
	'label'              => __( 'Tags', 'typedpress-admin' ),
	'enableGlobalSearch' => true,
	'enableSorting'      => false,
	'filterBy'           => array(
		'operators' => array( 'isAny', 'isNone', 'isAll', 'isNotAll' ),
	),
);
