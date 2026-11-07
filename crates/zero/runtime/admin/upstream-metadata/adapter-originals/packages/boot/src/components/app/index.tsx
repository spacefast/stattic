/* eslint-disable @typescript-eslint/no-unsafe-assignment, @typescript-eslint/no-unsafe-call, @typescript-eslint/no-unsafe-return, @typescript-eslint/no-unsafe-argument, @typescript-eslint/no-unsafe-member-access -- TanStack Router private APIs via unlock() and dynamic imports are untyped */
import { createRoot } from 'react-dom/client';
import { privateApis as routePrivateApis } from '@wordpress/route';
import { useMemo, useEffect, StrictMode } from 'react';
import { unlock } from '@zero-admin/admin-toolkit';
import { useSelect, dispatch } from '@wordpress/data';
import '@wordpress/theme';
import type { DashboardWidget } from '@zero-admin/dashboard';
import { getRouter } from './router';
import { store as zeroAdminStore } from '../../store';
import { updateMenuIcons } from '../../icons';
import { registerNavigationAbility } from '../../abilities/navigate';
import { ModalRenderer } from '../modal-renderer';
import type {
	Route,
	FlatMenuItem,
	RegisteredDashboardWidgetType,
	DashboardContext,
} from '../../store/types';
import './legacy-components-overrides.scss';
import './popover-compatibility.scss';
import './legacy-wpds-compatibility.scss';
import './legacy-admin-ui-bridge.scss';

const { RouterProvider } = unlock( routePrivateApis );

function App() {
	const routes = useSelect(
		( select ) => select( zeroAdminStore ).getRoutes(),
		[]
	);

	const router = useMemo( () => {
		return getRouter( routes );
	}, [ routes ] );

	useEffect( () => {
		registerNavigationAbility( router );
	}, [ router ] );

	return <RouterProvider router={ router } />;
}

/**
 * Initialize the application.
 *
 * Using a simple structure without router for now to fix the "useContext" error.
 *
 * @param routes
 * @param menuItems
 * @param metaMenuItems
 * @param dashboardWidgetTypes
 * @param initModules
 * @param dashboardContext
 * @param dashboardDefaultLayout
 */
async function init(
	routes: Route[],
	menuItems: FlatMenuItem[],
	metaMenuItems: FlatMenuItem[],
	dashboardWidgetTypes?: RegisteredDashboardWidgetType[],
	initModules?: string[],
	dashboardContext?: DashboardContext,
	dashboardDefaultLayout?: DashboardWidget< unknown >[]
) {
	const rootElement = document.getElementById( 'zero-admin-app' );
	if ( ! rootElement ) {
		throw new Error( "Root element with id 'zero-admin-app' not found." );
	}

	// Only import route modules that have beforeLoad handlers (must run at boot).
	// Other route modules are deferred and lazy-loaded on navigation.
	routes = await Promise.all(
		routes.map( async ( routeItem: Route ) => {
			if ( routeItem.route_module && routeItem.before_load ) {
				const { route } = await import( routeItem.route_module );
				return { ...routeItem, ...route };
			}
			return routeItem;
		} )
	);

	routes.forEach( dispatch( zeroAdminStore ).registerRoute );

	menuItems.forEach( ( menuItem ) => {
		void dispatch( zeroAdminStore ).registerMenuItem(
			menuItem.id,
			menuItem
		);
	} );

	metaMenuItems.forEach( ( menuItem ) => {
		void dispatch( zeroAdminStore ).registerSiteHubHelpMenuItem(
			menuItem.id,
			menuItem
		);
	} );

	updateMenuIcons( dispatch( zeroAdminStore ).updateMenuItem );

	if ( initModules?.length ) {
		await Promise.all(
			initModules.map( async ( initModule ) => {
				try {
					const module = await import( initModule );
					if ( typeof module.init === 'function' ) {
						await module.init();
					}
				} catch ( error ) {
					// eslint-disable-next-line no-console
					console.error(
						`Failed to load init module ${ initModule }:`,
						error
					);
				}
			} )
		);
	}

	if ( dashboardWidgetTypes?.length ) {
		void dispatch( zeroAdminStore ).setDashboardWidgetTypes(
			dashboardWidgetTypes
		);
	}

	if ( dashboardContext ) {
		void dispatch( zeroAdminStore ).setDashboardContext( dashboardContext );
	}

	if ( dashboardDefaultLayout?.length ) {
		void dispatch( zeroAdminStore ).setDashboardDefaultLayout(
			dashboardDefaultLayout
		);
	}

	// We need to apply different view transitions for Safari, because it
	// doesn't fully support object-fit on the pseudo images and we need a
	// different, lesser, approach.
	if ( /^((?!chrome|android).)*safari/i.test( navigator.userAgent ) ) {
		document.documentElement.classList.add( 'is-safari' );
	}

	const root = createRoot( rootElement );
	root.render(
		<StrictMode>
			<App />
			<ModalRenderer />
		</StrictMode>
	);
}

// Export the init function without executing it
export default init;
/* eslint-enable @typescript-eslint/no-unsafe-assignment, @typescript-eslint/no-unsafe-call, @typescript-eslint/no-unsafe-return, @typescript-eslint/no-unsafe-argument, @typescript-eslint/no-unsafe-member-access */
