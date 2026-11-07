import { TextareaControl } from '@zero-admin/design-system';
import type { User } from '@wordpress/core-data';
import type { Field } from '@zero-admin/dataviews';

export const fieldExtensions: Partial< Field< User > > = {
	getValue: ( { item } ) => item.description || '',
	Edit: ( { data, field, onChange } ) => {
		return (
			<TextareaControl
				id={ field.id }
				label={ field.label }
				value={ data.description }
				description={ field.description }
				onValueChange={ ( value ) => {
					onChange( { description: value } );
				} }
			/>
		);
	},
};
