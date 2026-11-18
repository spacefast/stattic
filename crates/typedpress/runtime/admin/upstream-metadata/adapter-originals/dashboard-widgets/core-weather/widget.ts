import type { DashboardWidgetType } from '@zero-admin/dashboard';
import { __ } from '@wordpress/i18n';

type WeatherWidgetType = {
	location: string;
	unit: 'celsius' | 'fahrenheit';
};

const widget: Omit<
	DashboardWidgetType< WeatherWidgetType >,
	'render_module'
> = {
	name: 'core/weather',
	title: __( 'Weather', 'zero-admin' ),
	attributes: [
		{
			id: 'location',
			type: 'text',
			label: __( 'Location', 'zero-admin' ),
		},
		{
			id: 'unit',
			type: 'text',
			label: __( 'Temperature Unit', 'zero-admin' ),
			elements: [
				{ value: 'celsius', label: __( 'Celsius', 'zero-admin' ) },
				{
					value: 'fahrenheit',
					label: __( 'Fahrenheit', 'zero-admin' ),
				},
			],
		},
	],
	example: {
		location: 'New York',
		unit: 'celsius',
	},
};

export default widget;
