<?php
/**
 * Tags field definition for core theme fields collection
 *
 * @package Zero_Admin
 */

return array(
	'type'               => 'array',
	'label'              => __( 'Tags', 'zero-admin' ),
	'enableGlobalSearch' => true,
	'enableSorting'      => false,
	'filterBy'           => array(
		'operators' => array( 'isAny', 'isNone', 'isAll', 'isNotAll' ),
	),
);
