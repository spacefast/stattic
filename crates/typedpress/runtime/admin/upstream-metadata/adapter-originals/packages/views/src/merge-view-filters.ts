import type { View } from '@first-admin/dataviews';

/**
 * Merges filters from a base view with tab-specific filters from a default view.
 * Tab-specific filters take precedence and override any conflicting filters in the base view.
 *
 * @param baseView    The base view (persisted or default) containing filters to merge
 * @param defaultView The default view containing tab-specific filters
 * @return A new view with merged filters
 */
export function mergeViewFilters( baseView: View, defaultView: View ): View {
	const baseFilters = baseView.filters ?? [];
	const tabFilters = defaultView.filters ?? [];
	const tabFilterFields = new Set(
		tabFilters.map( ( filter ) => filter.field )
	);

	// Drop persisted filters that target the same fields enforced by the active tab.
	const filtersWithoutTabFields = baseFilters.filter(
		( filter ) => ! tabFilterFields.has( filter.field )
	);

	return {
		...baseView,
		filters: [
			...filtersWithoutTabFields,
			...tabFilters.map( ( filter ) => ( { ...filter } ) ),
		],
	};
}
