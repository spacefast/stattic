// eslint-disable-next-line @zero-admin/design-system/use-new-components -- See: ARC-1359
import { SelectControl, Field } from '@zero-admin/design-system';
import type { User } from '@wordpress/core-data';
import type { Field as DataViewField } from '@zero-admin/dataviews';
import { useMemo } from '@wordpress/element';
import { sanitizeHtml } from '../../../routes/plugin-list/components/plugin-detail-modal/plugin-detail-utils';
import './style.scss';

interface RoleElement {
	label: string;
	value: string;
	description?: string;
}

interface ExtendedRoleField extends DataViewField< User > {
	elements?: RoleElement[];
	defaultValue?: string;
	disabled?: boolean;
}

export const fieldExtensions: Partial< DataViewField< User > > = {
	render: ( { item, field } ) => {
		// eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- Extended field properties from PHP
		const extendedField = field as ExtendedRoleField;

		if ( Array.isArray( item.roles ) ) {
			if ( item.roles.length === 0 ) {
				return '—';
			}
			return item.roles
				.map( ( role: string ) => {
					// Look up the label from field options/elements
					const element = extendedField.elements?.find(
						( el ) => el.value === role
					);
					if ( element?.label ) {
						return element.label;
					}
					// Fallback: capitalize and replace underscores
					return (
						role.charAt( 0 ).toUpperCase() +
						role.slice( 1 ).replace( '_', ' ' )
					);
				} )
				.join( ', ' );
		}
		return item.roles;
	},
	Edit: ( { field, onChange, data } ) => {
		// Extended field properties are added by the PHP field collection filter.
		// eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- Extended field properties from PHP
		const extendedField = field as ExtendedRoleField;

		// Extract the first role from the roles array
		const currentRole = Array.isArray( data?.roles )
			? data.roles[ 0 ]
			: data?.roles;
		const roleValue = currentRole ?? extendedField.defaultValue ?? '';

		// Get dynamic description based on selected role from elements
		const description = useMemo( () => {
			const element = extendedField.elements?.find(
				( el ) => el.value === roleValue
			);
			return element?.description ?? field.description;
		}, [ roleValue, extendedField.elements, field.description ] );

		// Guard against undefined data during initial render
		if ( ! data ) {
			return null;
		}

		return (
			<Field.Root>
				<SelectControl
					label={ field.label }
					value={ roleValue }
					disabled={ extendedField.disabled }
					items={ extendedField.elements }
					onValueChange={ ( value ) => {
						if ( value !== null ) {
							onChange( { roles: [ value ] } );
						}
					} }
				/>
				{ description && (
					<Field.Details
						className="roles-field-details"
						dangerouslySetInnerHTML={ {
							__html: sanitizeHtml( description ),
						} }
					/>
				) }
			</Field.Root>
		);
	},
};
