import { dequal } from 'dequal';
import { useCallback, useMemo } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import type { View } from '@first-admin/dataviews';
import { store as preferencesStore } from '@wordpress/preferences';
import { generatePreferenceKey } from './preference-keys';
import type { ViewConfig } from './types';
import { isView } from './is-view';
import { mergeViewFilters } from './merge-view-filters';
import { stripTabFilters } from './strip-tab-filters';

interface UseViewReturn {
	view: View;
	isModified: boolean;
	updateView: ( newView: View ) => void;
	resetToDefault: () => void;
}

function omit< T extends object, K extends keyof T >(
	obj: T,
	keys: K[]
): Omit< T, K > {
	const result = { ...obj };
	for ( const key of keys ) {
		delete result[ key ];
	}
	return result;
}

/**
 * Hook for managing DataViews view state with local persistence.
 *
 * @param config Configuration object with entity, optional variant, and defaultView
 * @return Object with current view, modification state, and update functions
 */
export function useView( config: ViewConfig ): UseViewReturn {
	const {
		kind,
		name,
		variant,
		defaultView,
		queryParams,
		onChangeQueryParams,
	} = config;

	const preferenceKey = generatePreferenceKey( kind, name, variant );
	const persistedView = useSelect(
		( select ) => {
			const view: unknown = select( preferencesStore ).get(
				'core/views',
				preferenceKey
			);
			return isView( view ) ? view : undefined;
		},
		[ preferenceKey ]
	);
	const { set } = useDispatch( preferencesStore );

	const baseView: View = persistedView ?? defaultView;
	const page = queryParams?.page ?? baseView.page ?? 1;
	const search = queryParams?.search ?? baseView.search ?? '';

	const view: View = useMemo( () => {
		const viewWithMergedFilters = mergeViewFilters( baseView, defaultView );
		return {
			...viewWithMergedFilters,
			page,
			search,
		};
	}, [ baseView, defaultView, page, search ] );

	const isModified = !! persistedView;

	const updateView = useCallback(
		( newView: View ) => {
			// Extract URL params (page, search) from the new view
			const urlParams: { page?: number; search?: string } = {
				page: newView?.page,
				search: newView?.search,
			};

			const preferenceView = omit(
				stripTabFilters( newView, defaultView ),
				[ 'page', 'search' ]
			);

			const defaultPreferenceView = omit(
				stripTabFilters( defaultView, defaultView ),
				[ 'page', 'search' ]
			);

			// If we have URL handling enabled, separate URL state from preference state
			if (
				onChangeQueryParams &&
				! dequal( urlParams, { page, search } )
			) {
				onChangeQueryParams( urlParams );
			}

			// Clear preferences when returning to defaults, even if baseView matches.
			if ( dequal( preferenceView, defaultPreferenceView ) ) {
				set( 'core/views', preferenceKey, undefined );
				return;
			}

			const basePreferenceView = omit(
				stripTabFilters( baseView, defaultView ),
				[ 'page', 'search' ]
			);

			// Only persist non-URL preferences if different from baseView
			if ( ! dequal( basePreferenceView, preferenceView ) ) {
				set( 'core/views', preferenceKey, preferenceView );
			}
		},
		[
			onChangeQueryParams,
			page,
			search,
			baseView,
			defaultView,
			set,
			preferenceKey,
		]
	);

	const resetToDefault = useCallback( () => {
		set( 'core/views', preferenceKey, undefined );
	}, [ preferenceKey, set ] );

	return {
		view,
		isModified,
		updateView,
		resetToDefault,
	};
}
