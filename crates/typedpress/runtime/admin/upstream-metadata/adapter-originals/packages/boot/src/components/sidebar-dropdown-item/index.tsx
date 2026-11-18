import clsx from 'clsx';
import type { ReactNode } from 'react';
import { Icon } from '@wordpress/ui';
// eslint-disable-next-line @zero-admin/design-system/use-new-components -- See: ARC-1359
import { Stack } from '@zero-admin/design-system';
import {
	FlexBlock,
	__experimentalItem as Item,
	__unstableMotion as motion,
	__unstableAnimatePresence as AnimatePresence,
} from '@wordpress/components';
import { chevronDownSmall } from '@wordpress/icons';
import { useReducedMotion } from '@wordpress/compose';
import { useSelect } from '@wordpress/data';
import { store as zeroAdminStore } from '../../store';
import SidebarNavigationItem from '../sidebar-navigation-item';
import { wrapIcon } from '../items';
import MenuBadge from '../sidebar/menu-badge';
import type { IconType } from '../../store/types';
import './style.scss';

const ANIMATION_DURATION = 0.2;

interface SidebarDropdownItemProps {
	/**
	 * Optional CSS class name.
	 */
	className?: string;
	/**
	 * Identifier of the parent menu item.
	 */
	id: string;
	/**
	 * Icon to display with the dropdown item.
	 */
	icon?: IconType;
	/**
	 * Whether to show placeholder icons for alignment.
	 */
	shouldShowPlaceholder?: boolean;
	/**
	 * Content to display inside the dropdown item.
	 */
	children: ReactNode;
	/**
	 * Optional badge content.
	 */
	badge?: string | number | null;
	/**
	 * Whether this dropdown is currently expanded.
	 */
	isExpanded: boolean;
	/**
	 * Function to toggle this dropdown's expanded state.
	 */
	onToggle: () => void;
}

export default function SidebarDropdownItem( {
	className,
	id,
	icon,
	shouldShowPlaceholder = true,
	children,
	badge,
	isExpanded,
	onToggle,
}: SidebarDropdownItemProps ) {
	const menuItems = useSelect(
		( select ) => select( zeroAdminStore ).getMenuItems(),
		[]
	);
	const items = menuItems.filter( ( item ) => item.parent === id );
	const disableMotion = useReducedMotion();
	const childHasRealIcons = items.some( ( item ) => !! item.icon );
	return (
		<div className="zero-admin-sidebar-dropdown-item">
			<Item
				className={ clsx(
					'zero-admin-sidebar-navigation-item',
					className
				) }
				onClick={ ( e ) => {
					e.preventDefault();
					e.stopPropagation();
					onToggle();
				} }
				onMouseDown={ ( e ) => e.preventDefault() }
			>
				<Stack
					direction="row"
					justify="flex-start"
					gap={ 2 }
					align="center"
					style={ { flexGrow: '1' } }
				>
					{ wrapIcon( icon, shouldShowPlaceholder ) }
					<FlexBlock>{ children }</FlexBlock>
					<MenuBadge badge={ badge } />
					<Icon
						icon={ chevronDownSmall }
						className={ clsx(
							'zero-admin-sidebar-dropdown-item__chevron',
							{ 'is-up': isExpanded }
						) }
					/>
				</Stack>
			</Item>
			<AnimatePresence initial={ false }>
				{ isExpanded && (
					<motion.div
						initial={ { height: 0 } }
						animate={ { height: 'auto' } }
						exit={ { height: 0 } }
						transition={ {
							type: 'tween',
							duration: disableMotion ? 0 : ANIMATION_DURATION,
							ease: 'easeOut',
						} }
						className="zero-admin-sidebar-dropdown-item__children"
						role="list"
					>
						{ items.map( ( item, index ) => (
							<SidebarNavigationItem
								key={ index }
								to={ item.to }
								badge={ item.badge }
								shouldShowPlaceholder={ childHasRealIcons }
							>
								{ item.label }
							</SidebarNavigationItem>
						) ) }
					</motion.div>
				) }
			</AnimatePresence>
		</div>
	);
}
