import {
	Suspense,
	useMemo,
	useEffect,
	useRef,
	lazy,
	type ComponentType,
} from 'react';
import { doAction } from '@wordpress/hooks';
import { WidgetLoadingOverlay } from '../widget-loading-overlay';
import { useDashboardWidgetContext } from '../widget-context';
import type {
	DashboardWidgetType,
	DashboardWidget,
	DashboardWidgetBadge,
	WidgetRenderProps,
	WidgetErrorConfig,
} from '../../types';

type WidgetComponentType = ComponentType< WidgetRenderProps< unknown > >;

interface WidgetModule {
	default: WidgetComponentType;
}

function isValidWidgetModule( module: unknown ): module is WidgetModule {
	return (
		typeof module === 'object' &&
		module !== null &&
		'default' in module &&
		typeof ( module as { default: unknown } ).default === 'function'
	);
}

/**
 * Emits a `dashboard.widgetRendered` action via `@wordpress/hooks`
 * when the widget render module resolves and mounts.
 *
 * Placed inside the Suspense boundary so it only mounts
 * after the lazy component has loaded. Consumers subscribe
 * with `addAction( 'dashboard.widgetRendered', 'namespace', cb )`.
 *
 * The action receives the widget instance: `{ uid, name, position }`.
 */
function WidgetRenderedAction() {
	const widget = useDashboardWidgetContext();
	const hasFired = useRef( false );

	useEffect( () => {
		if ( ! widget || hasFired.current ) {
			return;
		}

		hasFired.current = true;
		// eslint-disable-next-line @typescript-eslint/no-unsafe-assignment, @typescript-eslint/no-unsafe-call -- @wordpress/hooks is untyped
		doAction( 'dashboard.widgetRendered', widget );
	}, [ widget ] );

	return null;
}

export default function WidgetRender( {
	widget,
	widgetType,
	onRemove,
	setBadge,
	setError,
}: {
	widget: DashboardWidget< unknown >;
	widgetType: DashboardWidgetType< unknown >;
	onRemove?: () => void;
	setBadge?: ( badge: string | number | DashboardWidgetBadge | null ) => void;
	setError?: ( error: WidgetErrorConfig | true | null ) => void;
} ) {
	const WidgetComponent = useMemo(
		() =>
			lazy( async () => {
				const module: unknown = await import(
					widgetType.render_module
				);
				if ( ! isValidWidgetModule( module ) ) {
					throw new Error(
						`Invalid widget module: ${ widgetType.render_module }`
					);
				}
				return module;
			} ),
		[ widgetType.render_module ]
	);
	return (
		<Suspense fallback={ <WidgetLoadingOverlay /> }>
			<WidgetComponent
				attributes={ widget.attributes }
				onRemove={ onRemove }
				setBadge={ setBadge }
				setError={ setError }
			/>

			<WidgetRenderedAction />
		</Suspense>
	);
}
