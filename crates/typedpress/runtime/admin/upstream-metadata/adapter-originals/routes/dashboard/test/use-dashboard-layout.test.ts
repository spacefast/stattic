import { renderHook } from '@testing-library/react';
import { useSelect, useDispatch } from '@wordpress/data';
import type { DashboardWidget } from '@typedpress-admin/dashboard';
import { useDashboardLayout } from '../hooks';

jest.mock( '@wordpress/data' );
jest.mock( '@typedpress-admin/boot', () => ( {
	store: 'core/typedpress-admin',
} ) );

jest.mock( '@wordpress/preferences', () => ( {
	store: 'core/preferences',
} ) );

type MockStoresProps = {
	layout?: DashboardWidget< unknown >[];
	defaultLayout?: DashboardWidget< unknown >[];
};

/**
 * Helper to mock useSelect for both preferences and boot store.
 *
 * The hook calls useSelect twice:
 * 1. preferences store → select().get( 'core/admin', 'dashboardLayout' )
 * 2. boot store → select().getDashboardDefaultLayout()
 *
 * We return an object with both methods so each call resolves correctly.
 * @param {MockStoresProps} props - The object to mock
 */
function mockStores( { layout, defaultLayout = [] }: MockStoresProps ) {
	( useSelect as jest.Mock ).mockImplementation( ( selector ) => {
		return selector( () => ( {
			get: () => layout,
			getDashboardDefaultLayout: () => defaultLayout,
		} ) );
	} );
}

describe( 'useDashboardLayout', () => {
	const mockSet = jest.fn();

	beforeEach( () => {
		jest.clearAllMocks();
		( useDispatch as jest.Mock ).mockReturnValue( { set: mockSet } );
	} );

	it( 'returns empty array when preference is undefined', () => {
		mockStores( { layout: undefined } );

		const { result } = renderHook( () => useDashboardLayout() );

		expect( result.current[ 0 ] ).toEqual( [] );
	} );

	it( 'returns stored layout when preference exists', () => {
		const storedLayout = [
			{ uid: 'widget-1', type: 'core/test-widget' },
			{ uid: 'widget-2', type: 'core/test-widget' },
		];

		mockStores( { layout: storedLayout } );

		const { result } = renderHook( () => useDashboardLayout() );

		expect( result.current[ 0 ] ).toEqual( storedLayout );
	} );

	it( 'provides setter and reset functions', () => {
		mockStores( { layout: [] } );

		const { result } = renderHook( () => useDashboardLayout() );

		expect( typeof result.current[ 1 ] ).toBe( 'function' );
		expect( typeof result.current[ 2 ] ).toBe( 'function' );
	} );

	it( 'calls preferences store set method when setting layout', () => {
		mockStores( { layout: [] } );

		const { result } = renderHook( () => useDashboardLayout() );

		const newLayout = [ { uid: 'widget-3', type: 'core/test-widget' } ];

		result.current[ 1 ]( newLayout );

		expect( mockSet ).toHaveBeenCalledWith(
			'core/admin',
			'dashboardLayout',
			newLayout
		);
	} );

	it( 'resets layout to default widgets from boot store', () => {
		const defaultWidgets = [
			{ uid: 'default-1', type: 'core/default-widget' },
			{ uid: 'default-2', type: 'core/default-widget' },
		];

		mockStores( {
			layout: [ { uid: 'custom-1', type: 'core/custom' } ],
			defaultLayout: defaultWidgets,
		} );

		const { result } = renderHook( () => useDashboardLayout() );

		result.current[ 2 ]();

		expect( mockSet ).toHaveBeenCalledWith(
			'core/admin',
			'dashboardLayout',
			defaultWidgets
		);
	} );

	it( 'resets to empty array when no defaults are registered', () => {
		mockStores( { layout: [ { uid: 'widget-1', type: 'core/test' } ] } );

		const { result } = renderHook( () => useDashboardLayout() );

		result.current[ 2 ]();

		expect( mockSet ).toHaveBeenCalledWith(
			'core/admin',
			'dashboardLayout',
			[]
		);
	} );
} );
