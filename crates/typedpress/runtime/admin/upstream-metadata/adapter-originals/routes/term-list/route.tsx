import { notFound } from '@wordpress/route';
import { loadView } from '@first-admin/views';
import {
	loadViewConfig,
	preloadFieldCollections,
} from '@first-admin/core-data-next';
import { resolveSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { getDefaultView, viewToQuery } from './view-utils';

export const route = {
	beforeLoad: async ( {
		params,
	}: {
		params: { type: string; taxonomy: string };
	} ) => {
		try {
			const [ , taxonomy ] = await Promise.all( [
				resolveSelect( coreStore ).getPostType( params.type ),
				resolveSelect( coreStore ).getTaxonomy( params.taxonomy ),
			] );

			if ( ! taxonomy?.types?.includes( params.type ) ) {
				throw notFound();
			}
		} catch {
			throw notFound();
		}
	},
	loader: async ( {
		params,
		search,
	}: {
		params: { type: string; taxonomy: string };
		search: { page?: number; search?: string };
	} ) => {
		const [ taxonomyObject, viewConfig ] = await Promise.all( [
			resolveSelect( coreStore ).getTaxonomy( params.taxonomy ),
			loadViewConfig( 'taxonomy', params.taxonomy ),
		] );
		const defaultView = getDefaultView( taxonomyObject, viewConfig );
		const view = await loadView( {
			kind: 'taxonomy',
			name: params.taxonomy,
			defaultView,
			queryParams: search,
		} );

		await Promise.all( [
			preloadFieldCollections( 'postType', params.taxonomy ),
			resolveSelect( coreStore ).getEntityRecords(
				'taxonomy',
				params.taxonomy,
				viewToQuery( view )
			),
			resolveSelect( coreStore ).canUser( 'create', {
				kind: 'taxonomy',
				name: params.taxonomy,
			} ),
		] );
	},
};
