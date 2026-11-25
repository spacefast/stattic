import type { DashboardWidgetType } from '@zero-admin/dashboard';
import { __ } from '@wordpress/i18n';

type RecentPostsWidgetType = { numberOfPosts: number; showExcerpts: boolean };

const widget: Omit<
	DashboardWidgetType< RecentPostsWidgetType >,
	'render_module'
> = {
	name: 'core/recent-posts',
	title: __( 'Recent Posts', 'zero-admin' ),
	attributes: [
		{
			id: 'numberOfPosts',
			type: 'integer',
			label: __( 'Number of Posts', 'zero-admin' ),
		},
		{
			id: 'showExcerpts',
			type: 'boolean',
			label: __( 'Show Excerpts', 'zero-admin' ),
		},
	],
	example: {
		numberOfPosts: 5,
		showExcerpts: true,
	},
};

export default widget;
