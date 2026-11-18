import type { AnyRouter } from '@wordpress/route';
import type { PrivateApiToken } from '@zero-admin/private-apis';
import type { ComponentType, ReactNode } from 'react';

/**
 * Router instance type derived from AnyRouter (re-exported by @wordpress/route
 * from @tanstack/react-router). This avoids direct imports from
 * @tanstack/react-router which may not resolve in all packages.
 */
type RouterInstance = AnyRouter;
type RouterState = RouterInstance[ 'state' ];
type RouterLocation = RouterState[ 'location' ];
type RouterMatch = RouterState[ 'matches' ][ number ];

type RoutePrivateApis = {
	createBrowserHistory: ( opts?: { window?: Window } ) => {
		subscribe: ( cb: () => void ) => () => void;
	};
	createLazyRoute: ( id: string ) => ( opts: Record< string, unknown > ) => {
		lazy: Record< string, unknown >;
	};
	createLink: < T extends ComponentType< Record< string, unknown > > >(
		component: T
	) => T;
	createRootRoute: ( opts?: Record< string, unknown > ) => unknown;
	createRoute: ( opts?: Record< string, unknown > ) => unknown;
	createRouter: ( opts: Record< string, unknown > ) => RouterInstance;
	Outlet: ComponentType< Record< string, never > >;
	RouterProvider: ComponentType< {
		router: RouterInstance;
		children?: ReactNode;
	} >;
	redirect: typeof import('@wordpress/route').redirect;
	useCanGoBack: () => boolean;
	useLoaderData: < T = unknown >( opts?: {
		from?: string;
		select?: ( data: unknown ) => T;
	} ) => T;
	useLocation: () => RouterLocation;
	useMatches: () => RouterMatch[];
	useRouter: () => RouterInstance;
	useRouterState: < T = RouterState >( opts?: {
		select?: ( state: RouterState ) => T;
	} ) => T;
	parseHref: (
		href: string,
		opts?: Record< string, unknown >
	) => RouterLocation;
	useBlocker: ( opts: {
		condition?: boolean;
		blockerFn?: () => Promise< boolean > | boolean;
	} ) => {
		status: 'blocked' | 'idle';
		proceed: () => void;
		reset: () => void;
	};
};

declare module '@wordpress/route' {
	export const privateApis: PrivateApiToken< RoutePrivateApis >;
}
