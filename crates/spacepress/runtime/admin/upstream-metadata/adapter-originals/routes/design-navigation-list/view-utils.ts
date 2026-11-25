import { loadView } from '@first-admin/views';
import type { View } from '@first-admin/dataviews';

const NAVIGATION_POST_TYPE = 'wp_navigation';

const DEFAULT_VIEW: View = {
	type: 'list' as const,
	sort: {
		field: 'date',
		direction: 'desc' as const,
	},
	titleField: 'title',
};

export function getDefaultView(): View {
	return DEFAULT_VIEW;
}

export async function ensureView( search?: {
	page?: number;
	search?: string;
} ) {
	const defaultView = getDefaultView();
	return loadView( {
		kind: 'postType',
		name: NAVIGATION_POST_TYPE,
		defaultView,
		queryParams: search,
	} );
}
