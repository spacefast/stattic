<?php
/**
 * Author field definition for core plugins fields collection
 *
 * @package Zero_Admin
 */

return array(
	'type'         => 'text',
	'label'        => __( 'Author', 'zero-admin' ),
	'enableHiding' => true,
	'filterBy'     => array(
		'operators' => array( 'contains', 'startsWith', 'is', 'isNot' ),
	),
);
