import { setNudges } from '../actions';
import reducer from '../reducer';
import type { State } from '../reducer';
import { getNudges, getCurrentNudge, getNudgeByType } from '../selectors';
import type { Nudge } from '../types';

describe( 'Nudges Store', () => {
	describe( 'Actions', () => {
		describe( 'setNudges', () => {
			it( 'should create SET_NUDGES action with nudges array', () => {
				const nudges: Nudge[] = [
					{
						id: 'test-1',
						type: 'trial_countdown',
						severity: 'warning',
						title: 'Trial expires soon',
					},
					{
						id: 'test-2',
						type: 'billing',
						severity: 'urgent',
						title: 'Payment required',
					},
				];

				const action = setNudges( nudges );

				expect( action ).toEqual( {
					type: 'SET_NUDGES',
					nudges,
				} );
			} );

			it( 'should create SET_NUDGES action with empty array', () => {
				const action = setNudges( [] );

				expect( action ).toEqual( {
					type: 'SET_NUDGES',
					nudges: [],
				} );
			} );
		} );
	} );

	describe( 'Reducer', () => {
		it( 'should return default empty array for nudges', () => {
			const state = reducer( undefined, { type: 'INIT' } );

			expect( state.nudges ).toEqual( [] );
		} );

		it( 'should handle SET_NUDGES action', () => {
			const nudges: Nudge[] = [
				{
					id: 'test-1',
					type: 'trial_countdown',
					severity: 'warning',
					title: 'Test nudge',
				},
			];

			const state = reducer( undefined, setNudges( nudges ) );

			expect( state.nudges ).toEqual( nudges );
		} );

		it( 'should replace existing nudges with SET_NUDGES', () => {
			const initialNudges: Nudge[] = [
				{
					id: 'old-1',
					type: 'old_type',
					severity: 'info',
					title: 'Old nudge',
				},
			];

			const newNudges: Nudge[] = [
				{
					id: 'new-1',
					type: 'new_type',
					severity: 'warning',
					title: 'New nudge',
				},
				{
					id: 'new-2',
					type: 'another_type',
					severity: 'urgent',
					title: 'Another nudge',
				},
			];

			let state = reducer( undefined, setNudges( initialNudges ) );
			state = reducer( state, setNudges( newNudges ) );

			expect( state.nudges ).toEqual( newNudges );
			expect( state.nudges ).not.toContain( initialNudges[ 0 ] );
		} );

		it( 'should handle SET_NUDGES with empty array', () => {
			const initialNudges: Nudge[] = [
				{
					id: 'test-1',
					type: 'test',
					severity: 'info',
					title: 'Test',
				},
			];

			let state = reducer( undefined, setNudges( initialNudges ) );
			state = reducer( state, setNudges( [] ) );

			expect( state.nudges ).toEqual( [] );
		} );
	} );

	describe( 'Selectors', () => {
		describe( 'getNudges', () => {
			it( 'should return all nudges from state', () => {
				const nudges: Nudge[] = [
					{
						id: 'test-1',
						type: 'trial_countdown',
						severity: 'warning',
						title: 'Test 1',
					},
					{
						id: 'test-2',
						type: 'billing',
						severity: 'urgent',
						title: 'Test 2',
					},
				];

				const state: State = {
					routes: [],
					menuItems: [],
					metaMenuItems: [],
					dashboardWidgetTypes: [],
					contextEntries: [],
					nudges,
				};

				expect( getNudges( state ) ).toEqual( nudges );
			} );

			it( 'should return empty array when no nudges', () => {
				const state: State = {
					routes: [],
					menuItems: [],
					metaMenuItems: [],
					dashboardWidgetTypes: [],
					contextEntries: [],
					nudges: [],
				};

				expect( getNudges( state ) ).toEqual( [] );
			} );
		} );

		describe( 'getCurrentNudge', () => {
			it( 'should return first nudge as current nudge', () => {
				const nudges: Nudge[] = [
					{
						id: 'first',
						type: 'trial_countdown',
						severity: 'warning',
						title: 'First nudge',
					},
					{
						id: 'second',
						type: 'billing',
						severity: 'info',
						title: 'Second nudge',
					},
				];

				const state: State = {
					routes: [],
					menuItems: [],
					metaMenuItems: [],
					dashboardWidgetTypes: [],
					contextEntries: [],
					nudges,
				};

				const current = getCurrentNudge( state );
				expect( current ).toEqual( nudges[ 0 ] );
				expect( current?.id ).toBe( 'first' );
			} );

			it( 'should return null when nudges array is empty', () => {
				const state: State = {
					routes: [],
					menuItems: [],
					metaMenuItems: [],
					dashboardWidgetTypes: [],
					contextEntries: [],
					nudges: [],
				};

				expect( getCurrentNudge( state ) ).toBeNull();
			} );

			it( 'should return only first nudge even if multiple exist', () => {
				const nudges: Nudge[] = [
					{
						id: 'priority-1',
						type: 'urgent',
						severity: 'urgent',
						title: 'Highest priority',
					},
					{
						id: 'priority-2',
						type: 'warning',
						severity: 'warning',
						title: 'Second priority',
					},
					{
						id: 'priority-3',
						type: 'info',
						severity: 'info',
						title: 'Third priority',
					},
				];

				const state: State = {
					routes: [],
					menuItems: [],
					metaMenuItems: [],
					dashboardWidgetTypes: [],
					contextEntries: [],
					nudges,
				};

				const current = getCurrentNudge( state );
				expect( current ).toEqual( nudges[ 0 ] );
				expect( current?.id ).toBe( 'priority-1' );
			} );
		} );

		describe( 'getNudgeByType', () => {
			it( 'should return nudge matching specified type', () => {
				const nudges: Nudge[] = [
					{
						id: 'nudge-1',
						type: 'trial_countdown',
						severity: 'warning',
						title: 'Trial expires',
					},
					{
						id: 'nudge-2',
						type: 'billing',
						severity: 'urgent',
						title: 'Payment needed',
					},
					{
						id: 'nudge-3',
						type: 'domain',
						severity: 'info',
						title: 'Domain info',
					},
				];

				const state: State = {
					routes: [],
					menuItems: [],
					metaMenuItems: [],
					dashboardWidgetTypes: [],
					contextEntries: [],
					nudges,
				};

				const billingNudge = getNudgeByType( state, 'billing' );
				expect( billingNudge ).toEqual( nudges[ 1 ] );
				expect( billingNudge?.id ).toBe( 'nudge-2' );
			} );

			it( 'should return first match when multiple nudges have same type', () => {
				const nudges: Nudge[] = [
					{
						id: 'billing-1',
						type: 'billing',
						severity: 'warning',
						title: 'First billing nudge',
					},
					{
						id: 'billing-2',
						type: 'billing',
						severity: 'urgent',
						title: 'Second billing nudge',
					},
				];

				const state: State = {
					routes: [],
					menuItems: [],
					metaMenuItems: [],
					dashboardWidgetTypes: [],
					contextEntries: [],
					nudges,
				};

				const billingNudge = getNudgeByType( state, 'billing' );
				expect( billingNudge ).toEqual( nudges[ 0 ] );
				expect( billingNudge?.id ).toBe( 'billing-1' );
			} );

			it( 'should return null when type is not found', () => {
				const nudges: Nudge[] = [
					{
						id: 'nudge-1',
						type: 'trial_countdown',
						severity: 'warning',
						title: 'Test',
					},
				];

				const state: State = {
					routes: [],
					menuItems: [],
					metaMenuItems: [],
					dashboardWidgetTypes: [],
					contextEntries: [],
					nudges,
				};

				expect( getNudgeByType( state, 'nonexistent' ) ).toBeNull();
			} );

			it( 'should return null when nudges array is empty', () => {
				const state: State = {
					routes: [],
					menuItems: [],
					metaMenuItems: [],
					dashboardWidgetTypes: [],
					contextEntries: [],
					nudges: [],
				};

				expect( getNudgeByType( state, 'any_type' ) ).toBeNull();
			} );
		} );
	} );
} );
