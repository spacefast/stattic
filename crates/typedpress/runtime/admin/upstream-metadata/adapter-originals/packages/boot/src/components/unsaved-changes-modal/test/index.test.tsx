import { fireEvent, render, screen, waitFor } from '@testing-library/react';
const mockCreateErrorNotice = jest.fn();
const mockClearEntityRecordEdits = jest.fn();
const mockDoAction = jest.fn();
const mockGetNoticeFromSettledResults = jest.fn();
const mockIsSavingEntityRecord = jest.fn();
const mockSaveDirtyEntitiesSettled = jest.fn();
const mockSelect = jest.fn();
const mockUseBlocker = jest.fn();
const mockUseEntityValidity = jest.fn();
const mockUseRegistry = jest.fn();
const mockUseRouter = jest.fn();

let dirtyEntityRecords: Array< {
	kind: string;
	name: string;
	key: string | number;
} > = [];

jest.mock( '@zero-admin/admin-toolkit', () => ( {
	getNoticeFromSettledResults: (
		...args: unknown[]
	): ReturnType< typeof mockGetNoticeFromSettledResults > =>
		mockGetNoticeFromSettledResults( ...args ),
	saveDirtyEntitiesSettled: (
		...args: unknown[]
	): ReturnType< typeof mockSaveDirtyEntitiesSettled > =>
		mockSaveDirtyEntitiesSettled( ...args ),
	unlock: jest.fn( () => ( {
		useBlocker: mockUseBlocker,
		useRouter: mockUseRouter,
	} ) ),
	useEntityValidity: () => mockUseEntityValidity(),
} ) );

jest.mock( '@wordpress/core-data', () => ( {
	store: 'core',
} ) );

jest.mock( '@wordpress/data', () => ( {
	useDispatch: jest.fn( () => ( {
		clearEntityRecordEdits: mockClearEntityRecordEdits,
		createErrorNotice: mockCreateErrorNotice,
	} ) ),
	useRegistry: jest.fn( () => mockUseRegistry() ),
	useSelect: jest.fn( ( callback: ( select: unknown ) => unknown ) =>
		callback( mockSelect )
	),
} ) );

jest.mock( '@wordpress/hooks', () => ( {
	doAction: ( ...args: unknown[] ) => mockDoAction( ...args ),
} ) );

jest.mock( '@wordpress/i18n', () => ( {
	__: ( message: string ) => message,
} ) );

jest.mock( '@wordpress/notices', () => ( {
	store: 'notices',
} ) );

jest.mock( '@wordpress/route', () => ( {
	privateApis: {},
} ) );

describe( 'UnsavedChangesModal', () => {
	beforeEach( () => {
		dirtyEntityRecords = [
			{
				kind: 'root',
				name: 'booking',
				key: -1,
			},
		];
		mockClearEntityRecordEdits.mockReset();
		mockCreateErrorNotice.mockReset();
		mockDoAction.mockReset();
		mockGetNoticeFromSettledResults.mockReset();
		mockGetNoticeFromSettledResults.mockReturnValue( {
			kind: 'error',
			message: 'Failed to save changes.',
		} );
		mockIsSavingEntityRecord.mockReset();
		mockIsSavingEntityRecord.mockReturnValue( false );
		mockSaveDirtyEntitiesSettled.mockReset();
		mockSelect.mockReset();
		mockSelect.mockImplementation( () => ( {
			__experimentalGetDirtyEntityRecords: () => dirtyEntityRecords,
			isSavingEntityRecord: mockIsSavingEntityRecord,
		} ) );
		mockUseBlocker.mockReset();
		mockUseBlocker.mockReturnValue( {
			status: 'blocked',
			proceed: jest.fn(),
			reset: jest.fn(),
		} );
		mockUseEntityValidity.mockReset();
		mockUseEntityValidity.mockReturnValue( {
			isEntityValid: true,
		} );
		mockUseRegistry.mockReset();
		mockUseRegistry.mockReturnValue( {
			select: mockSelect,
			dispatch: jest.fn(),
		} );
		mockUseRouter.mockReset();
		mockUseRouter.mockReturnValue( {
			subscribe: jest.fn( () => jest.fn() ),
		} );
	} );

	it( 'shows pending state immediately and prevents re-entry while save is running', async () => {
		// Load after mocks because the module reads route APIs at import time.
		// eslint-disable-next-line @typescript-eslint/no-require-imports
		const UnsavedChangesModal = require( '../index' ).default;
		const blocker = {
			status: 'blocked',
			proceed: jest.fn(),
			reset: jest.fn(),
		};
		let resolveSave: (
			value: PromiseSettledResult< unknown >[]
		) => void = () => undefined;

		mockUseBlocker.mockReturnValue( blocker );
		mockSaveDirtyEntitiesSettled.mockImplementation(
			() =>
				new Promise(
					(
						resolve: (
							value: PromiseSettledResult< unknown >[]
						) => void
					) => {
						resolveSave = resolve;
					}
				)
		);

		render( <UnsavedChangesModal /> );

		const saveButton = screen.getByRole( 'button', {
			name: 'Save changes',
		} );
		const discardButton = screen.getByRole( 'button', {
			name: 'Discard changes',
		} );

		fireEvent.click( saveButton );

		await waitFor( () => {
			expect( saveButton ).toHaveAttribute( 'aria-disabled', 'true' );
		} );

		expect( discardButton ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( mockSaveDirtyEntitiesSettled ).toHaveBeenCalledTimes( 1 );

		fireEvent.click( saveButton );

		expect( mockSaveDirtyEntitiesSettled ).toHaveBeenCalledTimes( 1 );

		resolveSave( [
			{
				status: 'fulfilled',
				value: { id: 401 },
			},
		] );

		await waitFor( () => {
			expect( blocker.proceed ).toHaveBeenCalledTimes( 1 );
		} );
	} );
} );
