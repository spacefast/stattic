import { loadView } from '@first-admin/views';
import { loadViewConfig } from '@first-admin/core-data-next';
import type { ViewConfig } from '@first-admin/core-data-next';
import type { View, Filter } from '@first-admin/dataviews';

/**
 * Maps view field names to REST API orderby values.
 * @param field
 */
function getOrderby( field: string ): string {
	switch ( field ) {
		// Special case: map 'user' field to 'email' for ordering.
		case 'user':
			return 'email';
		// Map registration date to 'registered_date'.
		// This is necessary because the REST API uses 'registered_date' for ordering.
		// The 'registration_date' field is a custom field in our dataviews.
		// We need to ensure that the field name matches the REST API expectations.
		case 'registration_date':
			return 'registered_date';
		default:
			return field;
	}
}

function getFilter( view: View, field: string ): Filter | undefined {
	const filters = view?.filters ?? [];
	return filters.find( ( filter: Filter ) => filter.field === field );
}

function isNegated( filter: Filter ): boolean {
	return (
		filter.operator === 'isNot' ||
		filter.operator === 'isNone' ||
		filter.operator === 'notContains'
	);
}

export type RestAPIQueryParams = {
	per_page?: number;
	page?: number;
	search?: string;
	orderby?: string;
	order?: string;
	roles?: string;
	email_search?: string;
	email_search_not?: string;
	name_search?: string;
	name_search_not?: string;
	registered_after?: string;
	registered_before?: string;
};

export function viewToQuery( view: View ): RestAPIQueryParams {
	const result: RestAPIQueryParams = {};

	// Pagination, sorting, search
	if ( view.perPage !== undefined ) {
		result.per_page = view.perPage;
	}

	if ( view.page !== undefined ) {
		result.page = view.page;
	}

	if ( view.search && view.search.trim() !== '' ) {
		result.search = view.search;
	}

	if ( view.sort?.field ) {
		result.orderby = getOrderby( view.sort.field );
	}

	if ( view.sort?.direction ) {
		result.order = view.sort.direction;
	}

	// Handle filters
	const roleFilter = getFilter( view, 'roles' );
	if ( roleFilter?.value ) {
		result.roles = Array.isArray( roleFilter.value )
			? roleFilter.value.join( ',' )
			: String( roleFilter.value );
	}

	const emailFilter = getFilter( view, 'email_badge' );
	if ( emailFilter?.value ) {
		if ( isNegated( emailFilter ) ) {
			result.email_search_not = String( emailFilter.value );
		} else {
			result.email_search = String( emailFilter.value );
		}
	}

	const nameFilter = getFilter( view, 'name' );
	if ( nameFilter?.value ) {
		if ( isNegated( nameFilter ) ) {
			result.name_search_not = String( nameFilter.value );
		} else {
			result.name_search = String( nameFilter.value );
		}
	}

	const dateFilter = getFilter( view, 'registration_date' );
	if ( dateFilter?.value ) {
		switch ( dateFilter.operator ) {
			case 'on':
				result.registered_after = String( dateFilter.value );
				result.registered_before = String( dateFilter.value );
				break;
			case 'before':
				result.registered_before = String( dateFilter.value );
				break;
			case 'after':
				result.registered_after = String( dateFilter.value );
				break;
			case 'between':
				if ( Array.isArray( dateFilter.value ) ) {
					result.registered_after = String( dateFilter.value[ 0 ] );
					result.registered_before = String( dateFilter.value[ 1 ] );
				}
				break;
		}
	}

	return result;
}

const DEFAULT_VIEW: View = {
	type: 'table' as const,
	perPage: 20,
	filters: [],
	titleField: 'username',
	mediaField: 'avatar',
	fields: [ 'name', 'email', 'roles', 'posts' ],
	descriptionField: 'bio',
	showDescription: true,
};

export function getDefaultView(
	slug?: string,
	viewConfig?: ViewConfig | null
): View {
	const targetSlug = slug ?? 'all';
	const baseView =
		viewConfig?.default_view !== undefined &&
		viewConfig?.default_view !== null
			? cloneView( viewConfig.default_view )
			: getFallbackDefaultView( targetSlug );

	const currentTab = viewConfig?.tabs?.find(
		( tab ) => tab.slug === targetSlug
	);

	if ( currentTab?.filters ) {
		const nonRoleFilters = ( baseView.filters ?? [] ).filter(
			( filter: Filter ) => filter.field !== 'roles'
		);

		return {
			...baseView,
			filters: [
				...nonRoleFilters,
				...currentTab.filters.map( ( filter: Filter ) => ( {
					...filter,
					isLocked: true,
				} ) ),
			],
		};
	}

	return baseView;
}

export async function ensureView(
	slug?: string,
	search?: { page?: number; search?: string }
) {
	const viewConfig = await loadViewConfig( 'root', 'user' );
	const defaultView = getDefaultView( slug, viewConfig );
	return loadView( {
		kind: 'root',
		name: 'user',
		defaultView,
		queryParams: search,
	} );
}

function getFallbackDefaultView( slug: string ): View {
	const defaultView: View = {
		...DEFAULT_VIEW,
		filters: DEFAULT_VIEW.filters ? [ ...DEFAULT_VIEW.filters ] : undefined,
	};

	if ( slug !== 'all' ) {
		const existingFilters = defaultView.filters ?? [];
		defaultView.filters = [
			...existingFilters,
			{
				field: 'roles',
				operator: 'is' as const,
				value: slug,
				isLocked: true,
			},
		];
	}

	return defaultView;
}

function cloneView( view: View ): View {
	return {
		...view,
		filters: view.filters
			? view.filters.map( ( filter: Filter ) => ( { ...filter } ) )
			: view.filters,
	};
}
