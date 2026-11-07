import clsx from 'clsx';
import {
	useEffect,
	useLayoutEffect,
	useRef,
	createContext,
	useState,
	lazy,
	Suspense,
} from 'react';
import {
	NavigableRegion,
	useColorPreference,
	Page,
	unlock,
	EntityValidityProvider,
	DEFAULT_SITE_NAME,
} from '@zero-admin/admin-toolkit';
import { store as agentContextStore } from '@zero-admin/ai-agent';
import { IconButton } from '@wordpress/ui';
import { ThemeProvider } from '@zero-admin/theme';
import { menu } from '@wordpress/icons';
import {
	__unstableMotion as motion,
	__unstableAnimatePresence as AnimatePresence,
	__unstableUseNavigateRegions as useNavigateRegions,
	SlotFillProvider,
} from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import type { UnstableBase } from '@wordpress/core-data';
import { decodeEntities } from '@wordpress/html-entities';
import { speak } from '@wordpress/a11y';
import { __, sprintf } from '@wordpress/i18n';
import { useViewportMatch, useReducedMotion } from '@wordpress/compose';
import {
	CommandMenu,
	// @ts-expect-error Commands package is not typed yet.
} from '@wordpress/commands';
import { privateApis as routePrivateApis } from '@wordpress/route';
import {
	useSelect,
	useDispatch,
	select as selectStore,
	dispatch,
} from '@wordpress/data';
import { store as noticesStore, SnackbarNotices } from '@wordpress/notices';
import { PluginArea } from '@wordpress/plugins';
import Sidebar from '../sidebar';
import { useCommands } from '../commands';
import './style.scss';
import { ErrorBoundary } from '../error-boundary';
import { store as rootStore } from '../../store';
import UnsavedChangesWarning from '../unsaved-changes-warning';
import {
	buildSitemapData,
	preloadSitemapData,
} from '../../utils/sitemap-context';
import { findClosestMenuItem } from '../sidebar/path-matching';
import NavigationProgressBar from '../navigation-progress-bar';
import Canvas from '../canvas';
import UnsavedChangesModal from '../unsaved-changes-modal';

const { Outlet, useLoaderData, useRouterState } = unlock( routePrivateApis );

const ANIMATION_DURATION = 0.2;

// Dynamic import for AI Agent - loaded only when needed
const AIAgent = lazy( () => import( '@zero-admin/ai-agent' ) );

// Wrapper component that only renders AI Agent when experiment is enabled.
function AIAgentWrapper() {
	// AI Component Slot - The Big Sky Agents Plugin & Agenttic handles all UI
	return (
		<ErrorBoundary>
			<Suspense fallback={ null }>
				<AIAgent />
			</Suspense>
		</ErrorBoundary>
	);
}

export const OutletContext = createContext< string >( '' );

/**
 * Manages the document title with a three-tier system:
 * 1. Route-level title (from `title()` in route definition, resolved during loader)
 * 2. Menu-based fallback (derived from sidebar menu hierarchy)
 * 3. Component-level override (via `useDocumentTitle` hook — escape hatch)
 *
 * Also announces the page title to screen readers via `speak()` on client-side
 * navigations. Initial page load is not announced — screen readers already read
 * `document.title` on load.
 *
 * @param currentRouteId The current route ID from TanStack Router matches.
 */
function useDocumentTitleManager( currentRouteId: string ) {
	const hasNavigatedRef = useRef( false );
	const { pathname } = useRouterState( {
		select: ( state ) => state.location,
	} );

	// eslint-disable-next-line @typescript-eslint/no-unsafe-assignment, @typescript-eslint/no-unsafe-call
	const loaderData = useLoaderData( { from: currentRouteId } ) as
		| { title?: unknown }
		| undefined;
	const rawTitle: unknown = loaderData?.title;
	const routeTitle = typeof rawTitle === 'string' ? rawTitle : undefined;

	const { fullTitle, leafTitle } = useSelect(
		( select ) => {
			const menuItems = select( rootStore ).getMenuItems();
			const match = findClosestMenuItem( pathname, menuItems );
			const base = select( coreStore ).getEntityRecord< UnstableBase >(
				'root',
				'__unstableBase'
			);
			const siteName = base?.name
				? decodeEntities( base.name )
				: DEFAULT_SITE_NAME;

			if ( routeTitle ) {
				// Route-level title: derive parent section from menu hierarchy.
				// If the closest menu match's label differs from the route title,
				// use it as the parent breadcrumb section.
				let pageTitle = routeTitle;
				if ( match?.label && match.label !== routeTitle ) {
					pageTitle = `${ routeTitle } \u2039 ${ match.label }`;
				}
				return {
					fullTitle: `${ pageTitle } \u2014 ${ siteName }`,
					leafTitle: routeTitle,
				};
			}

			// Menu-based fallback (existing behavior).
			if ( ! match?.label ) {
				return { fullTitle: undefined, leafTitle: undefined };
			}
			let pageTitle = match.label;
			if ( match.parent ) {
				const parentItem = menuItems.find(
					( item ) => item.id === match.parent
				);
				if ( parentItem?.label ) {
					pageTitle = `${ pageTitle } \u2039 ${ parentItem.label }`;
				}
			}
			return {
				fullTitle: `${ pageTitle } \u2014 ${ siteName }`,
				leafTitle: match.label,
			};
		},
		[ pathname, routeTitle ]
	);

	useLayoutEffect( () => {
		if ( fullTitle ) {
			document.title = fullTitle;
		}
	}, [ fullTitle ] );

	useEffect( () => {
		if ( ! hasNavigatedRef.current ) {
			hasNavigatedRef.current = true;
			return;
		}
		if ( leafTitle ) {
			speak( leafTitle, 'assertive' );
		}
	}, [ leafTitle ] );
}

function Root() {
	const { themeColorSettings } = useColorPreference();
	const isMobileViewport = useViewportMatch( 'medium', '<' );
	const [ isMobileSidebarOpen, setIsMobileSidebarOpen ] = useState( false );
	const navigateRegionsProps = useNavigateRegions();
	const disableMotion = useReducedMotion();
	const { matches, location } = useRouterState();
	const currentRouteId = String( matches.at( -1 )?.routeId ?? '' );
	useDocumentTitleManager( currentRouteId );
	useCommands();

	// eslint-disable-next-line @typescript-eslint/no-unsafe-assignment
	const { createErrorNotice } = useDispatch( noticesStore );

	function onPluginAreaError( name: string ) {
		// eslint-disable-next-line @typescript-eslint/no-floating-promises
		createErrorNotice(
			sprintf(
				/* translators: %s: plugin name */
				__(
					'The "%s" plugin has encountered an error and cannot be rendered.',
					'zero-admin'
				),
				name
			)
		);
	}

	// eslint-disable-next-line @typescript-eslint/no-unsafe-assignment, @typescript-eslint/no-unsafe-call
	const { canvas } = ( useLoaderData( { from: currentRouteId } ) ?? {} ) as {
		canvas?: { isPreview?: boolean; size?: string };
	};

	useEffect( () => {
		setIsMobileSidebarOpen( false );
	}, [ location.pathname, isMobileViewport ] );

	// Preload and register sitemap context entry for AI agent
	useEffect( () => {
		// Preload post types data needed for sitemap building
		void preloadSitemapData();

		void dispatch( agentContextStore ).addContextEntry( {
			id: 'sitemap',
			type: 'sitemap',
			getData: () => {
				const routes = selectStore( rootStore ).getRoutes();
				const menuItems = selectStore( rootStore ).getMenuItems();
				const sitemapData = buildSitemapData( routes, menuItems );
				const { partner } =
					selectStore( rootStore ).getDashboardContext();

				// Add Multi-Site Dashboard (MSD) pages that live outside the admin SPA.
				// The zero-admin/navigate ability handles these via window.location.
				const msdBaseUrl = `${ partner.dashboard_url }/sites/${ window.location.hostname }`;
				sitemapData.menuItems.push( {
					id: 'msd-domains',
					to: `${ msdBaseUrl }/domains`,
					label: 'Domains',
					parent: null,
				} );

				return sitemapData;
			},
		} );

		return () => {
			void dispatch( agentContextStore ).removeContextEntry( 'sitemap' );
		};
	}, [] );

	const content = (
		<>
			<EntityValidityProvider>
				{
					// The outer theme provider sets all the settings, but hardcodes the
					// the background to `#f8f8f8`. Because of the `isRoot` property,
					// ThemeProvider affects all portalled content that is not wrapped in
					// a ThemeProvider itself (such as legacy wp components), forcing it to
					// appear in a "light" color scheme.
				 }
				<ThemeProvider
					isRoot
					color={ {
						...themeColorSettings.sidebar,
						bg: '#f8f8f8',
					} }
				>
					<NavigationProgressBar />
					{
						// The inner ThemeProvider applies the chosen scheme to its children
					 }
					<ThemeProvider color={ themeColorSettings.sidebar }>
						{ isMobileViewport && (
							<Page.SidebarToggleFill>
								<IconButton
									variant="minimal"
									tone="neutral"
									icon={ menu }
									onClick={ () =>
										setIsMobileSidebarOpen( true )
									}
									label={ __( 'Open sidebar', 'zero-admin' ) }
									size="compact"
								/>
							</Page.SidebarToggleFill>
						) }
						<UnsavedChangesWarning />
						<UnsavedChangesModal />
						<CommandMenu />
						<SnackbarNotices className="zero-admin-notices__snackbar" />
						{ /* eslint-disable @typescript-eslint/no-unsafe-assignment, @typescript-eslint/no-unsafe-member-access */ }
						<div
							{ ...navigateRegionsProps }
							ref={ navigateRegionsProps.ref }
							className={ clsx(
								'zero-admin-layout',
								navigateRegionsProps.className,
								{
									'has-full-canvas':
										canvas && ! canvas.isPreview,
									'is-canvas-compact':
										canvas?.size === 'compact',
								}
							) }
						>
							<div className="zero-admin-layout__content">
								<AnimatePresence>
									{ isMobileViewport &&
										isMobileSidebarOpen && (
											<motion.div
												initial={ { opacity: '0' } }
												animate={ { opacity: 1 } }
												exit={ { opacity: 0 } }
												transition={ {
													type: 'tween',
													duration: disableMotion
														? 0
														: ANIMATION_DURATION,
													ease: 'easeOut',
												} }
												role="button"
												tabIndex={ -1 }
												className="zero-admin-layout__sidebar-backdrop"
												onClick={ () =>
													setIsMobileSidebarOpen(
														false
													)
												}
												onKeyDown={ ( event ) => {
													if (
														event.key === 'Escape'
													) {
														setIsMobileSidebarOpen(
															false
														);
													}
												} }
											/>
										) }
								</AnimatePresence>
								<AnimatePresence>
									{ isMobileViewport &&
										isMobileSidebarOpen && (
											<motion.div
												initial={ { x: '-100%' } }
												animate={ { x: 0 } }
												exit={ { x: '-100%' } }
												transition={ {
													type: 'tween',
													duration: disableMotion
														? 0
														: ANIMATION_DURATION,
													ease: 'easeOut',
												} }
												className="zero-admin-layout__sidebar is-mobile"
											>
												<Sidebar />
											</motion.div>
										) }
								</AnimatePresence>
								{ ! isMobileViewport && (
									<NavigableRegion
										ariaLabel={ __(
											'Navigation',
											'zero-admin'
										) }
										className="zero-admin-layout__sidebar-region"
									>
										<div className="zero-admin-layout__sidebar">
											<Sidebar />
										</div>
									</NavigableRegion>
								) }
								<OutletContext.Provider value="base">
									<Outlet />
								</OutletContext.Provider>
								<OutletContext.Provider value="stage">
									<Outlet />
								</OutletContext.Provider>
								<OutletContext.Provider value="inspector">
									<Outlet />
									{ /* Show AI Agent as inspector - always on the far right
								     TODO: We need to put more thought into how to handle multiple inspector panels
								 */ }
									<AIAgentWrapper />
								</OutletContext.Provider>
								{ canvas && (
									// The canvas must be mounted in the root. We should not
									// mount it in route components, otherwise it will
									// remount when navigating.
									<ThemeProvider
										color={ themeColorSettings.app }
									>
										<div className="zero-admin-layout__canvas-container">
											{ canvas.isPreview &&
												isMobileViewport && (
													<div className="zero-admin-layout__mobile-sidebar-drawer">
														<IconButton
															variant="minimal"
															tone="neutral"
															icon={ menu }
															onClick={ () =>
																setIsMobileSidebarOpen(
																	true
																)
															}
															label={ __(
																'Open sidebar',
																'zero-admin'
															) }
															size="compact"
														/>
													</div>
												) }
											<div
												className={ clsx(
													'zero-admin-layout__canvas',
													{
														'is-full-canvas':
															! canvas.isPreview,
														'has-mobile-drawer':
															canvas.isPreview &&
															isMobileViewport,
													}
												) }
											>
												<ErrorBoundary>
													<Canvas
														currentRouteId={
															currentRouteId
														}
													/>
												</ErrorBoundary>
											</div>
										</div>
									</ThemeProvider>
								) }
							</div>
						</div>
						{ /* eslint-enable @typescript-eslint/no-unsafe-assignment, @typescript-eslint/no-unsafe-member-access */ }
					</ThemeProvider>
				</ThemeProvider>
				<PluginArea onError={ onPluginAreaError } />
			</EntityValidityProvider>
		</>
	);

	return <SlotFillProvider>{ content }</SlotFillProvider>;
}

export default Root;
