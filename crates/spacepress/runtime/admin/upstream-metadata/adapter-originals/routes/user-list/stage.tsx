/* eslint-disable @typescript-eslint/no-unsafe-assignment, @typescript-eslint/no-unsafe-argument, @typescript-eslint/no-unsafe-call, @typescript-eslint/no-unsafe-member-access -- unlock() and TanStack Router hooks return untyped values in strict mode */
import {
	useNavigate,
	useSearch,
	useParams,
	useInvalidate,
} from '@wordpress/route';
import { DataViews, useView, useViewConfig } from '@first-admin/views';
import { useFieldCollections } from '@first-admin/core-data-next';
import { Page, RouterLink, unlock } from '@first-admin/admin-toolkit';
import type { Action, View } from '@first-admin/dataviews';
import {
	store as coreStore,
	privateApis as coreDataPrivateApis,
	type User,
} from '@wordpress/core-data';
import { Button } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useCallback, useMemo } from '@wordpress/element';
import {
	serializeFilters,
	usePageView,
	useTrack,
	useTrackedDataView,
} from '@first-admin/telemetry';
import { useFullEditAction } from './actions/full-edit';
import { useQuickEditAction } from './actions/quick-edit';
import { useDeleteUserAction } from './actions/delete-user';
import { useUpdateUserRoleAction } from './actions/update-role';
import { RoleTabs } from './role-tabs';
import { getDefaultView, viewToQuery } from './view-utils';
import type { ExtendedUser } from '../user/types';
import { UserListEmptyState } from './empty-state';
import './style.scss';

const { useEntityRecordsWithPermissions } = unlock( coreDataPrivateApis );

function getItemId( item: User ) {
	return item.id.toString();
}

export default function UserList() {
	const invalidate = useInvalidate();
	const { slug = 'all' } = useParams( {
		from: '/users/list/$slug',
	} );
	const navigate = useNavigate();
	const track = useTrack();
	const searchParams = useSearch( {
		from: '/users/list/$slug',
	} );

	const canCreateUsers = useSelect(
		( select ) =>
			select( coreStore ).canUser( 'create', {
				kind: 'root',
				name: 'user',
			} ),
		[]
	);

	const {
		viewConfig,
		labels,
		defaultLayouts: configDefaultLayouts,
	} = useViewConfig( 'root', 'user' );

	const defaultLayouts = useMemo(
		() =>
			configDefaultLayouts || {
				table: {
					fields: [ 'name', 'email', 'roles', 'posts' ],
					density: 'comfortable',
					titleField: 'username',
					mediaField: 'avatar',
				},
			},
		[ configDefaultLayouts ]
	);

	const defaultView: View = useMemo(
		() => getDefaultView( slug, viewConfig ),
		[ slug, viewConfig ]
	);

	const handleQueryParamsChange = useCallback(
		( params: { page?: number; search?: string } ) => {
			void navigate( {
				search: {
					...searchParams,
					...params,
				},
			} );
		},
		[ searchParams, navigate ]
	);

	const { view, isModified, updateView, resetToDefault } = useView( {
		kind: 'root',
		name: 'user',
		defaultView,
		queryParams: searchParams,
		onChangeQueryParams: handleQueryParamsChange,
	} );

	const userQuery = useMemo( () => viewToQuery( view ), [ view ] );

	const {
		records: users,
		totalItems,
		totalPages,
		isResolving,
	} = useEntityRecordsWithPermissions( 'root', 'user', userQuery );

	const listSize = users?.length ?? 0;

	const dataViewTracking = useTrackedDataView( view, {
		tabName: slug,
		totalPages,
		totalItems: totalItems ?? 0,
		listSize,
		isLoading: isResolving,
	} );

	const onReset = useCallback( () => {
		dataViewTracking.resetView();
		resetToDefault();
		void invalidate();
	}, [ resetToDefault, invalidate, dataViewTracking ] );

	const onChangeView = useCallback(
		( newView: View ) => {
			dataViewTracking.onViewChange( newView );
			updateView( newView );
			if ( newView.type !== view.type ) {
				// The rendered surfaces depend on the view type,
				// so we need to retrigger the router loader when switching the view type.
				// try switching from list to table and vice versa.
				void invalidate();
			}
		},
		[ updateView, view.type, invalidate, dataViewTracking ]
	);

	const handleTabChange = useCallback(
		( role: string ) => {
			track.tabClick( slug, role, {
				total_pages: totalPages,
				filter: serializeFilters( view.filters ),
				list_size: listSize,
			} );
			void navigate( {
				to: `/users/list/${ role }`,
			} );
		},
		[ navigate, slug, track, totalPages, view.filters, listSize ]
	);

	usePageView( isResolving, {
		total_pages: totalPages,
		list_size: listSize,
		tab_name: slug,
		filter: serializeFilters( view.filters ),
	} );

	const fields = useFieldCollections( 'root', 'user' );

	// Reflect current selection from the URL so the corresponding row stays highlighted
	const selection = ( searchParams.userIds ?? [] ).map( ( id: number ) =>
		id.toString()
	);

	// Until list view supports multi-selection in list mode, only keep the first item.
	if ( view.type === 'list' ) {
		selection.splice( 1 );
	}

	const quickEditAction = useQuickEditAction();
	const fullEditAction = useFullEditAction();
	const deleteAction = useDeleteUserAction();
	const updateRoleAction = useUpdateUserRoleAction();

	const actions: Action< User >[] = useMemo( () => {
		const actionsArray = [];

		// Quick edit action - only show in table view
		if ( view.type !== 'list' && quickEditAction ) {
			actionsArray.push( quickEditAction );
		}

		// Full edit action - show in both views but with different priority
		if ( fullEditAction ) {
			if ( view.type === 'list' ) {
				actionsArray.push( fullEditAction );
			} else {
				actionsArray.push( {
					...fullEditAction,
					isPrimary: false,
				} );
			}
		}

		// Update role action
		if ( updateRoleAction ) {
			actionsArray.push( updateRoleAction );
		}

		// Delete action
		if ( deleteAction ) {
			actionsArray.push( deleteAction );
		}

		return actionsArray;
	}, [
		quickEditAction,
		fullEditAction,
		view.type,
		updateRoleAction,
		deleteAction,
	] );

	const trackedActions = useMemo(
		() => dataViewTracking.withActionTracking( actions ),
		[ actions, dataViewTracking ]
	);

	// Get empty content based on current tab.
	const emptyContent = useMemo(
		() => <UserListEmptyState slug={ slug } />,
		[ slug ]
	);

	return (
		<Page
			title={ labels?.title ?? '' }
			subTitle={ labels?.description ?? '' }
			className="user-list__stage"
			actions={
				canCreateUsers && (
					<Button
						variant="primary"
						onClick={ () => {
							track.buttonClick(
								labels?.add_button_label ?? 'invite_user'
							);
							void navigate( { to: '/users/add' } );
						} }
						size="compact"
					>
						{ labels?.add_button_label ?? '' }
					</Button>
				)
			}
			hasPadding={ false }
		>
			<DataViews
				data={ users ?? [] }
				fields={ fields }
				view={ view }
				onChangeView={ onChangeView }
				actions={ trackedActions }
				isLoading={ isResolving }
				paginationInfo={ {
					totalItems: totalItems ?? 0,
					totalPages: totalPages ?? 1,
				} }
				onReset={ isModified ? onReset : false }
				defaultLayouts={ defaultLayouts }
				getItemId={ getItemId }
				selection={ selection }
				onChangeSelection={ ( items: string[] ) => {
					void navigate( {
						search: {
							...searchParams,
							userIds:
								items.length > 0
									? items.map( ( item ) => Number( item ) )
									: undefined,
						},
					} );
				} }
				renderItemLink={ ( {
					item,
					...props
				}: {
					item: ExtendedUser;
				} ) => (
					<RouterLink
						to={ `/users/profile/${ item.id }` }
						{ ...props }
						onClick={ ( event ) => {
							event.stopPropagation();
							dataViewTracking.listItemClick( 'user_item' );
						} }
					/>
				) }
				tabs={
					<RoleTabs onChange={ handleTabChange } value={ slug } />
				}
				empty={ emptyContent }
			/>
		</Page>
	);
}
/* eslint-enable @typescript-eslint/no-unsafe-assignment, @typescript-eslint/no-unsafe-argument, @typescript-eslint/no-unsafe-call, @typescript-eslint/no-unsafe-member-access */
