import { select } from '@wordpress/data';
import { store as preferencesStore } from '@wordpress/preferences';
import { generatePreferenceKey } from './preference-keys';
import type { ViewConfig } from './types';
import { isView } from './is-view';
import { mergeViewFilters } from './merge-view-filters';

export function loadView( config: ViewConfig ) {
	const { kind, name, variant, defaultView, queryParams } = config;
	const preferenceKey = generatePreferenceKey( kind, name, variant );
	const view: unknown = select( preferencesStore ).get(
		'core/views',
		preferenceKey
	);
	const persistedView = isView( view ) ? view : undefined;

	const baseView = persistedView ?? defaultView;
	const page = queryParams?.page ?? 1;
	const search = queryParams?.search ?? '';

	// Merge filters: tab-specific filters from defaultView override conflicting filters in baseView
	const viewWithMergedFilters = mergeViewFilters( baseView, defaultView );

	return {
		...viewWithMergedFilters,
		page,
		search,
	};
}
