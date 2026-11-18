import type { Meta, StoryObj } from '@storybook/react-vite';
import { ThemeProvider } from '@first-admin/theme';
import { Button } from '@wordpress/ui';
import { Tooltip } from '..';

const meta: Meta< typeof Tooltip.Root > = {
	title: 'Design System/Tooltip',
	component: Tooltip.Root,
	subcomponents: {
		'Tooltip.Provider': Tooltip.Provider,
		'Tooltip.Trigger': Tooltip.Trigger,
		'Tooltip.Popup': Tooltip.Popup,
	},
};
export default meta;

export const Default: StoryObj< typeof Tooltip.Root > = {
	args: {
		children: (
			<>
				<Tooltip.Trigger>Hover me</Tooltip.Trigger>
				<Tooltip.Popup>Tooltip text</Tooltip.Popup>
			</>
		),
	},
};

/**
 * The `disabled` prop prevents the tooltip from showing, and can be used to
 * show the tooltip conditionally without rendering the underlying react
 * component conditionally (which could cause reconciliation issues).
 */
export const Disabled: StoryObj< typeof Tooltip.Root > = {
	...Default,
	args: {
		...Default.args,
		disabled: true,
	},
};

export const ThemeInheritance: StoryObj< typeof Tooltip.Root > = {
	args: {
		children: (
			<>
				<Tooltip.Trigger
					render={ <Button>Hover this magenta accent button</Button> }
				/>
				<Tooltip.Popup align="center" side="top">
					Don&apos;t put buttons in a tooltip, but{ ' ' }
					<Button>this should also be magenta</Button>
				</Tooltip.Popup>
			</>
		),
	},
	decorators: [
		( Story ) => (
			<ThemeProvider color={ { primary: 'magenta' } }>
				<Story />
			</ThemeProvider>
		),
	],
};
