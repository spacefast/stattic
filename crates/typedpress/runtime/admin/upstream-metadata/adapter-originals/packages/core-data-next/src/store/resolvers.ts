// The exception is ok now, because this package is meant to merge with core-data.
// eslint-disable-next-line no-restricted-imports
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

/**
 * Private resolver for fetching field collections from the REST API.
 * This automatically triggers when getEntityFieldCollections is called.
 *
 * @param kind Entity kind (e.g., 'postType', 'taxonomy').
 * @param name Entity name (e.g., 'post', 'category').
 * @return Promise resolving when the data is fetched.
 */
export const getEntityFieldCollections =
	( kind: string, name: string ) =>
	async ( { dispatch }: { dispatch: any } ) => {
		try {
			const path = addQueryArgs( '/wp/v2/field-collections', {
				kind,
				name,
			} );

			const response = await apiFetch( {
				path,
			} );

			dispatch.receiveEntityFieldCollections( kind, name, response );
			// eslint-disable-next-line @typescript-eslint/no-unused-vars
		} catch ( error ) {
			// For now, just dispatch empty collections on error
		}
	};

/**
 * Resolver for fetching editor settings from the REST API.
 * This automatically triggers when getEditorSettings is called.
 *
 * @return Promise resolving when the data is fetched.
 */
export const getEditorSettings =
	() =>
	async ( { dispatch }: { dispatch: any } ) => {
		const response = await apiFetch( {
			path: '/wp/v2/editor/settings',
		} );

		dispatch.receiveEditorSettings( response );
	};

/**
 * Resolver for fetching editor assets from the REST API.
 * This automatically triggers when getEditorAssets is called.
 *
 * @return Promise resolving when the data is fetched.
 */
export const getEditorAssets =
	() =>
	async ( { dispatch }: { dispatch: any } ) => {
		const response = await apiFetch( {
			path: '/wp/v2/editor/assets',
		} );

		dispatch.receiveEditorAssets( response );
	};

/**
 * Private resolver for fetching view config from the REST API.
 * This automatically triggers when getEntityViewConfig is called.
 *
 * @param kind    Entity kind (e.g., 'root', 'postType', 'taxonomy').
 * @param name    Entity name (e.g., 'user', 'post', 'category').
 * @param variant Optional. View config identifier.
 * @return Promise resolving when the data is fetched.
 */
export const getEntityViewConfig =
	( kind: string, name: string, variant?: string | null ) =>
	async ( { dispatch }: { dispatch: any } ) => {
		try {
			const queryArgs: Record< string, string > = {
				kind,
				name,
			};
			if ( variant ) {
				queryArgs.variant = variant;
			}

			const path = addQueryArgs( '/wp/v2/view-config', queryArgs );

			const response = await apiFetch( {
				path,
			} );

			dispatch.receiveEntityViewConfig( kind, name, response, variant );
			// eslint-disable-next-line @typescript-eslint/no-unused-vars
		} catch ( error ) {
			// For now, just dispatch null config on error
			dispatch.receiveEntityViewConfig( kind, name, null, variant );
		}
	};
