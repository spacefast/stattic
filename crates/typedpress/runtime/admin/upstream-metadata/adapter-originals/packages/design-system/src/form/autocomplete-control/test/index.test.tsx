import { createRef } from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AutocompleteControl } from '../index';

describe( 'AutocompleteControl', () => {
	const mockItems = [
		{ label: 'Option 1', value: 'option1' },
		{ label: 'Option 2', value: 'option2' },
		{ label: 'Option 3', value: 'option3' },
	];

	it( 'forwards ref', async () => {
		const user = userEvent.setup();
		const ref = createRef< HTMLInputElement >();
		const itemRef = createRef< HTMLDivElement >();

		render(
			<AutocompleteControl ref={ ref } label="Search" items={ mockItems }>
				{ ( item ) => (
					<AutocompleteControl.Item
						key={ item.value }
						ref={ item.value === 'option1' ? itemRef : undefined }
						value={ item }
					>
						{ item.label }
					</AutocompleteControl.Item>
				) }
			</AutocompleteControl>
		);

		expect( ref.current ).toBeInstanceOf( HTMLInputElement );

		await user.type( screen.getByRole( 'combobox' ), 'Option' );

		await waitFor( () => {
			expect( itemRef.current ).toBeInstanceOf( HTMLDivElement );
		} );
	} );

	it( 'renders accessible label and description', () => {
		render(
			<AutocompleteControl
				label="Search"
				description="Search for items"
				items={ mockItems }
			/>
		);

		expect(
			screen.getByRole( 'combobox', {
				name: 'Search',
				description: 'Search for items',
			} )
		).toBeVisible();
	} );

	it( 'renders placeholder text', () => {
		render(
			<AutocompleteControl
				label="Search"
				items={ mockItems }
				placeholder="Type to search"
			/>
		);

		expect( screen.getByPlaceholderText( 'Type to search' ) ).toBeVisible();
	} );

	// See https://github.com/mui/base-ui/issues/4245
	it.skip( 'should not clear the input value on Escape', async () => {
		const user = userEvent.setup();

		render( <AutocompleteControl label="Search" items={ mockItems } /> );

		const input = screen.getByRole( 'combobox' );

		await user.type( input, 'Option' );
		expect( input ).toHaveValue( 'Option' );

		await user.keyboard( '{Escape}{Escape}' );
		expect( input ).toHaveValue( 'Option' );
	} );
} );
