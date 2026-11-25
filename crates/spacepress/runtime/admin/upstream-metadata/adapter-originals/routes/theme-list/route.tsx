import { loadView } from '@first-admin/views';
import { preloadFieldCollections } from '@first-admin/core-data-next';
import { store as coreStore } from '@wordpress/core-data';
import { resolveSelect } from '@wordpress/data';
import { getDefaultView } from './view-utils';

const resolveThemes = async () => {
	// Fetching all of the installed themes as the themes endpoint doesn't support filtering, sorting etc.
	return resolveSelect( coreStore ).getEntityRecords( 'root', 'theme', {
		context: 'view',
		per_page: -1,
	} );
};

export const route = {
	loader: async () => {
		await Promise.all( [
			preloadFieldCollections( 'root', 'theme' ),
			resolveThemes(),
			// @ts-expect-error - getSite() types incorrectly require arguments
			resolveSelect( coreStore ).getSite(),
		] );
	},

	canvas: async ( {
		search,
	}: {
		search?: { selectedTheme?: string; page?: number; search?: string };
	} ) => {
		const defaultView = getDefaultView();
		const view = await loadView( {
			kind: 'root',
			name: 'theme',
			defaultView,
			queryParams: search
				? { page: search.page, search: search.search }
				: undefined,
		} );

		if ( view.type === 'list' ) {
			if ( ! search?.selectedTheme ) {
				return {
					isPreview: true,
				};
			}

			// @ts-expect-error - getSite() types incorrectly require arguments
			const site = await resolveSelect( coreStore ).getSite();
			const siteUrl = site?.url || window.location.origin;

			if ( ! siteUrl ) {
				return;
			}

			const url = new URL( siteUrl );
			url.searchParams.set( 'wp_theme_preview', search?.selectedTheme );

			return {
				url: url.toString(),
				isPreview: true,
			};
		}
	},
};
