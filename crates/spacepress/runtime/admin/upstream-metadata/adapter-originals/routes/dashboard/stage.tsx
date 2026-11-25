/**
 * External dependencies
 */
import { Dashboard, useWidgetTypes } from '@spacepress-admin/dashboard';
import { Page } from '@spacepress-admin/admin-toolkit';
import {
	store as spAdminStore,
	type StoreSelectors,
	type EntityDependency,
} from '@spacepress-admin/boot';
import { store as coreDataStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { useState, useCallback, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	recordButtonClick,
	recordTracksEvent,
	usePageView,
	useDashboardTracking,
} from '@spacepress-admin/telemetry';
import type { DashboardWidget } from '@spacepress-admin/dashboard';
import { MoreActionsDropdown, RestoreDefaultsModal } from './components';
import { useDashboardLayout } from './hooks';

function isRecord( value: unknown ): value is Record< string, unknown > {
	return typeof value === 'object' && value !== null;
}

/**
 * Detect widget attribute changes between two layouts and fire
 * tracking events for date range and metrics filter changes.
 * @param oldLayout
 * @param newLayout
 */
function trackWidgetAttributeChanges(
	oldLayout: DashboardWidget< unknown >[],
	newLayout: DashboardWidget< unknown >[]
) {
	for ( const newWidget of newLayout ) {
		const oldWidget = oldLayout.find( ( w ) => w.uid === newWidget.uid );
		if ( ! oldWidget || oldWidget.attributes === newWidget.attributes ) {
			continue;
		}

		const prev = isRecord( oldWidget.attributes )
			? oldWidget.attributes
			: undefined;
		const next = isRecord( newWidget.attributes )
			? newWidget.attributes
			: undefined;

		// Track date range changes
		if ( prev?.reportParams !== next?.reportParams ) {
			const prevParams = isRecord( prev?.reportParams )
				? prev.reportParams
				: undefined;
			const nextParams = isRecord( next?.reportParams )
				? next.reportParams
				: undefined;
			const nextPreset =
				typeof nextParams?.preset === 'string'
					? nextParams.preset
					: 'custom';
			const prevPreset =
				typeof prevParams?.preset === 'string'
					? prevParams.preset
					: 'custom';

			recordTracksEvent( 'date_range_apply', {
				widget_name: newWidget.type,
				filter: 'date_range',
				selected_value: nextPreset,
				previous_value: prevPreset,
			} );
		}

		// Track metrics changes
		if ( prev?.metrics !== next?.metrics ) {
			const getEnabledIds = ( metrics: unknown ) =>
				( Array.isArray( metrics ) ? metrics : [] )
					.filter(
						( m: unknown ) => isRecord( m ) && m.enabled === true
					)
					.map( ( m: unknown ) =>
						isRecord( m ) ? String( m.id ) : ''
					)
					.join( ',' );

			recordTracksEvent( 'widget_filter_apply', {
				widget_name: newWidget.type,
				filter: 'metrics',
				selected_value: getEnabledIds( next?.metrics ),
				previous_value: getEnabledIds( prev?.metrics ),
			} );
		}
	}
}

export default function DashboardRoute() {
	const [ layout, onChange, resetLayout ] = useDashboardLayout();

	const registeredWidgetTypes = useSelect(
		( select ) => select( spAdminStore ).getDashboardWidgetTypes(),
		[]
	);

	const widgetTypes = useWidgetTypes( registeredWidgetTypes );

	// Fire page_view once when widget types finish resolving.
	const isLoading = ! widgetTypes.length;
	const widgetTypeNames = new Set( widgetTypes.map( ( wt ) => wt.name ) );
	const activeWidgets = layout
		.filter( ( w ) => ! w.__meta?.deleted && widgetTypeNames.has( w.type ) )
		.map( ( w ) => w.type );
	usePageView( isLoading, { widget_list: JSON.stringify( activeWidgets ) } );

	// Subscribe to dashboard lifecycle actions (widget views + layout commits).
	const { trackLayoutChange } = useDashboardTracking( layout );

	const [ isRestoreModalOpen, setIsRestoreModalOpen ] = useState( false );

	// Reset key to force Dashboard re-mount when restoring defaults.
	// Todo: Address this upstream, in the Grid package/component.
	const [ resetKey, setResetKey ] = useState( 0 );

	const handleRestoreDefaults = useCallback( () => {
		recordButtonClick( 'restore_defaults' );
		resetLayout();
		setResetKey( ( k ) => k + 1 );
		setIsRestoreModalOpen( false );
	}, [ resetLayout ] );

	// Get dashboard notices from store (original configs with handlers)
	const noticeConfigs = useSelect(
		( select ) =>
			(
				select( spAdminStore ) as StoreSelectors
			 ).getDashboardNotices(),
		[]
	);

	// Extract all unique data dependencies from notices
	const dataDependencies = useMemo( () => {
		const deps = new Set< string >();
		noticeConfigs.forEach( ( notice ) => {
			notice.dataDependencies?.forEach( ( dep ) => {
				deps.add( JSON.stringify( dep ) );
			} );
		} );
		return Array.from( deps ).map( ( d ) => {
			// Parse JSON string back to dependency object
			// eslint-disable-next-line @typescript-eslint/no-unsafe-assignment
			const parsed = JSON.parse( d );
			// We trust this is valid since we stringified it ourselves above
			// eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- JSON.parse returns `any`, but we control the input
			return parsed as EntityDependency;
		} );
	}, [ noticeConfigs ] );

	// Create stable dependency key for memoization
	const depsKey = useMemo(
		() => JSON.stringify( dataDependencies ),
		[ dataDependencies ]
	);
	// Subscribe to all data dependencies in a single hook call
	useSelect(
		( select ) => {
			return dataDependencies.map( ( { kind, name, id } ) =>
				select( coreDataStore ).getEntityRecord( kind, name, id )
			);
		},
		// eslint-disable-next-line react-hooks/exhaustive-deps -- we use the memoized key instead
		[ depsKey ]
	);

	if ( ! widgetTypes.length ) {
		return null;
	}

	// Filter and sort notices, converting to display format
	const visibleNotices = noticeConfigs
		.filter( ( notice ) => ! notice.shouldShow || notice.shouldShow() )
		.sort( ( a, b ) => ( b.priority || 0 ) - ( a.priority || 0 ) )
		.map( ( notice ) => ( {
			id: notice.id,
			status: notice.status,
			message: notice.message,
			actions: notice.actions?.map( ( action ) => ( {
				id: action.id,
				label: action.label,
			} ) ),
		} ) );

	// Handle notice action clicks
	const handleNoticeAction = ( noticeId: string, actionId: string ) => {
		const noticeConfig = noticeConfigs.find( ( n ) => n.id === noticeId );
		if ( ! noticeConfig ) {
			return;
		}

		const action = noticeConfig.actions?.find( ( a ) => a.id === actionId );

		if ( action?.onClick ) {
			action.onClick();
		}
	};

	return (
		<Dashboard
			key={ resetKey }
			layout={ layout }
			onChange={ ( newLayout, context ) => {
				// eslint-disable-next-line @typescript-eslint/no-unsafe-call -- @spacepress-admin/telemetry is untyped cross-plugin
				trackLayoutChange( newLayout, context );
				trackWidgetAttributeChanges( layout, newLayout );
				onChange( newLayout );
			} }
			widgetTypes={ widgetTypes }
			notices={ visibleNotices }
			onNoticeAction={ handleNoticeAction }
		>
			<Page
				title={ __( 'Dashboard', 'spacepress-admin' ) }
				actions={
					<>
						<Dashboard.Actions />
						<MoreActionsDropdown
							items={ [
								{
									label: __(
										'Restore default widgets',
										'spacepress-admin'
									),
									onClick: () =>
										setIsRestoreModalOpen( true ),
								},
							] }
						/>
					</>
				}
				hasBorder={ true }
			>
				<Dashboard.Notices />
				<Dashboard.Widgets />

				<RestoreDefaultsModal
					isOpen={ isRestoreModalOpen }
					onClose={ () => setIsRestoreModalOpen( false ) }
					onConfirm={ handleRestoreDefaults }
				/>
			</Page>
		</Dashboard>
	);
}
