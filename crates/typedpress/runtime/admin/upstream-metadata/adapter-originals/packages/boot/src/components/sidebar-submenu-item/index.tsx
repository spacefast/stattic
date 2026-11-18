import type { ReactNode } from 'react';
import { Icon } from '@wordpress/ui';
// eslint-disable-next-line @zero-admin/design-system/use-new-components -- See: ARC-1359
import { Stack } from '@zero-admin/design-system';
import { FlexBlock } from '@wordpress/components';
import { isRTL } from '@wordpress/i18n';
import { chevronRightSmall, chevronLeftSmall } from '@wordpress/icons';
import RouterLinkItem from '../router-link-item';
import { wrapIcon } from '../items';
import MenuBadge from '../sidebar/menu-badge';
import type { IconType } from '../../store/types';

interface SidebarSubmenuItemProps {
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
	 * The path to navigate to.
	 */
	to: string;
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

export default function SidebarSubmenuItem( {
	className,
	id,
	icon,
	shouldShowPlaceholder = true,
	children,
	badge,
	to,
	onNavigate,
}: SidebarSubmenuItemProps ) {
	const handleClick = ( _event: React.MouseEvent ) => {
		onNavigate( { id, direction: 'forward' } );
	};

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
			<Icon icon={ isRTL() ? chevronLeftSmall : chevronRightSmall } />
		</Stack>
	);

	return (
		<RouterLinkItem
			to={ to }
			className={ className }
			onClick={ handleClick }
		>
			{ content }
		</RouterLinkItem>
	);
}
