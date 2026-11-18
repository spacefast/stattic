import { useState, useMemo } from 'react';
import { IconButton } from '@wordpress/ui';
// eslint-disable-next-line @zero-admin/design-system/use-new-components -- See: ARC-1359
import { Stack } from '@zero-admin/design-system';
import { __ } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import { help } from '@wordpress/icons';
import { store as zeroAdminStore } from '../../store';
import SidebarNavigationItem from '../sidebar-navigation-item';
import SidebarDrilldownItem from '../sidebar-drilldown-item';
import SidebarDropdownItem from '../sidebar-dropdown-item';
import SidebarSubmenuItem from '../sidebar-submenu-item';
import SidebarNavigationScreen from '../sidebar-navigation-screen';
import UserMenuButton from '../user-menu-button';
import SiteHub from '../site-hub';
import { useSidebarParent } from './use-sidebar-parent';
import { NudgeDisplay } from '../nudges';
import type { MenuItem } from '../../store/types';
import { ErrorBoundary } from '../error-boundary';
import { AIIcon } from './ai-icon';
import './style.scss';
import MetaMenu from '../meta-menu';
import { useToggleAgentChat } from './use-toggle-agent-chat';

function Sidebar() {
	const nudge = useSelect(
		( select ) => select( zeroAdminStore ).getCurrentNudge(),
		[]
	);
	const [ animationDirection, setAnimationDirection ] = useState<
		'forward' | 'backward' | null
	>( null );
	const [ parentId, setParentId, parentDropdownId, setParentDropdownId ] =
		useSidebarParent();
	const menuItems = useSelect(
		( select ) => select( zeroAdminStore ).getMenuItems(),
		[]
	);

	const parent = useMemo(
		() => menuItems.find( ( item ) => item.id === parentId ),
		[ menuItems, parentId ]
	);
	// Create a unique key for the current navigation state
	// The sidebar will animate when the key changes.
	const navigationKey = parent
		? `${ parent.parent_type }-${ parent.id }`
		: 'root';

	// We use transitions to handle navigation clicks
	// This allows smooth animations and non blocking navigation.
	const handleNavigate = ( {
		id,
		direction,
	}: {
		id?: string;
		direction: 'forward' | 'backward';
	} ) => {
		setAnimationDirection( direction );
		setParentId( id );
	};

	const handleDropdownToggle = ( dropdownId: string ) => {
		setParentDropdownId(
			parentDropdownId === dropdownId ? undefined : dropdownId
		);
	};

	const items = useMemo(
		() => menuItems.filter( ( item ) => item.parent === parentId ),
		[ menuItems, parentId ]
	);

	const hasRealIcons = items.some( ( item ) => !! item.icon );
	const backMenuItem = useMemo(
		() =>
			parent?.parent !== undefined
				? menuItems.find( ( { id } ) => id === parent.parent )
				: menuItems[ 0 ],
		[ parent, menuItems ]
	);

	const { toggleAgentChat } = useToggleAgentChat();

	return (
		<>
			<div className="zero-admin-sidebar__scrollable">
				<SiteHub />
				<div className="zero-admin-sidebar__content">
					<SidebarNavigationScreen
						isRoot={ ! parent }
						title={ parent ? parent.label : '' }
						backMenuItem={ backMenuItem }
						animationDirection={ animationDirection || undefined }
						navigationKey={ navigationKey }
						onNavigate={ handleNavigate }
						content={
							<div role="list">
								{ items.map( ( item: MenuItem ) => {
									if ( item.parent_type === 'dropdown' ) {
										return (
											<SidebarDropdownItem
												key={ item.id }
												id={ item.id }
												className="zero-admin-sidebar-navigation-item"
												icon={ item.icon }
												badge={ item.badge }
												shouldShowPlaceholder={
													hasRealIcons
												}
												isExpanded={
													parentDropdownId === item.id
												}
												onToggle={ () =>
													handleDropdownToggle(
														item.id
													)
												}
											>
												{ item.label }
											</SidebarDropdownItem>
										);
									}

									if ( item.parent_type === 'drilldown' ) {
										return (
											<SidebarDrilldownItem
												key={ item.id }
												id={ item.id }
												icon={ item.icon }
												badge={ item.badge }
												shouldShowPlaceholder={
													hasRealIcons
												}
												onNavigate={ handleNavigate }
											>
												{ item.label }
											</SidebarDrilldownItem>
										);
									}

									if ( item.parent_type === 'submenu' ) {
										return (
											<SidebarSubmenuItem
												key={ item.id }
												id={ item.id }
												to={ item.to }
												icon={ item.icon }
												badge={ item.badge }
												shouldShowPlaceholder={
													hasRealIcons
												}
												onNavigate={ handleNavigate }
												className="zero-admin-sidebar-navigation-item"
											>
												{ item.label }
											</SidebarSubmenuItem>
										);
									}

									return (
										<SidebarNavigationItem
											key={ item.id }
											to={ item.to }
											icon={ item.icon }
											badge={ item.badge }
											shouldShowPlaceholder={
												hasRealIcons
											}
										>
											{ item.label }
										</SidebarNavigationItem>
									);
								} ) }
							</div>
						}
					/>
				</div>

				{ /* Nudge area - only shows when there's a nudge */ }
				{ nudge && (
					<ErrorBoundary>
						<NudgeDisplay nudge={ nudge } />
					</ErrorBoundary>
				) }
				<Stack
					direction="column"
					gap={ 1 }
					className="zero-admin-sidebar__footer"
				>
					<Stack
						direction="row"
						align="center"
						justify="space-between"
					>
						<UserMenuButton />
						<Stack
							direction="row"
							gap={ 1 }
							justify="space-between"
							align="center"
						>
							<IconButton
								size="small"
								variant="minimal"
								tone="neutral"
								icon={ AIIcon }
								label={ __( 'Open Chat', 'zero-admin' ) }
								onClick={ toggleAgentChat }
							/>
							<MetaMenu
								parent="wp-logo"
								icon={ help }
								label={ __( 'Help', 'zero-admin' ) }
							/>
						</Stack>
					</Stack>
				</Stack>
			</div>
		</>
	);
}

export default Sidebar;
