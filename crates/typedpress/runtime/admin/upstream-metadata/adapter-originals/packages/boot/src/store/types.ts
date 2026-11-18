import type { ComponentType, ComponentProps } from 'react';
import type { Icon } from '@wordpress/ui';
import type {
	Dashboard,
	DashboardWidget,
	RegisteredDashboardWidgetType,
} from '@zero-admin/dashboard';

interface RouteSurfaces {
	// `content` is the main surface of the route, where the primary content is displayed (TanStack Router's `Outlet`).
	content: ComponentType;
	inspector?: ComponentType;
	sidebar?: ComponentType; // TODO: need to clarify this prop in connection with SidebarContent
	mobile?: ComponentType;
}

/**
 * The return value of Route.canvas.
 * Properties used to render the editor canvas.
 */

type CanvasExtraOptions = {
	/**
	 * Whether the canvas is a preview, or "view" mode.
	 */
	isPreview?: boolean;
	/**
	 * Optional layout size for route canvas.
	 * - 'fluid': Stage becomes 380px when canvas exists. Canvas expands to remaining space.
	 * - 'compact': Canvas becomes 400px and stage expands.
	 */
	size?: 'fluid' | 'compact';
	/**
	 * The link to the edit page of the post.
	 */
	editLink?: string;
};

export type UrlCanvas = { url: string };
export type FrontPageCanvas = CanvasExtraOptions;
export type PostCanvas = {
	/**
	 * The post type of the post to edit.
	 */
	postType: string;
	/**
	 * The ID of the post to edit.
	 */
	postId?: string;
	/**
	 * Array of block configurations for the preview.
	 */
	getTemplate?: () => Promise< Record< string, unknown >[] >;
} & CanvasExtraOptions;

export type RouteCanvas = UrlCanvas | FrontPageCanvas | PostCanvas | undefined;

export type IconType =
	/**
	 * A Dashicon string definition: `dashicons-<icon-name>`.
	 */
	| string
	/**
	 * A JSX element.
	 * Regular JSX element. `<Icon icon={wordpress} />`
	 */
	| JSX.Element
	/**
	 * A Design System Icon component.
	 * `svg` element or an `SVG` from `@wordpress/primitives`.
	 */
	| ComponentProps< typeof Icon >[ 'icon' ];
/**
 * The shape of the Route object
 * registered in the store.
 */
export interface Route {
	path: string;
	parentRoute?: string;
	surfaces?: RouteSurfaces;
	loader?: ( {
		params,
		search,
	}: {
		params: Record< string, string >;
		search: Record< string, unknown >;
	} ) => Promise< {
		canvas?: unknown;
		inspector?: boolean;
	} >;
	title?: ( context: {
		params: Record< string, string >;
		search: Record< string, unknown >;
	} ) => string | Promise< string >;
	beforeLoad?: () => void;
	staticData?: Record< string, unknown >;
	canvas?: ( {
		params,
		search,
	}: {
		params: Record< string, string >;
		search: Record< string, unknown >;
	} ) => Promise< RouteCanvas >;
	/**
	 * Module to lazy load the route component.
	 */
	content_module?: string;
	route_module?: string;
	/**
	 * Whether the route module exports a beforeLoad handler.
	 * When true, the route module is imported at boot time (static).
	 * When false/absent, the route module is lazy-loaded on navigation.
	 */
	before_load?: boolean;
	/**
	 * Static route data from PHP boot data (extracted from package.json at build time).
	 * Used to provide staticData to deferred routes that haven't loaded their route module yet.
	 */
	static_data?: Record< string, unknown >;
	inspector?: ( {
		search,
	}: {
		params: Record< string, string >;
		search: Record< string, unknown >;
	} ) => Promise< boolean >;
	onLeave?: ( match: unknown ) => void;
	/**
	 * TanStack Router gcTime (milliseconds) for loader cache eviction.
	 *
	 * CIAB-only: not present in Gutenberg's routing framework.
	 * Remove when migrating to Gutenberg. Do not rely on this for new routes.
	 */
	gc_time?: number;
}

/**
 * A flat menu item structure used for storage and registration.
 * Tree structure is built on-demand from this flat representation.
 */
export interface FlatMenuItem {
	id: string;
	to: string;
	icon?: IconType;
	label: string;
	badge?: string | number | null;
	parent?: string;
	parent_type?: 'drilldown' | 'dropdown' | 'submenu';
	callback?: ( event: React.MouseEvent ) => void;
}

/**
 * A hierarchical menu item structure used for rendering.
 * Built from FlatMenuItem[] by tree building utilities.
 */
export interface MenuItem {
	id: string;
	to: string;
	icon?: IconType;
	label: string;
	badge?: string | number | null;
	children?: MenuItem[];
	parent_type?: 'drilldown' | 'dropdown' | 'submenu';
	callback?: (
		event: React.MouseEvent
	) => void | Promise< void > | Promise< unknown >;
}

export type { RegisteredDashboardWidgetType };

/**
 * Nudge interface matching backend structure.
 */
export interface Nudge {
	id: string;
	type: string;
	severity: 'info' | 'warning' | 'urgent' | 'error';
	title: string;
	content?: string;
	icon?: 'info' | 'warning' | 'star' | 'bell';
	action?: {
		text: string;
		url: string;
		style?: 'primary' | 'secondary' | 'link';
	};
	context?: Record< string, unknown >;
}

/**
 * Entity record dependency for notice data subscriptions.
 */
export interface EntityDependency {
	kind: string;
	name: string;
	id: string | number;
}

/**
 * Infer notice types from Dashboard component props.
 */
type DashboardProps = ComponentProps< typeof Dashboard >;
type DashboardNoticeType = NonNullable< DashboardProps[ 'notices' ] >[ number ];
type DashboardNoticeAction = NonNullable<
	DashboardNoticeType[ 'actions' ]
>[ number ];

/**
 * Action with click handler for store-registered notices.
 */
export interface DashboardNoticeConfigAction extends DashboardNoticeAction {
	onClick: () => void;
}

/**
 * Dashboard notice configuration.
 * Extends inferred props with store-specific properties.
 */
export interface DashboardNoticeConfig
	extends Omit< DashboardNoticeType, 'actions' > {
	actions?: DashboardNoticeConfigAction[];
	/**
	 * Function that returns whether notice should be shown.
	 * This allows reactive notices that respond to data changes.
	 */
	shouldShow?: () => boolean;
	/**
	 * Optional priority for ordering (higher = shown first).
	 */
	priority?: number;
	/**
	 * Entity record dependencies that this notice depends on.
	 * The dashboard will subscribe to these to trigger re-renders when data changes.
	 */
	dataDependencies?: EntityDependency[];
}

/**
 * Dashboard context data from plugins via filter.
 */
export interface DashboardContext {
	logout_url: string;
	overrideRoute?: string;
	partner: { slug: string; dashboard_url: string; dashboard_name: string };
	[ key: string ]: unknown;
}

/**
 * Type definition for the store selectors.
 * Used to provide type safety when using useSelect with the zero-admin store.
 */
export interface StoreSelectors {
	getDashboardWidgetTypes: () => RegisteredDashboardWidgetType[];
	getDashboardNotices: () => DashboardNoticeConfig[];
	getDashboardContext: () => DashboardContext;
	getDashboardDefaultLayout: () => DashboardWidget< unknown >[];
}

/**
 * A registered modal that can be rendered globally.
 * Modals self-deregister when closed.
 */
export interface RegisteredModal {
	/** Unique identifier for this modal */
	id: string;
	/** The modal component to render - intentionally uses `any` to accept any component type */
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	component: ComponentType< any >;
	/** Props to pass to the modal component */
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	props?: Record< string, any >;
}
