import { Page, RouterLink, unlock } from '@first-admin/admin-toolkit';
import { useThemeFields } from '@first-admin/fields-next';
import { DataViews, useView } from '@first-admin/views';
import type { View } from '@first-admin/dataviews';
import { filterSortAndPaginate } from '@first-admin/dataviews';
import { useNavigate, useSearch, useInvalidate } from '@wordpress/route';
import { __ } from '@wordpress/i18n';
import type { Theme } from '@wordpress/core-data';
import { privateApis as coreDataPrivateApis } from '@wordpress/core-data';
import {
	useCallback,
	useMemo,
	createInterpolateElement,
} from '@wordpress/element';
import { serializeFilters, usePageView } from '@first-admin/telemetry';
import { Modal } from '@wordpress/components';
import { escapeHTML } from '@wordpress/escape-html';
import activateTheme from './actions/activate-theme';
import deleteTheme from './actions/delete-theme';
import updateTheme from './actions/update-theme';
import useAutoUpdatesTheme from './actions/auto-updates-theme';
import ThemeDetailModal from './theme-detail-modal';
import { getDefaultView } from './view-utils';
import './style.scss';

const { useEntityRecordsWithPermissions } = unlock( coreDataPrivateApis );

// This is the main component for the themes page.
export default function ThemesContent() {
	const navigate = useNavigate();
	const invalidate = useInvalidate();
	const searchParams = useSearch( { from: '/design/themes' } );
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

	const defaultView = useMemo( () => getDefaultView(), [] );
	const { view, isModified, updateView, resetToDefault } = useView( {
		kind: 'root',
		name: 'theme',
		defaultView,
		queryParams: searchParams,
		onChangeQueryParams: handleQueryParamsChange,
	} );

	// Enhanced view change handlers with invalidate invalidation
	const onReset = useCallback( () => {
		resetToDefault();
		invalidate();
	}, [ resetToDefault, invalidate ] );

	const onChangeView = useCallback(
		( newView: View ) => {
			updateView( newView );
			if ( newView.type !== view.type ) {
				// The rendered surfaces depend on the view type,
				// so we need to retrigger the invalidate loader when switching the view type.
				// This is especially important for list view which has different canvas rendering.
				invalidate();
			}
		},
		[ updateView, view.type, invalidate ]
	);

	const { records: allThemes, isResolving } = useEntityRecordsWithPermissions(
		'root',
		'theme',
		// Fetching all of the installed themes as the themes endpoint doesn't support filtering, sorting etc.
		{ context: 'view', per_page: -1 }
	);

	const fields = useThemeFields( allThemes );

	const { data: themes, paginationInfo } = filterSortAndPaginate(
		allThemes || [],
		view,
		fields
	);

	usePageView( isResolving, {
		total_pages: paginationInfo.totalPages,
		list_size: themes.length,
		filter: serializeFilters( view.filters ),
	} );

	const selectedTheme = searchParams.selectedTheme;

	const handleShowDetails = useCallback(
		( themeSlug: string ) => {
			navigate( {
				search: {
					...searchParams,
					selectedTheme: themeSlug,
					details: 1,
				},
			} );
		},
		[ navigate, searchParams ]
	);

	const handleCloseModal = useCallback( () => {
		navigate( {
			search: {
				...searchParams,
				details: undefined,
			},
		} );
	}, [ navigate, searchParams ] );

	const selectedThemeObject = useMemo( () => {
		return (
			allThemes?.find(
				( theme: Theme ) => theme.stylesheet === selectedTheme
			) || null
		);
	}, [ allThemes, selectedTheme ] );

	const actions = [
		activateTheme,
		deleteTheme,
		{
			id: 'view-details',
			label: __( 'Details', 'first-admin' ),
			isPrimary: false,
			callback: ( items: Theme[] ) => {
				if ( items.length > 0 ) {
					handleShowDetails( items[ 0 ].stylesheet );
				}
			},
			isEligible: () => true,
		},
		updateTheme,
		useAutoUpdatesTheme(),
		// TODO: Add the rest of the actions from the spec.
	];

	if ( view.type === 'list' && ! selectedTheme ) {
		navigate( {
			to: '/design/themes',
			search: {
				...searchParams,
				selectedTheme: themes[ 0 ].stylesheet,
			},
		} );
		return;
	}

	// These fields are not viewable, only searchable
	const viewFields = fields.filter(
		( field ) =>
			field.id !== 'author_uri' &&
			field.id !== 'stylesheet' &&
			field.id !== 'template'
	);

	return (
		<Page
			title={ __( 'Themes', 'first-admin' ) }
			className="themes-page"
			hasPadding={ false }
		>
			<DataViews
				data={ themes || [] }
				fields={ viewFields }
				view={ view }
				onChangeView={ onChangeView }
				defaultLayouts={ {
					grid: {
						fields: [],
						showDescription: false,
					},
					table: {
						showDescription: true,
						fields: [ 'author' ],
						layout: {
							density: 'comfortable',
						},
					},
					list: {
						fields: [],
						showDescription: false,
					},
				} }
				actions={ actions }
				getItemId={ ( item: Theme ) => item.stylesheet }
				paginationInfo={ paginationInfo }
				isLoading={ isResolving }
				selection={ selectedTheme ? [ selectedTheme ] : [] }
				onChangeSelection={ ( selection ) => {
					navigate( {
						search: {
							...searchParams,
							selectedTheme:
								selection.length > 0
									? selection[ 0 ]
									: undefined,
						},
					} );
				} }
				renderItemLink={ ( { item, ...props }: { item: Theme } ) => (
					<RouterLink
						to="/design/themes"
						search={ {
							...searchParams,
							selectedTheme: item.stylesheet,
							details: 1,
						} }
						viewTransition={ false }
						{ ...props }
					/>
				) }
				onReset={ isModified ? onReset : false }
			/>
			{ selectedTheme && selectedThemeObject && searchParams.details && (
				<ThemeDetailModal
					theme={ selectedThemeObject }
					onClose={ handleCloseModal }
				/>
			) }
			{ selectedTheme &&
				! selectedThemeObject &&
				searchParams.details && (
					<Modal
						title={ __( 'Theme not found', 'first-admin' ) }
						onRequestClose={ handleCloseModal }
						className="theme-detail-modal"
						size="small"
					>
						<div className="theme-details-error">
							{ createInterpolateElement(
								/* translators: <themeSlug /> will be replaced with the theme slug */
								__(
									'There is no theme <themeSlug /> installed.',
									'first-admin'
								),
								{
									themeSlug: (
										<em>{ escapeHTML( selectedTheme ) }</em>
									),
								}
							) }
						</div>
					</Modal>
				) }
		</Page>
	);
}
