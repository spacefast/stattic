import type { View } from '@first-admin/dataviews';
/**
 * Removes tab-specific filters from a view before persisting preferences.
 *
 * Tab-specific filters are defined in the default view for the current tab and should
 * not be persisted, as they will be dynamically re-applied when loading the view
 * based on the active tab. This function identifies filters in the view that match
 * fields present in the default view's filters and removes them.
 *
 * This is the inverse operation of `mergeViewFilters`, which adds tab-specific filters
 * when loading a view. Together, they ensure that tab-specific filters are never
 * persisted but are always applied based on the current tab context.
 *
 * @param view        The view from which to remove tab-specific filters
 * @param defaultView The default view containing tab-specific filters to identify
 * @return A new view with tab-specific filters removed
 */
export function stripTabFilters( view: View, defaultView: View ): View {
	const tabFilterFields = new Set(
		( defaultView.filters ?? [] ).map( ( filter ) => filter.field )
	);

	return {
		...view,
		filters: ( view.filters ?? [] ).filter(
			( filter ) => ! tabFilterFields.has( filter.field )
		),
	};
}
