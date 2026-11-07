import clsx from 'clsx';
import type { ReactNode } from 'react';
import { Icon } from '@wordpress/ui';
// eslint-disable-next-line @zero-admin/design-system/use-new-components -- See: ARC-1359
import { Stack } from '@zero-admin/design-system';
import { FlexBlock, __experimentalItem as Item } from '@wordpress/components';
import { arrowUpRight } from '@wordpress/icons';
import RouterLinkItem from '../router-link-item';
import { wrapIcon } from '../items';
import MenuBadge from '../sidebar/menu-badge';
import type { IconType } from '../../store/types';
import './style.scss';

interface SidebarNavigationItemProps {
	/**
	 * Optional CSS class name.
	 */
	className?: string;
	/**
	 * Icon to display with the navigation item.
	 */
	icon?: IconType;
	/**
	 * Whether to show placeholder icons for alignment.
	 */
	shouldShowPlaceholder?: boolean;
	/**
	 * Content to display inside the navigation item.
	 */
	children: ReactNode;
	/**
	 * Optional badge content.
	 */
	badge?: string | number | null;
	/**
	 * The path to navigate to.
	 */
	to: string;
}

export default function SidebarNavigationItem( {
	className,
	icon,
	shouldShowPlaceholder = true,
	children,
	badge,
	to,
}: SidebarNavigationItemProps ) {
	// Check if the 'to' prop is an external URL
	const isExternal = ! String(
		new URL( to, window.location.origin )
	).startsWith( window.location.origin );

	const content = (
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
		</Stack>
	);

	if ( isExternal ) {
		// Render as a regular anchor tag for external URLs
		return (
			<Item
				as="a"
				href={ to }
				target="_blank"
				rel="noopener noreferrer"
				className={ clsx(
					'zero-admin-sidebar-navigation-item',
					className
				) }
			>
				{ content }
				<Icon icon={ arrowUpRight } size={ 20 } />
			</Item>
		);
	}

	return (
		<RouterLinkItem
			to={ to }
			className={ clsx(
				'zero-admin-sidebar-navigation-item',
				className
			) }
		>
			{ content }
		</RouterLinkItem>
	);
}
