import type { FlatMenuItem, MenuItem } from '../store/types';

/**
 * Builds a hierarchical menu tree from a flat list of menu items.
 *
 * @param flatItems - Array of flat menu items
 * @return Array of hierarchical menu items (root level items with children)
 */
export function buildMenuTree( flatItems: FlatMenuItem[] ): MenuItem[] {
	const itemMap = new Map< string, MenuItem >();
	const rootItems: MenuItem[] = [];

	// Create all items in the map first
	flatItems.forEach( ( item ) => {
		itemMap.set( item.id, {
			id: item.id,
			to: item.to,
			icon: item.icon,
			label: item.label,
			badge: item.badge,
			parent_type: item.parent_type,
			children: [],
			callback: item.callback,
		} );
	} );

	// Build the tree structure
	flatItems.forEach( ( flatItem ) => {
		const item = itemMap.get( flatItem.id );
		if ( ! item ) {
			return;
		}

		if ( flatItem.parent ) {
			const parent = itemMap.get( flatItem.parent );
			if ( parent ) {
				parent.children = parent.children || [];
				parent.children.push( item );
			}
		} else {
			rootItems.push( item );
		}
	} );

	// Remove empty children arrays and sort by label
	const cleanupItem = ( item: MenuItem ): MenuItem => {
		if ( item.children && item.children.length > 0 ) {
			item.children = item.children.map( cleanupItem );
		}

		if ( item.children && item.children.length === 0 ) {
			delete item.children;
		}

		return item;
	};

	return rootItems.map( cleanupItem );
}
