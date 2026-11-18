<?php
/**
 * Developer mode (TypedPress): classic wp-admin opens on its own and TypedPress Admin's sidebar links to it.
 * Saved as the user's `tp_admin_developer_mode` meta through core's user route (lib/classic-route.php).
 *
 * @package TypedPress_Admin
 */

return array(
	'type'          => 'boolean',
	'label'         => __( 'Developer mode', 'typedpress-admin' ),
	'description'   => __( 'Open classic wp-admin on its own and show it in the sidebar. Off, its pages open inside TypedPress Admin.', 'typedpress-admin' ),
	'enableSorting' => false,
	'filterBy'      => false,
	'enableHiding'  => false,
);
