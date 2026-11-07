import { useFieldCollections } from '@zero-admin/core-data-next';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { useMemo } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import type { Term } from '@wordpress/core-data';
import type { Field } from '@zero-admin/dataviews';

const fieldDescriptions: Record< string, string > = {
	name: 'name_field_description',
	description: 'desc_field_description',
	slug: 'slug_field_description',
};

/**
 * Hook that returns taxonomy term fields for DataViews.
 *
 * @param taxonomy Taxonomy slug
 * @return Object with fields array and loading state
 */
export function useTaxonomyTermFields( taxonomy: string ): {
	fields: Field< Term >[];
	isLoading: boolean;
} {
	const taxonomyObject = useSelect(
		( select ) => select( coreStore ).getTaxonomy( taxonomy ),
		[ taxonomy ]
	);
	const fieldCollections = useFieldCollections( 'taxonomy', taxonomy );

	const fields: Field< Term >[] = useMemo( () => {
		return fieldCollections.map( ( field ) => {
			// Use field's description if specified, otherwise fall back to taxonomy label.
			const labelKey = fieldDescriptions[ field.id ];
			let description: string | undefined;
			if ( 'description' in field ) {
				description = field.description;
			} else if ( labelKey ) {
				description = decodeEntities(
					taxonomyObject?.labels?.[ labelKey ] ?? ''
				);
			}

			return {
				...field,
				description,
			};
		} );
	}, [ fieldCollections, taxonomyObject ] );

	return {
		fields,
		isLoading: false,
	};
}
