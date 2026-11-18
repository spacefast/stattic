/* eslint-disable @typescript-eslint/no-unsafe-assignment, @typescript-eslint/no-unsafe-call, @typescript-eslint/no-unsafe-return, @typescript-eslint/no-unsafe-member-access -- TanStack Router private APIs via unlock() are untyped */
import {
	type AnyRoute,
	privateApis as routePrivateApis,
} from '@wordpress/route';
import { useContext } from 'react';
// eslint-disable-next-line @zero-admin/design-system/use-new-components -- See: ARC-1359
import { Stack } from '@zero-admin/design-system';
import { ThemeProvider } from '@zero-admin/theme';
import { Page, useColorPreference, unlock } from '@zero-admin/admin-toolkit';
import clsx from 'clsx';
import { __ } from '@wordpress/i18n';
const {
	createRootRoute,
	createRoute,
	createRouter,
	createLazyRoute,
	createBrowserHistory,
	useLoaderData,
	parseHref,
} = unlock( routePrivateApis );
import { resolveSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import type { RouteCanvas, Route } from '../../store/types';
import Root, { OutletContext } from '../root';
import { ErrorBoundary } from '../error-boundary';
import { canvasLoader } from './canvas-loader';

function ThemedSurface( {
	children,
	wrapperClassname,
}: {
	children: React.ReactNode;
	wrapperClassname?: string;
} ) {
	const { themeColorSettings } = useColorPreference();

	return (
		<ThemeProvider color={ themeColorSettings.app }>
			<div
				className={ clsx(
					'zero-admin-layout__surface',
					wrapperClassname
				) }
			>
				<ErrorBoundary>{ children }</ErrorBoundary>
			</div>
		</ThemeProvider>
	);
}

function NotFoundComponent() {
	const outlet = useContext( OutletContext );

	// If there is no route match, render the root so the sidebar appears.
	if ( ! outlet ) {
		return <Root />;
	}
	// If we're in Root, only render inside the stage surface.
	if ( outlet !== 'stage' ) {
		return;
	}
	return (
		<ThemedSurface wrapperClassname="is-stage">
			<Page>
				<Stack
					direction="column"
					align="center"
					justify="center"
					style={ { height: '100%', width: '100%' } }
					gap={ 2 }
				>
					<h1>{ __( 'Route not found', 'zero-admin' ) }</h1>
				</Stack>
			</Page>
		</ThemedSurface>
	);
}

type RouteLoaderData = {
	canvas?: RouteCanvas;
	canvasLoaded?: Promise< void >;
	inspector?: boolean;
	title?: string;
};

const rootRoute = createRootRoute( {
	component: Root,
	loader: async () => {
		await Promise.all( [
			// Needed for the site hub logo and title, which is present on all
			// pages.
			resolveSelect( coreStore ).getEntityRecord(
				'root',
				'__unstableBase'
			),
			// Needed for the user menu, which is present on all pages.
			resolveSelect( coreStore ).getCurrentUser(),
		] );
	},
} ) as AnyRoute & {
	addChildren: ( routes: AnyRoute[] ) => AnyRoute;
};

// Build the loader function wrapper used by both static and deferred routes.
function buildRouteLoader( _route: Route ) {
	return async ( {
		params,
		deps,
	}: {
		params: Record< string, string >;
		deps: { search: Record< string, unknown > };
	} ) => {
		const promises = [];
		if ( _route.loader ) {
			promises.push( _route.loader( { params, search: deps.search } ) );
		}

		// Start title resolution in parallel with loader/canvas/inspector.
		// title() often fetches the same entity as loader() via resolveSelect,
		// sharing core-data's in-flight promise cache — no duplicate HTTP requests.
		let titlePromise: Promise< string | undefined > | undefined;
		if ( _route.title ) {
			titlePromise = Promise.resolve(
				_route.title( { params, search: deps.search } )
			);
			// Prevent unhandled rejection for orphaned intent preloads.
			titlePromise.catch( () => {} );
		}

		let canvas: RouteCanvas;
		let canvasLoaded: Promise< void > | undefined;
		if ( _route.canvas ) {
			canvas = await _route.canvas( {
				params,
				search: deps.search,
			} );
			// Start canvas data preloading as soon as the canvas
			// config resolves, so it can overlap with any remaining
			// route loader and inspector work. The promise is passed
			// through loader data so the Canvas component can await
			// the same work instead of starting a duplicate call.
			if ( canvas ) {
				canvasLoaded = canvasLoader( canvas );
				// Prevent unhandled rejection for orphaned preloads
				// (e.g., intent-based preloads where the user navigates
				// away). The rejection is preserved for downstream
				// consumers that await canvasLoaded.
				canvasLoaded.catch( () => {} );
			}
		}

		let inspector = true;
		if ( _route.inspector ) {
			inspector = await _route.inspector( {
				params,
				search: deps.search,
			} );
		}

		await Promise.all( promises );

		let title: string | undefined;
		if ( titlePromise ) {
			try {
				title = await titlePromise;
			} catch {
				// Title resolution failed — fall back to menu-based title.
			}
		}

		return {
			canvas,
			canvasLoaded,
			inspector,
			title,
		};
	};
}

// Build the lazy component that renders stage/inspector surfaces from a content module.
function buildContentComponent(
	routePath: string,
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	module: Record< string, any >
) {
	return function Component() {
		const { inspector } = ( useLoaderData( {
			from: routePath,
		} ) ?? {} ) as RouteLoaderData;

		const outlet = useContext( OutletContext );
		switch ( outlet ) {
			case 'inspector':
				if ( module.inspector && inspector ) {
					return (
						<ThemedSurface wrapperClassname="is-inspector">
							<module.inspector />
						</ThemedSurface>
					);
				}
				return null;
			case 'stage':
				if ( module.stage ) {
					return (
						<ThemedSurface wrapperClassname="is-stage">
							<module.stage />
						</ThemedSurface>
					);
				}
				return null;
		}
		return null;
	};
}

const createRouteTree = ( routes: Route[] = [] ) => {
	const tanstackRoutes: AnyRoute[] = routes.map( ( _route ) => {
		// Deferred routes: route module not yet imported, lazy-load everything on navigation.
		const isDeferred =
			_route.route_module && ! _route.beforeLoad && ! _route.loader;

		let tanstackRoute: AnyRoute;

		if ( isDeferred ) {
			const routeModuleName = _route.route_module;
			const contentModuleName = _route.content_module;

			// Provide loader on the non-lazy definition so TanStack Router
			// executes it at the right time. The wrapper lazily imports the
			// route module and delegates to the real loader.
			tanstackRoute = createRoute( {
				getParentRoute: () => rootRoute,
				loaderDeps: ( {
					search,
				}: {
					search: Record< string, unknown >;
				} ) => ( { search } ),
				path: _route.path,
				staticData: _route.static_data ?? _route.staticData,
				gcTime: _route.gc_time,
				loader: async ( context: {
					params: Record< string, string >;
					deps: { search: Record< string, unknown > };
				} ) => {
					const routeConfig = routeModuleName
						? await import( routeModuleName ).then(
								( m ) => m.route
						  )
						: null;
					if ( routeConfig ) {
						// eslint-disable-next-line @typescript-eslint/no-unsafe-argument -- dynamic import
						return buildRouteLoader( {
							..._route,
							...routeConfig,
						} )( context );
					}
				},
			} ) as AnyRoute;

			// Lazy-load only the content module (component).
			// Route module import is cached by JS runtime, so no double-loading.
			if ( contentModuleName ) {
				tanstackRoute = (
					tanstackRoute as {
						lazy: ( fn: () => Promise< unknown > ) => AnyRoute;
					}
				 ).lazy( () =>
					import( contentModuleName ).then( ( contentModule ) =>
						createLazyRoute( _route.path )( {
							component: buildContentComponent(
								_route.path,
								// eslint-disable-next-line @typescript-eslint/no-unsafe-argument -- dynamic import
								contentModule
							),
						} )
					)
				);
			}
		} else {
			// Static routes: route module already imported at boot.
			tanstackRoute = createRoute( {
				getParentRoute: () => rootRoute,
				loaderDeps: ( {
					search,
				}: {
					search: Record< string, unknown >;
				} ) => ( { search } ),
				path: _route.path,
				loader: buildRouteLoader( _route ),
				beforeLoad: _route.beforeLoad,
				staticData: _route.static_data ?? _route.staticData,
				onLeave: _route.onLeave,
				gcTime: _route.gc_time,
			} ) as AnyRoute;

			const moduleName = _route.content_module;
			if ( moduleName ) {
				tanstackRoute = (
					tanstackRoute as {
						lazy: ( fn: () => Promise< unknown > ) => AnyRoute;
					}
				 ).lazy( () =>
					import( moduleName ).then( ( module ) =>
						createLazyRoute( _route.path )( {
							component: buildContentComponent(
								_route.path,
								// eslint-disable-next-line @typescript-eslint/no-unsafe-argument -- dynamic import
								module
							),
						} )
					)
				);
			}
		}

		return tanstackRoute;
	} );
	return rootRoute.addChildren( tanstackRoutes );
};

function createPathHistory() {
	return createBrowserHistory( {
		// @ts-expect-error -- parseLocation is available in runtime private API but not in current type defs.
		parseLocation: () => {
			const url = new URL( window.location.href );
			const path = url.searchParams.get( 'p' ) || '/';
			const pathWithPrefix = path.startsWith( '/wp-admin/new' )
				? path
				: `/wp-admin/new${ path }`;
			const pathHref = `${ pathWithPrefix }${ url.hash }`;
			return parseHref( pathHref, window.history.state );
		},
		createHref: ( href: string ) => {
			const path = href.replace( /^\/wp-admin\/new(?=\/|$)/, '' );
			const searchParams = new URLSearchParams( window.location.search );
			searchParams.set( 'p', path );
			return `${ window.location.pathname }?${ searchParams }`;
		},
	} );
}

const history = window.location.pathname.startsWith( '/wp-admin/new' )
	? createBrowserHistory()
	: createPathHistory();

export const getRouter = ( routes: Route[] = [] ) => {
	const routeTree = createRouteTree( routes );
	return createRouter( {
		history,
		routeTree,
		defaultPreload: 'intent',
		basepath: '/wp-admin/new',
		defaultNotFoundComponent: NotFoundComponent,
		defaultViewTransition: true,
	} );
};
/* eslint-enable @typescript-eslint/no-unsafe-assignment, @typescript-eslint/no-unsafe-call, @typescript-eslint/no-unsafe-return, @typescript-eslint/no-unsafe-member-access */
