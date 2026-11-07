import { resolveSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { parse } from '@wordpress/block-serialization-default-parser';
import { loadEditorAssets } from '@zero-admin/lazy-editor';
import { unlock } from '@zero-admin/admin-toolkit';
import type { RouteCanvas } from '../../store/types';
import {
	fetchResolvedTemplate,
	injectResolvedTemplate,
} from './resolved-template';

// packages/block-serialization-default-parser/src/index.ts
type ParsedBlock = {
	blockName: string | null;
	attrs: Record< string, unknown > | null;
	innerBlocks: ParsedBlock[];
	innerHTML: string;
	innerContent: Array< string | null >;
};

function flattenBlocks( blocks: ParsedBlock[] ): ParsedBlock[] {
	const result: ParsedBlock[] = [];

	blocks.forEach( ( block ) => {
		result.push( block );
		if ( block.innerBlocks ) {
			result.push( ...flattenBlocks( block.innerBlocks ) );
		}
	} );

	return result;
}

function getPostContentRaw( post: unknown ): string | undefined {
	if ( post && typeof post === 'object' && 'content' in post ) {
		const { content } = post;
		if ( content && typeof content === 'object' && 'raw' in content ) {
			return typeof content.raw === 'string' ? content.raw : undefined;
		}
	}
	return undefined;
}

function getPostStylesId( post: unknown ): string | undefined {
	if ( post && typeof post === 'object' && 'styles_post_id' in post ) {
		return typeof post.styles_post_id === 'string'
			? post.styles_post_id
			: undefined;
	}
	return undefined;
}

async function preloadBlockContent( content?: string ) {
	if ( ! content ) {
		return;
	}

	const promises: Promise< unknown >[] = [];
	const blocks = parse( content );
	for ( const block of flattenBlocks( blocks ) ) {
		if ( block.blockName === 'core/template-part' && block.attrs ) {
			const theme = block.attrs.theme;
			const slug = block.attrs.slug;
			if ( typeof theme === 'string' && typeof slug === 'string' ) {
				promises.push(
					resolveSelect( coreStore )
						.getEntityRecord(
							'postType',
							'wp_template_part',
							`${ theme }//${ slug }`
						)
						.then( ( post ) =>
							preloadBlockContent( getPostContentRaw( post ) )
						)
				);
			}
		} else if ( block.blockName === 'core/navigation' ) {
			promises.push(
				resolveSelect( coreStore ).getEntityRecords( 'root', 'menu', {
					per_page: -1,
					context: 'view',
				} )
			);
			promises.push(
				resolveSelect( coreStore ).getEntityRecords(
					'postType',
					'page',
					{
						parent: 0,
						order: 'asc',
						orderby: 'id',
						per_page: -1,
						context: 'view',
					}
				)
			);
			// https://github.com/WordPress/gutenberg/blob/94af11b45d80ceb0a43cc82de6efed4aa71ccfc0/packages/block-library/src/navigation/use-navigation-menu.js#L47
			promises.push(
				resolveSelect( coreStore ).getEntityRecords(
					'postType',
					'wp_navigation',
					{
						per_page: 100,
						status: [ 'publish', 'draft' ],
						order: 'desc',
						orderby: 'date',
					}
				)
			);
			const ref = block.attrs?.ref;
			if ( typeof ref === 'string' || typeof ref === 'number' ) {
				promises.push(
					resolveSelect( coreStore )
						.getEntityRecord( 'postType', 'wp_navigation', ref, {
							context: 'edit',
						} )
						.then( ( post ) =>
							preloadBlockContent( getPostContentRaw( post ) )
						)
				);
			} else {
				/* eslint-disable @typescript-eslint/no-unsafe-call, @typescript-eslint/no-unsafe-member-access, @typescript-eslint/no-unsafe-argument */
				promises.push(
					unlock( resolveSelect( coreStore ) )
						.getNavigationFallbackId()
						.then( ( id?: string ) =>
							resolveSelect( coreStore )
								.getEntityRecord(
									'postType',
									'wp_navigation',
									id,
									{ context: 'edit' }
								)
								.then( ( post: unknown ) =>
									preloadBlockContent(
										getPostContentRaw( post )
									)
								)
						)
				);
				/* eslint-enable @typescript-eslint/no-unsafe-call, @typescript-eslint/no-unsafe-member-access, @typescript-eslint/no-unsafe-argument */
			}
		} else if ( block.blockName === 'core/page-list' ) {
			// https://github.com/WordPress/gutenberg/blob/94af11b45d80ceb0a43cc82de6efed4aa71ccfc0/packages/block-library/src/page-list/edit.js#L130
			promises.push(
				resolveSelect( coreStore ).getEntityRecords(
					'postType',
					'page',
					{
						per_page: 100,
						_fields: [
							'id',
							'link',
							'menu_order',
							'parent',
							'title',
							'type',
						],
						orderby: 'menu_order',
						order: 'asc',
					}
				)
			);
		}
	}

	return await Promise.all( promises );
}

async function preloadStylesPost( stylesPostId?: string ) {
	if ( ! stylesPostId ) {
		return;
	}

	return Promise.allSettled( [
		resolveSelect( coreStore ).canUser( 'update', {
			kind: 'root',
			name: 'globalStyles',
			id: stylesPostId,
		} ),
		resolveSelect( coreStore ).getEntityRecord(
			'root',
			'globalStyles',
			stylesPostId
		),
	] );
}

/**
 * Resolves the posts to preload for template-based canvas routes.
 *
 * For FrontPageCanvas (no postId): resolves theme, permissions, and front
 * page ID in parallel before looking up the template.
 *
 * For PostCanvas (has postId): resolves the theme check and template lookup.
 *
 * @param routeCanvas The canvas to resolve.
 */
async function resolveTemplatePosts( routeCanvas: RouteCanvas ) {
	if (
		! routeCanvas ||
		'url' in routeCanvas ||
		'getTemplate' in routeCanvas
	) {
		return null;
	}

	let _postType: string | undefined;
	let _postId: string | undefined;

	const postsToPreload: { postType: string; postId: string }[] = [];

	if ( ! ( 'postId' in routeCanvas ) ) {
		// FrontPageCanvas — run all three independent gates in parallel.
		/* eslint-disable @typescript-eslint/no-unsafe-assignment, @typescript-eslint/no-unsafe-member-access */
		const [ theme, canManageSite, defaultTemplateId ] = await Promise.all( [
			resolveSelect( coreStore ).getCurrentTheme(),
			resolveSelect( coreStore ).canUser( 'update', {
				kind: 'root',
				name: 'site',
			} ),
			resolveSelect( coreStore ).getDefaultTemplateId( {
				slug: 'front-page',
			} ),
		] );

		if ( ! theme.is_block_theme || ! canManageSite ) {
			return null;
		}
		/* eslint-enable @typescript-eslint/no-unsafe-assignment, @typescript-eslint/no-unsafe-member-access */

		if ( defaultTemplateId ) {
			postsToPreload.push( {
				postType: 'wp_template',
				postId: defaultTemplateId,
			} );
		}
	} else {
		// PostCanvas — only the theme check is needed as a gate.
		/* eslint-disable @typescript-eslint/no-unsafe-assignment, @typescript-eslint/no-unsafe-member-access */
		const theme = await resolveSelect( coreStore ).getCurrentTheme();
		if ( ! theme.is_block_theme ) {
			return null;
		}
		/* eslint-enable @typescript-eslint/no-unsafe-assignment, @typescript-eslint/no-unsafe-member-access */

		_postType = routeCanvas.postType;
		_postId = routeCanvas.postId;
	}

	if ( _postType && _postId ) {
		postsToPreload.push( { postType: _postType, postId: _postId } );
	}

	return postsToPreload;
}

/**
 * Given a route's canvas object, resolves and preloads all the necessary
 * data to render the correpsonding editor component for that route.
 *
 * For template-based canvases (no getTemplate), tries the server-side
 * resolved-template endpoint first to eliminate the client-side waterfall.
 * Falls back to the existing waterfall on failure.
 *
 * @param routeCanvas The canvas to resolve.
 * @return The resolved canvas with all the necessary data preloaded.
 */
export async function canvasLoader(
	routeCanvas: RouteCanvas
): Promise< void > {
	if ( ! routeCanvas ) {
		return;
	}

	if ( 'url' in routeCanvas ) {
		return;
	}

	// For template-based canvases, try server-side resolution first.
	if ( ! ( 'getTemplate' in routeCanvas ) ) {
		try {
			const postType =
				'postId' in routeCanvas ? routeCanvas.postType : undefined;
			const postId =
				'postId' in routeCanvas ? routeCanvas.postId : undefined;

			const response = await fetchResolvedTemplate( postType, postId );
			await injectResolvedTemplate( response );

			// Template waterfall eliminated. Run remaining parallel work only.
			// Note: canUser is a preload for the editor, not a gate.
			// The waterfall path gates FrontPageCanvas on manage_options,
			// but that check can't run here (data is already injected).
			// The server endpoint requires edit_posts, and all CIAB
			// admin users have manage_options, so this is safe.
			await Promise.all( [
				loadEditorAssets(),
				resolveSelect( coreStore ).canUser( 'update', {
					kind: 'root',
					name: 'site',
				} ),
				resolveSelect( coreStore )
					.__experimentalGetCurrentGlobalStylesId()
					.then( ( id: string ) => preloadStylesPost( id ) ),
				resolveSelect(
					coreStore
				).__experimentalGetCurrentThemeBaseGlobalStyles(),
			] );
			return;
		} catch {
			// Endpoint failed — fall through to existing waterfall.
		}
	}

	// Existing waterfall (fallback + handles getTemplate routes).
	let postsToPreload: { postType: string; postId: string }[];

	if ( ! ( 'getTemplate' in routeCanvas ) ) {
		const result = await resolveTemplatePosts( routeCanvas );
		if ( result === null ) {
			return; // Gates failed (non-block theme or insufficient permissions).
		}
		postsToPreload = result;
	} else if ( routeCanvas.postType && routeCanvas.postId ) {
		postsToPreload = [
			{
				postType: routeCanvas.postType,
				postId: routeCanvas.postId,
			},
		];
	} else {
		postsToPreload = [];
	}

	await Promise.allSettled( [
		loadEditorAssets(),
		...postsToPreload.map( async ( { postType, postId } ) =>
			resolveSelect( coreStore )
				.getEntityRecord( 'postType', postType, postId )
				.then( ( post ) => {
					return Promise.all( [
						preloadBlockContent( getPostContentRaw( post ) ),
						preloadStylesPost( getPostStylesId( post ) ),
					] );
				} )
		),
		resolveSelect( coreStore ).canUser( 'update', {
			kind: 'root',
			name: 'site',
		} ),
		resolveSelect( coreStore )
			.__experimentalGetCurrentGlobalStylesId()
			.then( ( id: string ) => preloadStylesPost( id ) ),
		resolveSelect(
			coreStore
		).__experimentalGetCurrentThemeBaseGlobalStyles(),
	] );
}
