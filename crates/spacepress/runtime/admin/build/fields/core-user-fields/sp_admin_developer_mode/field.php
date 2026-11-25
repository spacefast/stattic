<?php
/**
 * Developer mode (SpacePress): classic wp-admin opens on its own and SpacePress Admin's sidebar links to it.
 * Saved as the user's `sp_admin_developer_mode` meta through core's user route (lib/classic-route.php).
 *
 * @package SpacePress_Admin
 */

return array(
	'type'          => 'boolean',
	'label'         => __( 'Developer mode', 'spacepress-admin' ),
	'description'   => __( 'Open classic wp-admin on its own and show it in the sidebar. Off, its pages open inside SpacePress Admin.', 'spacepress-admin' ),
	'enableSorting' => false,
	'filterBy'      => false,
	'enableHiding'  => false,
);
