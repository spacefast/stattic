import type { ReactNode, ComponentProps } from 'react';
import type { Icon, Badge } from '@wordpress/ui';
import type { Field } from '@spacepress-admin/dataviews';
import type { Notice } from '@wordpress/components';
import type { GridLayoutItem, GridProps } from '@automattic/grid';

/**
 * Inferred types
 */
type NoticeProps = ComponentProps< typeof Notice >;
type NoticeStatus = NoticeProps[ 'status' ];
type DSIconType = ComponentProps< typeof Icon >[ 'icon' ];

/**
 * Base properties shared by all dashboard widget instances.
 */
interface DashboardWidgetBase< Item > {
	/**
	 * Unique identifier for the widget instance.
	 * Maps to `key` in `GridLayoutItem`.
	 */
	uid: GridLayoutItem[ 'key' ];

	/**
	 * Widget type name (e.g., `woocommerce-analytics/sales-summary`).
	 */
	type: string;

	/**
	 * User-configured widget attributes.
	 */
	attributes?: Item;

	/**
	 * Number of grid columns this widget spans.
	 */
	width?: GridLayoutItem[ 'width' ];

	/**
	 * Number of grid rows this widget spans.
	 */
	height?: GridLayoutItem[ 'height' ];

	/**
	 * Display order in the grid (lower values appear first).
	 */
	order?: GridLayoutItem[ 'order' ];

	__meta?: {
		deleted?: boolean;
	};
}

/**
 * Dashboard widget instance.
 *
 * `fullWidth` and `fillWidth` are mutually exclusive:
 * - `fullWidth` spans all columns (`grid-column: 1 / -1`).
 * - `fillWidth` spans the remaining columns in the current row.
 */
export type DashboardWidget< Item > =
	| ( DashboardWidgetBase< Item > & {
			fullWidth?: false | undefined;
			fillWidth?: boolean;
	  } )
	| ( DashboardWidgetBase< Item > & {
			fullWidth: true;
			fillWidth?: false | undefined;
	  } );

export interface DashboardWidgetLayout {
	contentPadding?: boolean;
	scrollableContent?: boolean;
}

interface DashboardLayoutWidgetDefaults {
	width?: GridLayoutItem[ 'width' ];
	height?: GridLayoutItem[ 'height' ];
	order?: GridLayoutItem[ 'order' ];
}

export interface DashboardWidgetBadge
	extends Pick< ComponentProps< typeof Badge >, 'intent' > {
	/**
	 * Badge text/value to display
	 */
	value: string | number;

	/**
	 * Accessible label for screen readers
	 * If not provided, defaults to the value
	 */
	ariaLabel?: string;

	/**
	 * Additional information text displayed in a tooltip on hover
	 */
	info?: string;
}

/**
 * Configuration for widget error state.
 * Allows widgets to customize the error UI displayed when an error occurs.
 */
export interface WidgetErrorConfig {
	/**
	 * Custom error message to display.
	 * If not provided, a default message will be shown.
	 */
	message?: string;

	/**
	 * Custom icon to display.
	 * If not provided, a default warning icon will be shown.
	 */
	icon?: React.ReactElement< React.ComponentProps< 'svg' > >;

	/**
	 * Optional action button (e.g., retry).
	 */
	action?: {
		label: string;
		onClick: () => void;
	};
}

export interface WidgetRenderProps< Item = unknown > {
	/**
	 * Widget attributes configured by the user
	 */
	attributes?: Item;

	/**
	 * Remove this widget from the dashboard.
	 * Persists immediately (no edit mode required).
	 */
	onRemove?: () => void;

	/**
	 * Function to update the widget's badge.
	 * Accepts a simple value (string/number), a full config object, or null to hide.
	 *
	 * @example
	 * ```tsx
	 * // Simple value
	 * setBadge( 14 );
	 *
	 * // With intent
	 * setBadge( {
	 *   value: 3,
	 *   intent: 'high',
	 * } );
	 *
	 * // Hide badge
	 * setBadge( null );
	 * ```
	 */
	setBadge?: ( badge: string | number | DashboardWidgetBadge | null ) => void;

	/**
	 * Function to report an error state in the widget.
	 * Pass `true` for default error, a config object for custom error, or `null` to clear.
	 *
	 * @example
	 * ```tsx
	 * // Show error with default message
	 * setError( true );
	 *
	 * // Show error with custom message
	 * setError( { message: 'Unable to fetch sales data' } );
	 *
	 * // Show error with retry action
	 * setError( {
	 *   message: 'Failed to load data',
	 *   action: { label: 'Retry', onClick: handleRetry }
	 * } );
	 *
	 * // Clear error state
	 * setError( null );
	 * ```
	 */
	setError?: ( error: WidgetErrorConfig | true | null ) => void;
}

export interface DashboardWidgetType< Item > {
	/**
	 * Unique identifier for the widget.
	 */
	name: string;

	/**
	 * Display name for the widget.
	 */
	title?: string;

	/**
	 * Optional icon to display before the widget title.
	 */
	icon?: DSIconType;

	/**
	 * Optional description for the widget.
	 */
	description?: string;

	/**
	 * Optional information for the widget.
	 */
	info?: string;

	/**
	 * Render module for the widget.
	 */
	render_module: string;

	/**
	 * Optional attributes for the widget.
	 */
	attributes?: Field< Item >[];

	/**
	 * Example attributes for the widget.
	 */
	example: Item;

	/**
	 * Optional layout options for the widget.
	 * @example
	 * ```tsx
	 * layout: {
	 *   contentPadding: false,
	 * },
	 * ```
	 */
	layout?: DashboardWidgetLayout;

	/**
	 * Default values for the widget instance when inserted into the dashboard.
	 * These define the initial grid placement, not the internal widget layout.
	 * @example
	 * ```tsx
	 * defaults: {
	 *   width: 2,
	 *   height: 3,
	 * },
	 * ```
	 */
	defaults?: DashboardLayoutWidgetDefaults;
}

// Custom action type for dashboard notices (not derived from NoticeProps['actions']
// since we render custom buttons rather than using Notice's built-in actions)
export interface DashboardNoticeAction {
	id: string;
	label: string;
}

export interface DashboardNoticeType {
	id: string;
	status: NoticeStatus;
	message: string;
	actions?: DashboardNoticeAction[];
}

export interface DashboardNoticeProps extends DashboardNoticeType {
	onNoticeAction?: ( noticeId: string, actionId: string ) => void;
}

/**
 * Dashboard widget type registration data from the backend.
 * Contains minimal metadata needed to dynamically load widget modules.
 * The widget_module exports the full DashboardWidgetType definition.
 */
export interface RegisteredDashboardWidgetType {
	name: string;
	render_module: string;
	widget_module: string;
}

/**
 * Context passed as the second argument to `onChange`.
 * Indicates which internal operation triggered the layout change.
 *
 * - `'commit'` — User committed staged edits (exiting edit mode).
 * - `'direct'` — A widget changed the layout directly (e.g., self-removal).
 */
export interface LayoutChangeContext {
	source: 'commit' | 'direct';
}

export interface DashboardProps {
	children?: ReactNode;
	layout: DashboardWidget< unknown >[];
	onChange: (
		layout: DashboardWidget< unknown >[],
		context?: LayoutChangeContext
	) => void;
	widgetTypes?: DashboardWidgetType< unknown >[];
	notices?: DashboardNoticeType[];
	onNoticeAction?: ( noticeId: string, actionId: string ) => void;

	/**
	 * Callback when a widget reports or clears an error state.
	 * Use this to track widget errors and optionally show a notice.
	 *
	 * @param uid   - The widget's unique identifier.
	 * @param error - The error config (`true` for default, object for custom), or `null` if cleared.
	 */
	onWidgetError?: (
		uid: string,
		error: WidgetErrorConfig | true | null
	) => void;

	/**
	 * Fixed number of columns for the grid layout.
	 * When set, the grid always uses this exact column count,
	 * bypassing responsive and collapse behavior.
	 * Mutually exclusive with `minColumnWidth`.
	 *
	 * @example
	 * ```tsx
	 * <Dashboard columnsNumber={ 3 } />
	 * ```
	 */
	columnsNumber?: GridProps[ 'columns' ];

	/**
	 * Minimum column width in pixels for responsive grid layout.
	 * The dashboard will automatically adjust the number of columns
	 * to ensure each column is at least this wide.
	 * Mutually exclusive with `columnsNumber`.
	 *
	 * @default 350
	 * @example
	 * ```tsx
	 * // Ensure line charts have adequate space
	 * <Dashboard minColumnWidth={ 420 } />
	 *
	 * // Allow more compact layout for simple widgets
	 * <Dashboard minColumnWidth={ 280 } />
	 * ```
	 */
	minColumnWidth?: GridProps[ 'minColumnWidth' ];

	/**
	 * Breakpoint in pixels below which dashboard switches to single column.
	 * Describes what actually happens: the grid collapses to a single column at this width.
	 *
	 * @default 640
	 * @example
	 * ```tsx
	 * // Standard collapse breakpoint
	 * <Dashboard collapseWidth={ 640 } />
	 *
	 * // More aggressive responsive behavior
	 * <Dashboard collapseWidth={ 768 } />
	 * ```
	 */
	collapseWidth?: number;

	/**
	 * Height of each widget row in pixels, or 'auto' to size by content.
	 *
	 * @default 200
	 * @example
	 * ```tsx
	 * // Taller widgets for chart-heavy dashboards
	 * <Dashboard rowHeight={ 280 } />
	 *
	 * // Auto-size widgets to their content
	 * <Dashboard rowHeight="auto" />
	 * ```
	 */
	rowHeight?: GridProps[ 'rowHeight' ];
}
