import { useFieldCollections } from '@spacepress-admin/core-data-next';
import { useViewConfig } from '@spacepress-admin/views';
import { Page, unlock } from '@spacepress-admin/admin-toolkit';
import { useParams, useNavigate } from '@wordpress/route';
import { Button, Icon } from '@wordpress/ui';
import { decodeEntities } from '@wordpress/html-entities';
import { __, sprintf } from '@wordpress/i18n';
import { store as spAdminStore, type StoreSelectors } from '@spacepress-admin/boot';
import { select, useSelect, useDispatch } from '@wordpress/data';
import {
	__experimentalGrid as Grid,
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
	Button as TriggerButton,
	privateApis as componentsPrivateApis,
} from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import type { User } from '@wordpress/core-data';
import { store as noticesStore } from '@wordpress/notices';
import { useMemo, useState, useCallback, useRef } from '@wordpress/element';
import { moreVertical, arrowUpRight } from '@wordpress/icons';
import { DataForm } from '@spacepress-admin/dataviews';
import { useTrack } from '@spacepress-admin/telemetry';
import {
	isInviteUser,
	isPendingInvite,
	isStoreOwner,
	getUserDisplayName,
	isInInviteMode,
} from '../user/utils';
import type { ExtendedUser, UserViewConfigData } from '../user/types';
import { DeleteUserModal } from '../user/components/delete-user-modal';
import './style.scss';

interface UserEditContentProps {
	userId: number;
	breadcrumbs?: Array< { label: string; to?: string } >;
	showBadges?: boolean;
}

interface UserProfileBreadcrumbsProps {
	user: ExtendedUser;
	breadcrumbItems: Array< { label: string; to?: string } >;
	showBadges?: boolean;
	isInviteMode?: boolean;
}

export function UserProfileBreadcrumbs( {
	user,
	breadcrumbItems,
	showBadges = true,
	isInviteMode = false,
}: UserProfileBreadcrumbsProps ) {
	// eslint-disable-next-line @typescript-eslint/no-unsafe-assignment -- unlock() returns untyped private APIs
	const { Badge } = unlock( componentsPrivateApis );
	const showPendingInviteBadge = isPendingInvite( user );
	const showStoreOwnerBadge = isStoreOwner( user );

	return (
		<HStack spacing={ 2 } justify="flex-start">
			<Page.Breadcrumbs items={ breadcrumbItems } />
			{ showBadges && showPendingInviteBadge && (
				<Badge>{ __( 'Pending invite', 'spacepress-admin' ) }</Badge>
			) }
			{ showBadges && showStoreOwnerBadge && isInviteMode && (
				<Badge>{ __( 'Store owner', 'spacepress-admin' ) }</Badge>
			) }
		</HStack>
	);
}

export function UserEditContent( {
	userId,
	breadcrumbs,
	showBadges = true,
}: UserEditContentProps ) {
	const [ isDeleteModalOpen, setIsDeleteModalOpen ] = useState( false );
	const navigate = useNavigate();
	// eslint-disable-next-line @typescript-eslint/no-unsafe-assignment, @typescript-eslint/no-unsafe-call -- useTrack is a WP Module that loses type resolution in strict mode
	const track = useTrack();

	const data: ExtendedUser | undefined = useSelect( () => {
		if ( ! userId ) {
			return undefined;
		}

		if ( isInviteUser( userId ) ) {
			// eslint-disable-next-line @typescript-eslint/no-unnecessary-type-assertion -- keeps return type consistent with getEditedEntityRecord branch
			return select( coreStore ).getEntityRecord(
				'root',
				'user',
				userId
			) as ExtendedUser | undefined;
		}

		// eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- getEditedEntityRecord returns wide EntityRecord type
		return select( coreStore ).getEditedEntityRecord(
			'root',
			'user',
			userId
		) as ExtendedUser | undefined;
	}, [ userId ] );

	const { editEntityRecord, saveEditedEntityRecord } =
		useDispatch( coreStore );
	const { createSuccessNotice, createErrorNotice, removeNotice } =
		useDispatch( noticesStore );

	const fields = useFieldCollections( 'root', 'user' );
	const viewConfig = useViewConfig( 'root', 'user' );
	const { editForm } = viewConfig;

	const { currentUser, partnerContext } = useSelect(
		// eslint-disable-next-line @typescript-eslint/no-shadow -- callback parameter intentionally shadows module-level select
		( select ) => {
			const { getCurrentUser } = select( coreStore );
			return {
				currentUser: getCurrentUser(),
				partnerContext: (
					select( spAdminStore ) as StoreSelectors
				 ).getDashboardContext().partner,
			};
		},
		[]
	);

	// eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- PHP-added custom properties on ViewConfig
	const configData = viewConfig.viewConfig as UserViewConfigData;
	const isInviteMode = isInInviteMode( configData );

	// Mark fields as read-only based on view config
	const fieldsWithReadOnly = useMemo( () => {
		if ( ! fields ) {
			return fields;
		}

		const readonlyFields = configData?.readonly_fields?.edit_form || [];

		const isSelfProfile = data?.id === currentUser?.id;

		return fields.map( ( field ) => {
			const isRolesField = field.id === 'roles';
			const isAvatarField = field.id === 'avatar';

			// For invitees, roles should be read-only (can't change pending invite role)
			// For self profile in invite mode (CIAB), roles should be read-only
			// For self profile in non-invite mode, roles should be disabled
			// For the store owner, no one can change their role
			const isEditingStoreOwner = data && isStoreOwner( data );
			const shouldDisableRoles =
				( isRolesField && isSelfProfile && ! isInviteMode ) ||
				( isRolesField && isEditingStoreOwner );

			const isInvite = isRolesField && isInviteUser( data );
			const shouldMakeReadOnly =
				readonlyFields.includes( field.id ) ||
				isInvite ||
				( isRolesField && isSelfProfile && isInviteMode );

			return {
				...field,
				// Hide avatar label in the form (use space to avoid fallback to field id)
				label: isAvatarField ? ' ' : field.label,
				description: isInvite ? '' : field.description,
				readOnly: shouldMakeReadOnly,
				disabled: shouldDisableRoles,
			};
		} );
	}, [ fields, configData, data, currentUser, isInviteMode ] );

	// Stabilize data reference to avoid re-creating handleFieldChange on every edit.
	const dataRef = useRef( data );
	dataRef.current = data;

	// Guard against concurrent saves (e.g. rapid modal confirms).
	const isSavingRef = useRef( false );

	// Save immediately on field change (modal confirms then calls onChange).
	const handleFieldChange = useCallback(
		async ( changes: Partial< User > ) => {
			if ( isSavingRef.current || isInviteUser( dataRef.current ) ) {
				return;
			}

			isSavingRef.current = true;
			const fieldNames = Object.keys( changes ).join( ',' );
			// eslint-disable-next-line @typescript-eslint/no-unsafe-call, @typescript-eslint/no-unsafe-member-access -- WP Module loses type info
			track.buttonClick( 'save', { field_name: fieldNames } );

			try {
				void removeNotice( 'update-user-error' );

				await editEntityRecord( 'root', 'user', userId, changes );
				await saveEditedEntityRecord( 'root', 'user', userId, {
					throwOnError: true,
				} );

				void createSuccessNotice(
					sprintf(
						/* translators: %s: user name or email */
						__( '%s updated.', 'spacepress-admin' ),
						dataRef.current
							? getUserDisplayName( dataRef.current )
							: ''
					),
					{
						type: 'snackbar',
						id: 'update-user-success',
					}
				);
			} catch ( error: unknown ) {
				// eslint-disable-next-line @typescript-eslint/no-unsafe-call, @typescript-eslint/no-unsafe-member-access -- WP Module loses type info
				track.buttonError( 'save', error, {
					field_name: fieldNames,
				} );
				const message =
					typeof error === 'object' &&
					error !== null &&
					'message' in error &&
					typeof error.message === 'string'
						? decodeEntities( error.message )
						: __( 'Could not update user.', 'spacepress-admin' );
				void createErrorNotice( message, {
					type: 'snackbar',
					id: 'update-user-error',
				} );
			} finally {
				isSavingRef.current = false;
			}
		},
		[
			userId,
			editEntityRecord,
			saveEditedEntityRecord,
			createSuccessNotice,
			createErrorNotice,
			removeNotice,
			track,
		]
	);

	if ( ! data || ! currentUser.id || ! fieldsWithReadOnly || ! editForm ) {
		return null;
	}

	// eslint-disable-next-line @typescript-eslint/no-unsafe-assignment -- unlock() returns untyped private APIs
	const { Menu } = unlock( componentsPrivateApis );
	const isSelfProfile = data.id === currentUser.id;

	// Determine breadcrumb items
	const breadcrumbItems = breadcrumbs || [
		{
			label: __( 'Users', 'spacepress-admin' ),
			to: '/users/list/all',
		},
		{
			label: data.email || __( 'User', 'spacepress-admin' ),
		},
	];

	return (
		<Page
			className="woocommerce-user-profile-page"
			hasPadding={ true }
			hasBorder={ true }
			contentWidth={ 'constrained' }
			breadcrumbs={
				<UserProfileBreadcrumbs
					user={ data }
					breadcrumbItems={ breadcrumbItems }
					showBadges={ showBadges }
					isInviteMode={ isInviteMode }
				/>
			}
			actions={
				<HStack spacing={ 2 }>
					{ isSelfProfile && isInviteMode && partnerContext && (
						<Button
							variant="outline"
							size="compact"
							onClick={ () => {
								// eslint-disable-next-line @typescript-eslint/no-unsafe-call, @typescript-eslint/no-unsafe-member-access -- WP Module loses type info
								track.buttonClick( 'manage_profile' );
								window.open(
									`${ partnerContext.dashboard_url }/me/profile`,
									'_blank',
									'noopener,noreferrer'
								);
							} }
							style={ { cursor: 'pointer' } }
						>
							{ sprintf(
								/* translators: %s: partner dashboard name (e.g. "Woo Store") */
								__( 'Manage profile in %s', 'spacepress-admin' ),
								partnerContext.dashboard_name
							) }
							<Icon icon={ arrowUpRight } size={ 16 } />
						</Button>
					) }
					{ ! isSelfProfile && (
						<Menu>
							<Menu.TriggerButton
								render={
									<TriggerButton
										icon={ moreVertical }
										size="compact"
										label={ __( 'Actions', 'spacepress-admin' ) }
									/>
								}
							/>
							<Menu.Popover>
								<Menu.Item
									onClick={ () => {
										setIsDeleteModalOpen( true );
									} }
								>
									<Menu.ItemLabel>
										{ __( 'Delete', 'spacepress-admin' ) }
									</Menu.ItemLabel>
								</Menu.Item>
							</Menu.Popover>
						</Menu>
					) }
				</HStack>
			}
		>
			<Grid
				columns={ 3 }
				gap={ 8 }
				justify="center"
				templateColumns="600px"
			>
				<VStack spacing={ 8 }>
					<DataForm
						data={ data }
						fields={ fieldsWithReadOnly }
						form={ editForm }
						onChange={ handleFieldChange }
					/>
				</VStack>
			</Grid>

			{ data && (
				<DeleteUserModal
					users={ [ data ] }
					isOpen={ isDeleteModalOpen }
					onClose={ () => setIsDeleteModalOpen( false ) }
					onSuccess={ () => {
						void navigate( { to: '/users/list/all' } );
					} }
				/>
			) }
		</Page>
	);
}

export default function Content() {
	const { userId: urlUserId }: { userId: string } = useParams( {
		from: '/users/profile/$userId',
	} );
	const userId = Number( urlUserId );

	return <UserEditContent userId={ userId } />;
}
