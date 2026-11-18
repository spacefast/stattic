import type { View, Action } from '@first-admin/dataviews';
import { Page, unlock } from '@first-admin/admin-toolkit';
import { usePostFields } from '@first-admin/fields-next';
import { useView, DataViews } from '@first-admin/views';
import { Button } from '@wordpress/ui';
import {
	useNavigate,
	useSearch,
	privateApis as routePrivateApis,
} from '@wordpress/route';
import { privateApis as coreDataPrivateApis } from '@wordpress/core-data';
import { useCallback, useMemo, useState } from '@wordpress/element';
import { serializeFilters, usePageView } from '@first-admin/telemetry';
import type { Post } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';
import { privateApis as editorPrivateApis } from '@wordpress/editor';
import { getDefaultView } from './view-utils';
import { useEditNavigationAction } from './actions/edit-navigation';
import { AddNavigationModal } from './add-navigation';

// Unlock WordPress private APIs
const { useLoaderData } = unlock( routePrivateApis );
const { useEntityRecordsWithPermissions } = unlock( coreDataPrivateApis );
const { usePostActions } = unlock( editorPrivateApis );

const NAVIGATION_POST_TYPE = 'wp_navigation';

const PRELOADED_NAVIGATION_MENUS_QUERY = {
	per_page: 100,
	status: [ 'publish', 'draft' ],
	order: 'desc',
	orderby: 'date',
};

function getItemId( item: Post ) {
	return item.id.toString();
}

function NavigationList() {
	const navigate = useNavigate();
	const searchParams = useSearch( { from: '/design/navigation/list' } );
	const loaderDataResult = useLoaderData( {
		from: '/design/navigation/list',
	} ) as
		| {
				canvas?: {
					postId?: number;
				};
		  }
		| undefined;
	const loaderData = loaderDataResult?.canvas;

	const defaultView: View = useMemo( () => {
		return getDefaultView();
	}, [] );

	const handleQueryParamsChange = useCallback(
		( params: { page?: number; search?: string } ) => {
			navigate( {
				search: {
					...searchParams,
					...params,
				},
			} );
		},
		[ searchParams, navigate ]
	);

	const { view, updateView, isModified, resetToDefault } = useView( {
		kind: 'postType',
		name: NAVIGATION_POST_TYPE,
		defaultView,
		queryParams: searchParams,
		onChangeQueryParams: handleQueryParamsChange,
	} );

	const {
		records: navigationMenus,
		totalItems,
		totalPages,
		isResolving,
	} = useEntityRecordsWithPermissions(
		'postType',
		NAVIGATION_POST_TYPE,
		PRELOADED_NAVIGATION_MENUS_QUERY
	);

	usePageView( isResolving, {
		total_pages: totalPages,
		list_size: navigationMenus.length,
		filter: serializeFilters( view.filters ),
	} );

	const { isLoading: isLoadingFields, fields } = usePostFields( {
		postType: NAVIGATION_POST_TYPE,
	} );
	const [ showAddModal, setShowAddModal ] = useState( false );

	const editAction = useEditNavigationAction();
	const postTypeActions: Action< Post >[] = usePostActions( {
		postType: NAVIGATION_POST_TYPE,
		context: 'list',
	} );

	const actions: Action< Post >[] = useMemo( () => {
		return [
			editAction,
			...( postTypeActions?.flatMap< Action< Post > >( ( action ) => {
				switch ( action.id ) {
					// Skip revisions as next admin does not support it
					case 'view-post-revisions':
						return [];
				}
				return [ action ];
			} ) ?? [] ),
		];
	}, [ editAction, postTypeActions ] );

	const selection =
		( searchParams.ids ?? [] ).map( ( id: number ) => id.toString() ) ?? [];

	if ( selection.length === 0 && loaderData?.postId ) {
		selection.push( loaderData.postId.toString() );
	}

	if ( view.type === 'list' ) {
		selection.splice( 1 );
	}

	return (
		<>
			<Page
				title={ __( 'Navigation', 'first-admin' ) }
				className="navigation-page"
				hasPadding={ false }
				actions={
					<Button
						variant="solid"
						size="compact"
						onClick={ () => setShowAddModal( true ) }
					>
						{ __( 'Add New', 'first-admin' ) }
					</Button>
				}
			>
				<DataViews
					data={ navigationMenus }
					fields={ fields }
					view={ view }
					onChangeView={ updateView }
					isLoading={ isResolving || isLoadingFields }
					actions={ actions }
					paginationInfo={ {
						totalItems,
						totalPages,
					} }
					defaultLayouts={ {
						list: {},
					} }
					getItemId={ getItemId }
					selection={ selection }
					onChangeSelection={ ( items: string[] ) => {
						navigate( {
							search: {
								...searchParams,
								ids:
									items.length > 0
										? items.map( ( id ) => Number( id ) )
										: undefined,
							},
						} );
					} }
					onReset={ isModified ? resetToDefault : false }
				/>
			</Page>
			{ showAddModal && (
				<AddNavigationModal
					closeModal={ () => setShowAddModal( false ) }
				/>
			) }
		</>
	);
}

export default NavigationList;
