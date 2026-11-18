import {
	useNavigate,
	useParams,
	useSearch,
	privateApis as routePrivateApis,
} from '@wordpress/route';
import type { View } from '@first-admin/dataviews';
import { Page, RouterLink, unlock } from '@first-admin/admin-toolkit';
import { Button } from '@wordpress/ui';
// eslint-disable-next-line @first-admin/design-system/use-new-components -- See: ARC-1359
import { EmptyState, Tabs } from '@first-admin/design-system';
import { useTaxonomyTermFields } from '@first-admin/fields-next';
import { useView, DataViews, useViewConfig } from '@first-admin/views';
import { useCallback, useMemo } from '@wordpress/element';
import {
	serializeFilters,
	usePageView,
	useTrack,
	useTrackedDataView,
} from '@first-admin/telemetry';
import {
	store as coreStore,
	privateApis as coreDataPrivateApis,
} from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import { tag } from '@wordpress/icons';
import type { Term } from '@wordpress/core-data';
import { getDefaultView, viewToQuery } from './view-utils';
import { useTaxonomyTermsActions } from './actions';
import './style.scss';

// eslint-disable-next-line @typescript-eslint/no-unsafe-assignment -- private API unlock has no types
const {
	useEntityRecordsWithPermissions,
}: {
	useEntityRecordsWithPermissions: (
		kind: string,
		name: string,
		query: Record< string, unknown >
	) => {
		records: Term[];
		totalItems: number;
		totalPages: number;
		isResolving: boolean;
		hasResolved: boolean;
	};
} = unlock( coreDataPrivateApis );
// eslint-disable-next-line @typescript-eslint/no-unsafe-assignment -- WordPress private API
const { useRouterState } = unlock( routePrivateApis );

const FALLBACK_DEFAULT_LAYOUTS = {
	table: {},
};

function getItemId( item: Term ) {
	return item.id.toString();
}

function getItemLevel( item: Term ) {
	// eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- REST API adds `level` when `orderby_hierarchy` is active
	return ( item as { level?: number } ).level ?? 0;
}

function TaxonomyTerms() {
	const { type, taxonomy }: { type: string; taxonomy: string } = useParams( {
		from: '/types/$type/taxonomies/$taxonomy',
	} );
	// eslint-disable-next-line @typescript-eslint/no-unsafe-assignment -- useSearch returns untyped params
	const searchParams: Record< string, unknown > = useSearch( {
		from: '/types/$type/taxonomies/$taxonomy',
	} );
	const navigate = useNavigate();

	const { fields } = useTaxonomyTermFields( taxonomy );

	const taxonomyObject = useSelect(
		( select ) => select( coreStore ).getTaxonomy( taxonomy ),
		[ taxonomy ]
	);

	// eslint-disable-next-line @typescript-eslint/no-unsafe-assignment -- useViewConfig return type is loosely typed
	const {
		viewConfig,
		labels,
		defaultLayouts: configDefaultLayouts,
	} = useViewConfig( 'taxonomy', taxonomy );

	const canCreateTerms = useSelect(
		( select ) =>
			select( coreStore ).canUser( 'create', {
				kind: 'taxonomy',
				name: taxonomy,
			} ),
		[ taxonomy ]
	);

	const defaultView = useMemo(
		() => getDefaultView( taxonomyObject, viewConfig ),
		[ taxonomyObject, viewConfig ]
	);

	const defaultLayouts = useMemo(
		() => configDefaultLayouts ?? FALLBACK_DEFAULT_LAYOUTS,
		[ configDefaultLayouts ]
	);

	const handleQueryParamsChange = useMemo(
		() => ( params: { page?: number; search?: string } ) => {
			void navigate( {
				to: '/types/$type/taxonomies/$taxonomy',
				params: { type, taxonomy },
				search: ( prev: Record< string, unknown > ) => ( {
					...prev,
					...params,
				} ),
			} );
		},
		[ navigate, type, taxonomy ]
	);

	// eslint-disable-next-line @typescript-eslint/no-unsafe-assignment -- useView return type is loosely typed
	const { view, isModified, updateView, resetToDefault } = useView( {
		kind: 'taxonomy',
		name: taxonomy,
		defaultView,
		queryParams: searchParams,
		onChangeQueryParams: handleQueryParamsChange,
	} );

	const taxonomyQuery = useMemo( () => viewToQuery( view ), [ view ] );

	const {
		records: terms,
		totalItems,
		totalPages,
		isResolving,
		hasResolved,
	} = useEntityRecordsWithPermissions( 'taxonomy', taxonomy, taxonomyQuery );

	const track = useTrack();

	// eslint-disable-next-line @typescript-eslint/no-unsafe-call -- WordPress private API
	const { isLoading: isRouterLoading } = useRouterState( {
		select: ( state: { isLoading: boolean } ) => ( {
			isLoading: state.isLoading,
		} ),
	} );
	const dataViewTracking = useTrackedDataView( view, {
		tabName: 'all',
		totalPages,
		totalItems,
		listSize: terms?.length ?? 0,
		isLoading: isResolving || isRouterLoading,
	} );

	const handleViewChange = useCallback(
		( newView: View ) => {
			dataViewTracking.onViewChange( newView );
			updateView( newView );
		},
		[ updateView, dataViewTracking ]
	);

	const handleOnReset = useCallback( () => {
		dataViewTracking.resetView();
		resetToDefault();
	}, [ resetToDefault, dataViewTracking ] );

	usePageView( isResolving, {
		total_pages: totalPages,
		list_size: terms.length,
		taxonomy,
		filter: serializeFilters( view.filters ),
	} );

	/* eslint-disable @typescript-eslint/no-unsafe-call, @typescript-eslint/no-unsafe-assignment */
	const actions = useTaxonomyTermsActions();
	/* eslint-enable @typescript-eslint/no-unsafe-call, @typescript-eslint/no-unsafe-assignment */

	const trackedActions = useMemo(
		() => dataViewTracking.withActionTracking( actions ),
		[ actions, dataViewTracking ]
	);

	const selection = useMemo( () => {
		// eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- searchParams is untyped
		const termIds = searchParams.termIds as number[] | undefined;
		return termIds ? termIds.map( ( id: number ) => id.toString() ) : [];
	}, [ searchParams.termIds ] );

	if ( ! taxonomyObject ) {
		return null;
	}

	return (
		<Page
			title={
				// eslint-disable-next-line @typescript-eslint/no-unsafe-assignment -- labels type is loosely typed
				labels?.title ||
				taxonomyObject.labels?.menu_name ||
				taxonomyObject.name
			}
			subTitle={ labels?.description }
			hasPadding={ false }
			actions={
				canCreateTerms && (
					<Button
						variant="solid"
						size="compact"
						onClick={ () => {
							track.buttonClick( 'add_term' );
							void navigate( {
								to: '/types/$type/taxonomies/$taxonomy/add',
								params: {
									type,
									taxonomy,
								},
							} );
						} }
					>
						{ labels?.add_button_label ||
							taxonomyObject.labels.add_new_item }
					</Button>
				)
			}
		>
			{ hasResolved && terms.length === 0 && ! searchParams.search ? (
				<EmptyState.Root className="first-admin-terms-list-content__no-terms">
					<EmptyState.Icon icon={ tag } />
					<EmptyState.Title>
						{ taxonomyObject.labels.no_terms }
					</EmptyState.Title>
					<EmptyState.Description>
						{ sprintf(
							// translators: %s: Taxonomy name.
							__(
								'No %s have been created yet. Create one to start organizing your content.',
								'first-admin'
							),
							taxonomyObject.labels.name.toLocaleLowerCase()
						) }
					</EmptyState.Description>
					<EmptyState.Actions>
						<Button
							variant="solid"
							onClick={ () => {
								void navigate( {
									to: '/types/$type/taxonomies/$taxonomy/add',
									params: {
										type,
										taxonomy,
									},
								} );
							} }
						>
							{ labels?.add_button_label ||
								taxonomyObject.labels.add_new_item }
						</Button>
					</EmptyState.Actions>
				</EmptyState.Root>
			) : (
				<DataViews
					data={ terms }
					fields={ fields }
					view={ view }
					onChangeView={ handleViewChange }
					actions={ trackedActions }
					isLoading={ isResolving || ! hasResolved }
					paginationInfo={ { totalItems, totalPages } }
					getItemId={ getItemId }
					getItemLevel={ getItemLevel }
					defaultLayouts={ defaultLayouts }
					onReset={ isModified ? handleOnReset : false }
					selection={ selection }
					onChangeSelection={ ( items: string[] ) => {
						void navigate( {
							to: '/types/$type/taxonomies/$taxonomy',
							params: { type, taxonomy },
							search: ( prev: Record< string, unknown > ) => ( {
								...prev,
								termIds:
									items.length > 0
										? items.map( ( id ) => Number( id ) )
										: undefined,
							} ),
						} );
					} }
					renderItemLink={ ( { item, ...props } ) => (
						<RouterLink
							to="/types/$type/taxonomies/$taxonomy/edit/$termId"
							params={ {
								type,
								taxonomy,
								termId: item.id.toString(),
							} }
							{ ...props }
							onClick={ ( event ) => {
								event.stopPropagation();
								dataViewTracking.listItemClick( 'term_item' );
							} }
						/>
					) }
					tabs={
						// Placeholder tab UI for visual parity; we will add real tab navigation only when needed.
						<Tabs.Root>
							<Tabs.List density="compact">
								<Tabs.Tab value="all">
									{ __( 'All', 'first-admin' ) }
								</Tabs.Tab>
							</Tabs.List>
						</Tabs.Root>
					}
					empty={
						<EmptyState.Root>
							<EmptyState.Icon icon={ tag } />
							<EmptyState.Title>
								{ taxonomyObject.labels.not_found }
							</EmptyState.Title>
							<EmptyState.Description>
								{ sprintf(
									// translators: %s: Taxonomy singular name.
									__(
										'Try adjusting your search or create a new %s.',
										'first-admin'
									),
									taxonomyObject.labels.singular_name.toLocaleLowerCase()
								) }
							</EmptyState.Description>
							<EmptyState.Actions>
								<Button
									variant="outline"
									onClick={ () =>
										void navigate( {
											to: '/types/$type/taxonomies/$taxonomy',
											params: { type, taxonomy },
											search: (
												prev: Record< string, unknown >
											) => ( {
												...prev,
												search: undefined,
											} ),
										} )
									}
								>
									{ __( 'Clear search', 'first-admin' ) }
								</Button>
								<Button
									variant="solid"
									onClick={ () => {
										void navigate( {
											to: '/types/$type/taxonomies/$taxonomy/add',
											params: {
												type,
												taxonomy,
											},
										} );
									} }
								>
									{ labels?.add_button_label ||
										taxonomyObject.labels.add_new_item }
								</Button>
							</EmptyState.Actions>
						</EmptyState.Root>
					}
				/>
			) }
		</Page>
	);
}

export default TaxonomyTerms;
