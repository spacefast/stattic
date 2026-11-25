<?php
/**
 * Author field definition for core plugins fields collection
 *
 * @package SpacePress_Admin
 */

return array(
	'type'         => 'text',
	'label'        => __( 'Author', 'spacepress-admin' ),
	'enableHiding' => true,
	'filterBy'     => array(
		'operators' => array( 'contains', 'startsWith', 'is', 'isNot' ),
	),
);
