import { registerAbility } from '@wordpress/abilities';
import { select } from '@wordpress/data';
import { store as agentContextStore } from '@zero-admin/ai-agent';
import type { SitemapData } from '@zero-admin/ai-agent';
import { store as zeroAdminStore } from '../store';

/**
 * Minimal router interface for navigation with cache invalidation.
 */
interface Router {
	navigate: ( options: {
		to: string;
		search?: Record< string, unknown >;
	} ) => void;
	invalidate: () => void;
}

// Track if ability has been registered to avoid duplicate registration
let isRegistered = false;

/**
 * Check if a path exists in the sitemap context registered by the agent.
 * Reads the sitemap's menuItems at call time so any URLs added by the root
 * component or plugins are automatically included.
 *
 * @param path The path or URL to check against sitemap menu items
 * @return True if the path matches a sitemap entry
 */
function isInSitemap( path: string ): boolean {
	try {
		const entries = select( agentContextStore ).getContextEntries();
		for ( const entry of entries ) {
			if ( entry.type !== 'sitemap' ) {
				continue;
			}
			// eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- narrowed by entry.type === 'sitemap'
			const data = entry.getData() as SitemapData | undefined;
			if ( data?.menuItems?.some( ( item ) => item.to === path ) ) {
				return true;
			}
		}
	} catch {
		// Store not available yet
	}
	return false;
}

/**
 * Check if a path matches any registered route in the admin SPA.
 * Converts TanStack route patterns (e.g., /types/$type/list) to regexes for matching.
 *
 * @param path The path to check against registered routes
 * @return True if the path matches a registered route
 */
function isKnownRoute( path: string ): boolean {
	try {
		const routes = select( zeroAdminStore ).getRoutes();
		return routes.some( ( route: { path: string } ) => {
			// Replace TanStack $param segments with a regex that matches any path segment
			const pattern = route.path.replace( /\$\w+/g, '[^/]+' );
			return new RegExp( `^${ pattern }$` ).test( path );
		} );
	} catch {
		return false;
	}
}

/**
 * Register the navigation ability with WordPress Abilities API
 *
 * @param router The TanStack Router instance
 */
export function registerNavigationAbility( router: Router ): void {
	// Prevent duplicate registration (e.g., from React Strict Mode)
	if ( isRegistered ) {
		return;
	}

	try {
		void registerAbility( {
			name: 'zero-admin/navigate',
			label: 'Navigate Admin Interface',
			description:
				'Navigate to a different page within the admin interface or to external dashboard pages',
			category: 'zero-admin',
			meta: {
				instructions: `Use this tool to navigate users to different pages in the admin interface.

## Navigation Strategy
- When helping users navigate, prefer using the shortcuts (like "create", "edit") to take them directly to actions rather than navigating to list views first
- Menu items are hierarchical, check any additional context to understand the organization
- Use exact paths from the sitemap to ensure successful navigation
- For pages outside the admin interface (e.g., domain management in the Multi-Site Dashboard), use the full URL from the sitemap
- IMPORTANT: Only navigate to pages listed in the sitemap. Navigation to wp-admin or other unlisted URLs will be rejected.
`,
			},
			input_schema: {
				type: 'object',
				required: [ 'path' ],
				properties: {
					path: {
						type: 'string',
						description:
							'The route path to navigate to (e.g., "/types/post/list", "/") or a full URL for external pages (e.g., "my.wordpress.com/sites/example.com/domains")',
					},
					search: {
						type: 'object',
						description: 'Optional query parameters',
						additionalProperties: true,
					},
				},
			},
			callback: ( input: {
				path: string;
				search?: Record< string, unknown >;
			} ) => {
				const { path, search } = input;

				// For registered SPA routes, use TanStack Router (no page reload)
				if ( isKnownRoute( path ) ) {
					router.navigate( { to: path, search } );
					router.invalidate();
					return {
						success: true,
						navigatedTo: path,
					};
				}

				// For paths listed in the sitemap (e.g., MSD pages), use full page navigation
				if ( isInSitemap( path ) ) {
					window.location.href = path;
					return {
						success: true,
						navigatedTo: path,
					};
				}

				// Block navigation to unknown paths (wp-admin, arbitrary URLs, etc.)
				return {
					success: false,
					error: `Navigation to "${ path }" is not allowed. Use only paths listed in the sitemap.`,
				};
			},
		} );

		// Mark as registered
		isRegistered = true;
	} catch ( error ) {
		// eslint-disable-next-line no-console
		console.error( 'Failed to register navigation ability:', error );
	}
}
