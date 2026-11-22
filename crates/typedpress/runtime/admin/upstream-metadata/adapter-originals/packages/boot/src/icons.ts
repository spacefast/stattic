import {
	brush,
	commentContent,
	home,
	image,
	layout,
	navigation,
	page,
	paragraph,
	payment,
	people,
	plugins,
	settings,
	styles,
	tool,
	verse,
} from '@wordpress/icons';
import type { FlatMenuItem } from './store/types';

/**
 * Updates menu items with modern SVG icons from @wordpress/icons.
 * This function should be called after menu items are registered to replace
 * Dashicons with optimized SVG icons.
 *
 * @param updateMenuItem - Function to update a menu item.
 */
export function updateMenuIcons(
	updateMenuItem: ( itemId: string, item: Partial< FlatMenuItem > ) => void
) {
	// Core menu items
	updateMenuItem( 'dashboard', {
		icon: home,
	} );

	updateMenuItem( 'settings', {
		icon: settings,
	} );

	updateMenuItem( 'settings-general', {
		icon: settings,
	} );

	updateMenuItem( 'settings-users', {
		icon: people,
	} );

	updateMenuItem( 'settings-reading', {
		icon: paragraph,
	} );

	updateMenuItem( 'settings-experiments', {
		icon: tool,
	} );

	updateMenuItem( 'design', {
		icon: brush,
	} );

	updateMenuItem( 'design-styles', {
		icon: styles,
	} );

	updateMenuItem( 'design-templates', {
		icon: layout,
	} );

	updateMenuItem( 'design-navigation', {
		icon: navigation,
	} );

	updateMenuItem( 'design-themes', {
		icon: brush,
	} );

	// Common post types (these IDs are generated dynamically in menu-items.php)
	// Pages
	updateMenuItem( 'page', {
		icon: page,
	} );

	// Posts
	updateMenuItem( 'post', {
		icon: verse,
	} );

	// Plugins
	updateMenuItem( 'plugins', {
		icon: plugins,
	} );

	// Media/Attachments
	updateMenuItem( 'attachment', {
		icon: image,
	} );

	// Comments (if enabled)
	updateMenuItem( 'comment', {
		icon: commentContent,
	} );

	updateMenuItem( 'users', {
		icon: people,
	} );

	// WooPayments Finance section
	updateMenuItem( 'finance', {
		icon: payment,
	} );
}
