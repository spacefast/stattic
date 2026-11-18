import {
	useParams,
	useNavigate,
	useSearch,
	useInvalidate,
} from '@wordpress/route';
import {
	Page,
	RouterLink,
	unlock,
	viewToQuery,
} from '@first-admin/admin-toolkit';
// eslint-disable-next-line @first-admin/design-system/use-new-components -- See: ARC-1359
import { Tabs } from '@first-admin/design-system';
import { useView, DataViews } from '@first-admin/views';
import { filterSortAndPaginate } from '@first-admin/dataviews';
import type { View, Action } from '@first-admin/dataviews';
import {
	store as coreStore,
	privateApis as coreDataPrivateApis,
} from '@wordpress/core-data';
import { Button, DropZone } from '@wordpress/components';
import { useSelect, useRegistry } from '@wordpress/data';
import { useCallback, useMemo } from '@wordpress/element';
import { serializeFilters, usePageView } from '@first-admin/telemetry';
import { privateApis as editorPrivateApis } from '@wordpress/editor';
import type { Post } from '@wordpress/core-data';
import { usePostFields } from '@first-admin/fields-next';
import { __ } from '@wordpress/i18n';
import { useQuickEditAction } from './actions/quick-edit';
import { useFullEditAction } from './actions/full-edit';
import { AddNewMediaUploadButton } from './add-new-media';
import './style.scss';
import { getDefaultView } from './view-utils';

// Unlock WordPress private APIs
const { useEntityRecordsWithPermissions } = unlock( coreDataPrivateApis );
const { usePostActions } = unlock( editorPrivateApis );

function getItemId( item: Post ) {
	return item.id.toString();
}

function getItemLevel( item: Post ) {
	return ( item as { level?: number } ).level ?? 0;
}

function PostList() {
	const invalidate = useInvalidate();
	const { type: postType, slug = 'all' } = useParams( {
		from: '/types/$type/list/$slug',
	} );
	const navigate = useNavigate();
	const searchParams = useSearch( { from: '/types/$type/list/$slug' } );
	const registry = useRegistry();
	const postTypeObject = useSelect(
		( select ) => select( coreStore ).getPostType( postType ),
		[ postType ]
	);

	const labels = postTypeObject?.labels;
	const canCreateRecord = useSelect(
		( select ) =>
			select( coreStore ).canUser( 'create', {
				kind: 'postType',
				name: postType,
			} ),
		[ postType ]
	);

	const defaultLayouts = useMemo(
		() =>
			postTypeObject?.default_layouts || {
				table: {},
				grid: {},
				list: {},
			},
		[ postTypeObject?.default_layouts ]
	);

	const defaultView: View = useMemo( () => {
		return getDefaultView( postTypeObject, slug );
	}, [ postTypeObject, slug ] );

	// Callback to handle URL query parameter changes
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

	// Use the new view persistence hook
	const { view, isModified, updateView, resetToDefault } = useView( {
		kind: 'postType',
		name: postType,
		defaultView,
		queryParams: searchParams,
		onChangeQueryParams: handleQueryParamsChange,
	} );

	const onReset = () => {
		resetToDefault();
		invalidate();
	};
	const onChangeView = ( newView: View ) => {
		updateView( newView );
		if ( newView.type !== view.type ) {
			// The rendered surfaces depend on the view type,
			// so we need to retrigger the router loader when switching the view type.
			// try switching from list to table and vice versa.
			invalidate();
		}
	};

	const isClientSidePostType = postType === 'wp_template';

	const postTypeQuery = useMemo(
		() => viewToQuery( view, postType ),
		[ view, postType ]
	);
	const { records, totalItems, totalPages, isResolving } =
		useEntityRecordsWithPermissions( 'postType', postType, {
			...postTypeQuery,
			...( isClientSidePostType ? { per_page: -1 } : {} ),
		} );

	const { isLoading: isLoadingFields, fields: allFields } = usePostFields( {
		postType,
	} );

	// Hide status column except in 'All' tab, and disable status filtering
	const fields = useMemo( () => {
		return allFields
			.filter( ( field: { id: string } ) => {
				// Hide status column in specific status tabs
				if ( field.id === 'status' && slug !== 'all' ) {
					return false;
				}
				return true;
			} )
			.map( ( field: { id: string; filterBy?: any } ) => {
				// Disable status field filtering since we use tabs
				if ( field.id === 'status' ) {
					return { ...field, filterBy: false };
				}
				return field;
			} );
	}, [ allFields, slug ] );

	// `wp_template` doesn't server-side search, so
	// use the full record set and let DataViews handle filtering locally.
	const { data: posts, paginationInfo } = useMemo( () => {
		if ( isClientSidePostType ) {
			return filterSortAndPaginate< Post >( records, view, fields );
		}

		return {
			data: records ?? [],
			paginationInfo: {
				totalItems: totalItems ?? 0,
				totalPages: totalPages ?? 1,
			},
		};
	}, [
		fields,
		isClientSidePostType,
		records,
		totalItems,
		totalPages,
		view,
	] );

	usePageView( isResolving, {
		total_pages: paginationInfo.totalPages,
		list_size: posts.length,
		tab_name: slug,
		post_type: postType,
		filter: serializeFilters( view.filters ),
	} );

	// Helper function to clean up postIds from URL after deletion
	const cleanupDeletedPostIdsFromUrl = useCallback(
		( deletedItems: Post[] ) => {
			const deletedIds = deletedItems.map( ( item: Post ) =>
				item.id.toString()
			);
			const currentPostIds = searchParams.postIds || [];
			const remainingPostIds = currentPostIds.filter(
				( id: string ) => ! deletedIds.includes( id )
			);

			if ( remainingPostIds.length !== currentPostIds.length ) {
				navigate( {
					search: {
						...searchParams,
						postIds:
							remainingPostIds.length > 0
								? remainingPostIds
								: undefined,
					},
				} );
			} else {
				// If no change in the url, the first item might have changed.
				invalidate();
			}
		},
		[ invalidate, searchParams, navigate ]
	);

	const postTypeActions: Action< Post >[] = usePostActions( {
		postType,
		context: 'list',
		onActionPerformed: ( actionId: string, items: Post[] ) => {
			// Clean up URL when delete actions are performed
			if (
				actionId === 'move-to-trash' ||
				actionId === 'permanently-delete'
			) {
				cleanupDeletedPostIdsFromUrl( items );
			}
		},
	} );

	const quickEditAction = useQuickEditAction();
	const fullEditAction = useFullEditAction();
	const editAction = view.type === 'list' ? fullEditAction : quickEditAction;

	const actions = useMemo( () => {
		return [
			editAction,
			...postTypeActions?.flatMap< Action< Post > >( ( action ) => {
				switch ( action.id ) {
					case 'permanently-delete':
						return [
							{
								...action,
								isEligible( item ) {
									if ( item.type === 'attachment' ) {
										return true;
									}
									return action.isEligible?.( item ) ?? false;
								},
							},
						];

					// Media can in some circumstances need a trash option, but
					// we need to extend the REST API to support it. See
					// https://github.com/WordPress/wordpress-develop/pull/9210.
					// Once that is merged we should fix this.
					case 'move-to-trash':
						return [
							{
								...action,
								isEligible( item ) {
									if ( item.type === 'attachment' ) {
										return false;
									}
									return action.isEligible?.( item ) ?? false;
								},
							},
						];

					// Skip revisions as the admin does not support it
					case 'view-post-revisions':
						return [];

					// Skip "Order" action — archaic WP page ordering, not needed in CIAB
					case 'order-pages':
						return [];

					// Skip "Set as Posts Page" — CIAB doesn't support blogs
					case 'set-as-posts-page':
						return [];

					// Skip duplicate post action if not supported by the post type.
					case 'duplicate-post':
						if (
							! postTypeObject?.supports?.[ 'duplicate-post' ]
						) {
							return [];
						}
						break;
				}

				return [ action ];
			} ),
		];
	}, [ editAction, postTypeActions, postTypeObject?.supports ] );

	const handleTabChange = useCallback(
		( status: string ) => {
			navigate( {
				to: `/types/${ postType }/list/${ status }`,
			} );
		},
		[ navigate, postType ]
	);

	if ( ! postTypeObject ) {
		return null;
	}

	const selection = searchParams.postIds ?? [];

	// Auto-select first post in list view if none selected
	if ( view.type === 'list' && selection.length === 0 && posts?.length > 0 ) {
		selection.push( posts[ 0 ].id.toString() );
	}

	// Until list view supports multi selection, only keep the first item.
	if ( view.type === 'list' ) {
		selection.splice( 1 );
	}

	return (
		<Page
			title={ postTypeObject.labels?.name }
			subTitle={ postTypeObject.labels?.description }
			className={ `${ postTypeObject.name.toLowerCase() }-page` }
			actions={
				( labels?.add_new_item &&
					canCreateRecord &&
					postType !== 'attachment' &&
					postType !== 'wp_template' && (
						<Button
							variant="primary"
							onClick={ () => {
								navigate( {
									to: `/types/${ postType }/new`,
								} );
							} }
							size="compact"
						>
							{ labels.add_new_item }
						</Button>
					) ) ||
				( labels?.add_new_item &&
					canCreateRecord &&
					postType === 'attachment' && (
						<AddNewMediaUploadButton
							buttonText={ labels?.add_new_item }
							buttonVariant="primary"
						/>
					) )
			}
			hasPadding={ false }
		>
			{ canCreateRecord && 'import_handler' in postTypeObject && (
				<DropZone
					onFilesDrop={ ( files ) => {
						import( postTypeObject.import_handler as string ).then(
							( module ) => {
								module.default( registry, files );
							}
						);
					} }
					onHTMLDrop={ ( html ) => {
						import( postTypeObject.import_handler as string ).then(
							( module ) => {
								module.default( registry, html );
							}
						);
					} }
					label={ __( 'Drop files to upload', 'first-admin' ) }
					className="post-list__drop-zone"
				/>
			) }
			<DataViews
				data={ posts }
				fields={ fields }
				view={ view }
				onChangeView={ onChangeView }
				showLayoutSwitcher
				actions={ actions }
				isLoading={ isResolving || isLoadingFields }
				paginationInfo={ paginationInfo }
				onReset={ isModified ? onReset : false }
				defaultLayouts={ defaultLayouts }
				getItemId={ getItemId }
				getItemLevel={ getItemLevel }
				selection={ selection }
				onChangeSelection={ ( items: string[] ) => {
					navigate( {
						search: {
							...searchParams,
							postIds: items.length > 0 ? items : undefined,
							edit:
								items.length === 0
									? undefined
									: searchParams.edit,
						},
					} );
				} }
				renderItemLink={ ( { item, ...props }: { item: Post } ) => (
					<RouterLink
						to={ `/types/${ postType }/edit/${ encodeURIComponent(
							item.id
						) }` }
						{ ...props }
						onClick={ ( event ) => {
							// Temporary fix to prevent triggering
							// onChangeSelection, which would override the URL.
							event.stopPropagation();
						} }
					/>
				) }
				tabs={
					!! postTypeObject.views?.length &&
					postTypeObject.views?.length > 1 && (
						<Tabs.Root
							onValueChange={ handleTabChange }
							value={ slug ?? null }
						>
							<Tabs.List density="compact">
								{ postTypeObject.views.map(
									( filter: {
										slug: string;
										label: string;
									} ) => (
										<Tabs.Tab
											value={ filter.slug }
											key={ filter.slug }
										>
											{ filter.label }
										</Tabs.Tab>
									)
								) }
							</Tabs.List>
						</Tabs.Root>
					)
				}
			/>
		</Page>
	);
}

export default PostList;
