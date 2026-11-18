<?php
/**
 * Author field definition for core plugins fields collection
 *
 * @package TypedPress_Admin
 */

return array(
	'type'         => 'text',
	'label'        => __( 'Author', 'typedpress-admin' ),
	'enableHiding' => true,
	'filterBy'     => array(
		'operators' => array( 'contains', 'startsWith', 'is', 'isNot' ),
	),
);
