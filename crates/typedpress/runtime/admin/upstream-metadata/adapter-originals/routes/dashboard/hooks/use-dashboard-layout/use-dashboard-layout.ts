import { useDispatch, useSelect } from '@wordpress/data';
import { store as preferencesStore } from '@wordpress/preferences';
import { store as tpAdminStore, type StoreSelectors } from '@typedpress-admin/boot';
import type { DashboardWidget } from '@typedpress-admin/dashboard';

/**
 * Hook for managing dashboard layout preferences.
 *
 * @return Array containing current layout and setter function
 */
function useDashboardLayout(): [
	DashboardWidget< unknown >[],
	( layout: DashboardWidget< unknown >[] ) => void,
	() => void,
] {
	const layout = useSelect(
		( select ) =>
			// eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- preferences store returns `unknown`
			( select( preferencesStore ).get(
				'core/admin',
				'dashboardLayout'
			) as DashboardWidget< unknown >[] | undefined ) ?? [],
		[]
	);

	const { set } = useDispatch( preferencesStore );

	function setLayout( newLayout: DashboardWidget< unknown >[] ) {
		void set( 'core/admin', 'dashboardLayout', newLayout );
	}

	const defaultLayout = useSelect(
		( select ) =>
			// eslint-disable-next-line @typescript-eslint/no-unsafe-return -- StoreSelectors cast
			(
				select( tpAdminStore ) as StoreSelectors
			 ).getDashboardDefaultLayout(),
		[]
	);

	function resetLayout() {
		void set( 'core/admin', 'dashboardLayout', defaultLayout );
	}

	return [ layout, setLayout, resetLayout ];
}

export default useDashboardLayout;
