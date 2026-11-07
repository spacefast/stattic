import type { DashboardWidgetType } from '@zero-admin/dashboard';
import { __ } from '@wordpress/i18n';

type QuickStatsWidgetType = {
	timeRange: 'today' | 'week' | 'month';
};

const widget: Omit<
	DashboardWidgetType< QuickStatsWidgetType >,
	'render_module'
> = {
	name: 'core/quick-stats',
	title: __( 'Quick Stats', 'zero-admin' ),
	info: __(
		'This widget displays key metrics and statistics for the current time range.',
		'zero-admin'
	),
	attributes: [
		{
			id: 'timeRange',
			type: 'text',
			label: __( 'Time Range', 'zero-admin' ),
			elements: [
				{ value: 'today', label: __( 'Today', 'zero-admin' ) },
				{ value: 'week', label: __( 'This Week', 'zero-admin' ) },
				{ value: 'month', label: __( 'This Month', 'zero-admin' ) },
			],
		},
	],
	example: {
		timeRange: 'week',
	},
};

export default widget;
