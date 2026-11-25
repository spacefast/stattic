import { useNavigate, useParams, useSearch } from '@wordpress/route';
import { Page, RouterLink, unlock } from '@first-admin/admin-toolkit';
import { useFieldCollections } from '@first-admin/core-data-next';
import { DataViews, useView } from '@first-admin/views';
import { privateApis as coreDataPrivateApis } from '@wordpress/core-data';
import { filterSortAndPaginate } from '@first-admin/dataviews';
import { __ } from '@wordpress/i18n';
import { useCallback, useMemo } from '@wordpress/element';
import { serializeFilters, usePageView } from '@first-admin/telemetry';
import type { Plugin } from '@wordpress/core-data';
import type { View } from '@first-admin/dataviews';
import { usePluginActions } from './actions';
import { PluginTabs } from './plugin-tabs';
import { getDefaultView, viewToQuery } from './view-utils';
import type { PluginTab } from './view-utils';
import './style.scss';
import PluginDetailModal from './components/plugin-detail-modal/plugin-detail-modal';

const { useEntityRecordsWithPermissions } = unlock( coreDataPrivateApis );

export default function PluginList() {
	const { slug = 'all' } = useParams( { from: '/plugins/list/$slug' } );
	const searchParams = useSearch( { from: '/plugins/list/$slug' } );
	const selectedPlugin = searchParams?.selectedPlugin || null;
	const activeTab = slug as PluginTab;
	const navigate = useNavigate();
	const fields = useFieldCollections( 'root', 'plugin' );
	const actions = usePluginActions();

	const handleQueryParamsChange = useCallback(
		( params: { page?: number; search?: string } ) => {
			navigate( {
				// @ts-expect-error - Not entirely sure how to fix this type error.
				search: params,
			} );
		},
		[ navigate ]
	);

	const defaultView = getDefaultView();
	const { view, updateView, isModified, resetToDefault } = useView( {
		kind: 'root',
		name: 'plugin',
		defaultView,
		queryParams: searchParams,
		onChangeQueryParams: handleQueryParamsChange,
	} );
	const pluginQuery = useMemo( () => viewToQuery( slug ), [ slug ] );

	const { records: allPlugins, isResolving } =
		useEntityRecordsWithPermissions( 'root', 'plugin', pluginQuery );

	const { data: plugins, paginationInfo } = filterSortAndPaginate(
		allPlugins || [],
		view,
		fields
	);

	usePageView( isResolving, {
		total_pages: paginationInfo.totalPages,
		list_size: plugins.length,
		tab_name: slug,
		filter: serializeFilters( view.filters ),
	} );

	const onChange = useCallback(
		( newView: View ) => {
			updateView( newView );
		},
		[ updateView ]
	);

	// Handle tab changes - reset to page 1
	const handleTabChange = useCallback(
		( value: PluginTab ) => {
			navigate( {
				to: `/plugins/list/${ value }`,
			} );
		},
		[ navigate ]
	);

	const handleCloseModal = useCallback( () => {
		navigate( {
			search: {
				...searchParams,
				selectedPlugin: undefined,
				details: undefined,
			},
		} );
	}, [ navigate, searchParams ] );

	return (
		<Page
			title={ __( 'Plugins', 'first-admin' ) }
			subTitle={ __( 'Manage and install new plugins.', 'first-admin' ) }
			hasPadding={ false }
		>
			<DataViews
				view={ view }
				actions={ actions }
				onChangeView={ onChange }
				fields={ fields }
				data={ plugins }
				onReset={ isModified ? resetToDefault : false }
				isLoading={ isResolving }
				paginationInfo={ paginationInfo }
				defaultLayouts={ {
					table: {
						fields: defaultView.fields,
						layout: {
							density: 'balanced',
						},
						descriptionField: 'description',
						showDescription: true,
					},
				} }
				getItemId={ ( item: Plugin ) => item.plugin }
				renderItemLink={ ( { item, ...props }: { item: Plugin } ) => (
					<RouterLink
						to={ '/plugins/list/' + slug }
						search={ {
							...searchParams,
							selectedPlugin: item.plugin.split( '/' )[ 0 ],
							details: 1,
						} }
						viewTransition={ false }
						{ ...props }
					/>
				) }
				tabs={
					<PluginTabs
						activeTab={ activeTab }
						onChange={ handleTabChange }
					/>
				}
			/>
			{ selectedPlugin && searchParams.details && (
				<PluginDetailModal
					slug={ selectedPlugin }
					onClose={ handleCloseModal }
				/>
			) }
		</Page>
	);
}
