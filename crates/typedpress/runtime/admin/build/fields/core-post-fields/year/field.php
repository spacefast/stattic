<?php
/**
 * Year field definition for core post fields collection.
 * This field can be used as a grouping field.
 *
 * @package TypedPress_Admin
 */

return array(
	'type'        => 'text',
	'label'       => __( 'Year', 'typedpress-admin' ),
	'description' => __( 'The year the post was published.', 'typedpress-admin' ),
	'filterBy'    => false,
);
