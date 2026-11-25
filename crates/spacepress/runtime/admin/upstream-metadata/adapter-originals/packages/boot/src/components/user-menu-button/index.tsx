import { Link } from '@wordpress/route';
import { unlock } from '@zero-admin/admin-toolkit';
import { Button, Icon } from '@wordpress/ui';
// eslint-disable-next-line @zero-admin/design-system/use-new-components -- See: ARC-1359
import { Tooltip } from '@zero-admin/design-system';
import { privateApis as componentsPrivateApis } from '@wordpress/components';
import { commentAuthorAvatar } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { store as zeroAdminStore } from '../../store';
import './style.scss';

const { Menu } = unlock( componentsPrivateApis );

function UserMenuButton() {
	const { currentUser, logoutUrl } = useSelect( ( select ) => {
		const { getCurrentUser } = select( coreStore );
		const { getDashboardContext } = select( zeroAdminStore );
		return {
			currentUser: getCurrentUser(),
			logoutUrl: getDashboardContext().logout_url,
		};
	}, [] );
	const hasAvatar = currentUser?.avatar_urls?.[ '48' ];

	return (
		<Tooltip.Provider delay={ 0 }>
			<Tooltip.Root>
				<Menu onOpenChange={ () => {} }>
					<Menu.TriggerButton
						render={
							<Tooltip.Trigger
								aria-label={ __( 'Account', 'zero-admin' ) }
								render={
									<Button
										variant="minimal"
										size="compact"
										tone="neutral"
									/>
								}
								className="zero-admin-user-menu-button__button"
							>
								{ hasAvatar ? (
									<img
										src={ currentUser.avatar_urls[ '48' ] }
										alt=""
										width={ 22 }
										height={ 22 }
										style={ {
											objectFit: 'cover',
											borderRadius: '50%',
										} }
									/>
								) : (
									<Icon
										size={ 22 }
										icon={ commentAuthorAvatar }
									/>
								) }
							</Tooltip.Trigger>
						}
					/>
					<Menu.Popover>
						<Menu.Item render={ <Link to="/profile" /> }>
							<Menu.ItemLabel>
								{ __( 'Profile', 'zero-admin' ) }
							</Menu.ItemLabel>
						</Menu.Item>
						<Menu.Item
							render={
								/* eslint-disable-next-line jsx-a11y/anchor-has-content */
								<a href={ logoutUrl } />
							}
						>
							<Menu.ItemLabel>
								{ __( 'Log out', 'zero-admin' ) }
							</Menu.ItemLabel>
						</Menu.Item>
					</Menu.Popover>
				</Menu>
				<Tooltip.Popup>{ __( 'Account', 'zero-admin' ) }</Tooltip.Popup>
			</Tooltip.Root>
		</Tooltip.Provider>
	);
}

export default UserMenuButton;
