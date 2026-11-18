import { decodeEntities } from '@wordpress/html-entities';
import type { Term } from '@wordpress/core-data';
import { TextareaControl } from '@zero-admin/design-system';
import type { Field } from '@zero-admin/dataviews';

const decodeDescription = ( description?: string | null ) =>
	description ? decodeEntities( description ) : '';

export const fieldExtensions: Partial< Field< Term > > = {
	getValue: ( { item } ) => decodeDescription( item.description ),
	render: ( { item } ) =>
		item.description ? decodeEntities( item.description ) : undefined,
	Edit: ( { data, field, onChange } ) => (
		<TextareaControl
			label={ field.label }
			value={ decodeDescription( data.description ) }
			onValueChange={ ( value ) => onChange( { description: value } ) }
			rows={ 5 }
			description={ field.description }
		/>
	),
};
