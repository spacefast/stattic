import { useNavigate } from '@wordpress/route';
// @ts-expect-error Commands package is not typed yet.
import { useCommandLoader } from '@wordpress/commands';
import { useMemo } from '@wordpress/element';
import { __, _x, sprintf } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import { wrapIcon } from '../items';
import { store as zeroAdminStore } from '../../store';

type CommandCallbackArgs = { close: () => void };

function useNavigateLoader() {
	const navigate = useNavigate();
	const items = useSelect(
		( select ) => select( zeroAdminStore ).getMenuItems(),
		[]
	);
	// Include all items in the command palette.
	// For parent items, navigate to their first child.
	// Exclude parent items that don't have any non-parent children.
	const commands = useMemo(
		() =>
			items
				.filter( ( item ) => {
					// Include non-parent items
					if ( ! item.parent_type ) {
						return true;
					}
					// Include parent items only if they have non-parent children
					return items.some(
						( child ) =>
							child.parent === item.id && ! child.parent_type
					);
				} )
				.map( ( item ) => {
					const getParentLabels = (
						currentItem: typeof item
					): string[] => {
						if ( ! currentItem.parent ) {
							return [ currentItem.label ];
						}

						const parentItem = items.find(
							( parent ) => parent.id === currentItem.parent
						);
						if ( ! parentItem ) {
							return [ currentItem.label ];
						}

						return getParentLabels( parentItem ).concat( [
							currentItem.label,
						] );
					};

					const parentLabels = getParentLabels( item );

					return {
						name: `zero-admin/navigate-${ item.id }`,
						label: sprintf(
							/* translators: %s: Name of the navigation item with hierarchical path */
							__( 'Go to %s', 'zero-admin' ),
							parentLabels.join(
								' ' +
									_x(
										'›',
										'Hierarchical separator for navigation items',
										'zero-admin'
									) +
									' '
							)
						),
						icon: wrapIcon( item.icon ),
						callback: ( { close }: CommandCallbackArgs ) => {
							// If this is a parent item, find its first child
							if ( item.parent_type ) {
								const firstChild = items.find(
									( child ) =>
										child.parent === item.id &&
										! child.parent_type
								);
								navigate( {
									to: firstChild ? firstChild.to : item.to,
								} );
							} else {
								navigate( { to: item.to } );
							}
							close();
						},
					};
				} ),
		[ items, navigate ]
	);

	return { commands };
}

export function useCommands() {
	useCommandLoader( {
		name: 'zero-admin/navigate',
		hook: useNavigateLoader,
	} );
	return null;
}
