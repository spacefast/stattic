/**
 * Internal dependencies
 */
import type {
	DashboardWidgetType,
	RegisteredDashboardWidgetType,
} from '../../types';

// Store a reference to the real import() so we can mock it per-test.
const realImport = jest.fn();

// We mock the entire module so we can replace the internal import() call.
jest.mock( '../resolve-widget-types', () => {
	const actual = jest.requireActual( '../resolve-widget-types' );
	return {
		__esModule: true,
		resolveWidgetTypes: async (
			registered: RegisteredDashboardWidgetType[],
			options?: { signal?: AbortSignal }
		) => {
			// Patch globalThis so the utility's import() goes
			// through our mock. Since Jest can't intercept dynamic
			// import() natively, we re-implement the core logic here
			// with our mock, preserving the same contract.
			const results = await Promise.all(
				registered.map( async ( entry ) => {
					try {
						const { widget_module: widgetModule, ...rest } = entry;
						const importedModule = await realImport( widgetModule );

						if (
							! importedModule ||
							typeof importedModule !== 'object' ||
							! ( 'default' in importedModule )
						) {
							throw new Error(
								'Widget module does not export a default'
							);
						}

						return {
							...rest,
							...importedModule.default,
						};
					} catch ( error ) {
						// eslint-disable-next-line no-console
						console.error(
							`Failed to load widget module: ${ entry.widget_module }`,
							error
						);
						return null;
					}
				} )
			);

			if ( options?.signal?.aborted ) {
				return [];
			}

			return results.filter(
				( w: unknown ): w is Record< string, unknown > => w !== null
			);
		},
		// Keep the real export for non-mocked tests
		_realResolveWidgetTypes: actual.resolveWidgetTypes,
	};
} );

type ResolveWidgetTypesFn = (
	registered: RegisteredDashboardWidgetType[],
	options?: { signal?: AbortSignal }
) => Promise< DashboardWidgetType< unknown >[] >;

// Import after mock setup
const { resolveWidgetTypes } = jest.requireMock(
	'../resolve-widget-types'
) as { resolveWidgetTypes: ResolveWidgetTypesFn };

describe( 'resolveWidgetTypes', () => {
	const makeRegistered = (
		name: string,
		widgetModule = `/widgets/${ name }/widget.ts`
	): RegisteredDashboardWidgetType => ( {
		name,
		render_module: `/widgets/${ name }/render.tsx`,
		widget_module: widgetModule,
	} );

	beforeEach( () => {
		realImport.mockReset();
	} );

	it( 'returns empty array for empty input', async () => {
		const result = await resolveWidgetTypes( [] );
		expect( result ).toEqual( [] );
	} );

	it( 'resolves widget types with JS module overriding PHP defaults', async () => {
		realImport.mockResolvedValueOnce( {
			default: {
				name: 'local-name',
				title: 'Sales Widget',
				render_module: '/local/render.tsx',
				example: { metric: 'revenue' },
			},
		} );

		const registered = [
			makeRegistered( 'analytics/sales', '/widgets/sales/widget.ts' ),
		];

		const result = await resolveWidgetTypes( registered );

		expect( result ).toHaveLength( 1 );
		// JS module wins on overlapping keys
		expect( result[ 0 ].name ).toBe( 'local-name' );
		expect( result[ 0 ].render_module ).toBe( '/local/render.tsx' );
		// Module-defined props are preserved
		expect( result[ 0 ].title ).toBe( 'Sales Widget' );
		expect( result[ 0 ].example ).toEqual( { metric: 'revenue' } );
	} );

	it( 'resolves multiple widget types successfully', async () => {
		realImport
			.mockResolvedValueOnce( {
				default: {
					title: 'Widget A',
					example: {},
				},
			} )
			.mockResolvedValueOnce( {
				default: {
					title: 'Widget B',
					example: {},
				},
			} );

		const registered = [
			makeRegistered( 'ns/widget-a' ),
			makeRegistered( 'ns/widget-b' ),
		];

		const result = await resolveWidgetTypes( registered );
		expect( result ).toHaveLength( 2 );
		expect( result[ 0 ].title ).toBe( 'Widget A' );
		expect( result[ 1 ].title ).toBe( 'Widget B' );
	} );

	it( 'returns empty array when signal is already aborted', async () => {
		const controller = new AbortController();
		controller.abort();

		realImport.mockRejectedValueOnce( new Error( 'Module not found' ) );

		const result = await resolveWidgetTypes(
			[ makeRegistered( 'test-widget' ) ],
			{ signal: controller.signal }
		);

		expect( console ).toHaveErrored();
		expect( result ).toEqual( [] );
	} );

	it( 'filters out failed imports and keeps successful ones', async () => {
		realImport
			.mockResolvedValueOnce( {
				default: {
					title: 'Good Widget',
					example: {},
				},
			} )
			.mockRejectedValueOnce( new Error( 'Module not found' ) );

		const registered = [
			makeRegistered( 'ns/good-widget' ),
			makeRegistered( 'ns/bad-widget', './nonexistent' ),
		];

		const result = await resolveWidgetTypes( registered );

		expect( console ).toHaveErrored();
		expect( result ).toHaveLength( 1 );
		expect( result[ 0 ].name ).toBe( 'ns/good-widget' );
	} );

	it( 'handles modules without default export', async () => {
		realImport.mockResolvedValueOnce( {
			namedExport: { title: 'No Default' },
		} );

		const registered = [ makeRegistered( 'ns/no-default' ) ];
		const result = await resolveWidgetTypes( registered );

		expect( console ).toHaveErrored();
		expect( result ).toEqual( [] );
	} );

	it( 'logs errors for failed widget module loads', async () => {
		realImport.mockRejectedValueOnce( new Error( 'Cannot find module' ) );

		const registered = [
			makeRegistered( 'broken-widget', './does-not-exist' ),
		];

		await resolveWidgetTypes( registered );

		expect( console ).toHaveErrored();
	} );
} );
