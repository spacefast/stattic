import { loadView } from '@first-admin/views';
import { resolveSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import type { View } from '@first-admin/dataviews';
import type { Type } from '@wordpress/core-data';

const DEFAULT_VIEW: View = {
	type: 'table' as const,
	sort: {
		field: 'date',
		direction: 'desc' as const,
	},
	fields: [ 'title', 'author', 'status', 'date' ],
};

export function getDefaultView(
	postType: Type | undefined,
	slug?: string
): View {
	if ( postType?.views && slug ) {
		const viewItem = postType.views.find( ( view ) => view.slug === slug );
		if ( viewItem?.view ) {
			return viewItem.view;
		}
	}

	const defaultView = {
		...( postType?.default_view ?? DEFAULT_VIEW ),
		showLevels: postType?.hierarchical,
	};

	return defaultView;
}

export async function ensureView(
	type: string,
	slug?: string,
	search?: { page?: number; search?: string }
) {
	const postTypeObject = await resolveSelect( coreStore ).getPostType( type );
	const defaultView = getDefaultView( postTypeObject, slug );
	return loadView( {
		kind: 'postType',
		name: type,
		defaultView,
		queryParams: search,
	} );
}
