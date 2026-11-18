import clsx from 'clsx';
import type { ReactNode } from 'react';
import { Icon } from '@wordpress/ui';
// eslint-disable-next-line @zero-admin/design-system/use-new-components -- See: ARC-1359
import { Stack } from '@zero-admin/design-system';
import { FlexBlock, __experimentalItem as Item } from '@wordpress/components';
import { isRTL } from '@wordpress/i18n';
import { chevronRightSmall, chevronLeftSmall } from '@wordpress/icons';
import { wrapIcon } from '../items';
import MenuBadge from '../sidebar/menu-badge';
import type { IconType } from '../../store/types';

interface SidebarNavigationItemProps {
	/**
	 * Optional CSS class name.
	 */
	className?: string;
	/**
	 * Identifier of the navigation item.
	 */
	id: string;
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
	 * Function to handle sidebar navigation when the item is clicked.
	 */
	onNavigate: ( {
		id,
		direction,
	}: {
		id?: string;
		direction: 'forward' | 'backward';
	} ) => void;
}

export default function SidebarDrilldownItem( {
	className,
	id,
	icon,
	shouldShowPlaceholder = true,
	children,
	badge,
	onNavigate,
}: SidebarNavigationItemProps ) {
	const handleClick = ( e: React.MouseEvent ) => {
		e.preventDefault();
		onNavigate( { id, direction: 'forward' } );
	};

	return (
		<Item
			className={ clsx(
				'zero-admin-sidebar-navigation-item',
				className
			) }
			onClick={ handleClick }
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
				<Icon icon={ isRTL() ? chevronLeftSmall : chevronRightSmall } />
			</Stack>
		</Item>
	);
}
