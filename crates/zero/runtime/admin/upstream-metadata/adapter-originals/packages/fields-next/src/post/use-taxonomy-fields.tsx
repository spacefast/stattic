import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import type { Post, Taxonomy, Term } from '@wordpress/core-data';
import type { Field } from '@zero-admin/dataviews';
import { TaxonomyTerms } from './taxonomy-terms';

/**
 * Hook that generates taxonomy fields for a given post type.
 *
 * Creates dynamic fields that show taxonomy terms (like categories, tags)
 * associated with posts. Each field displays readable term names instead of IDs.
 *
 * @param postType - The post type to generate taxonomy fields for
 * @return Array of taxonomy field configurations for DataViews
 */
export function useTaxonomyFields( postType: string ): Field< Post >[] {
	const taxonomies = useSelect(
		( select ) => {
			const { getTaxonomies, getPostType } = select( coreStore );
			const postTypeObj = getPostType( postType );

			// Only fetch taxonomies if the post type actually has them to avoid failing requests
			return ( postTypeObj?.taxonomies?.length ?? 0 ) > 0
				? getTaxonomies( { type: postType } )
				: [];
		},
		[ postType ]
	);

	// We load and organize all terms for each taxonomy in a single request
	// to avoid multiple requests and recalculations for each taxonomy field.
	const taxonomyTermMaps: Map< string, Map< number, Term > > = useSelect(
		( select ) => {
			const { getEntityRecords } = select( coreStore );
			const maps = new Map< string, Map< number, Term > >();

			for ( const taxonomy of taxonomies || [] ) {
				const allTerms = getEntityRecords< Term >(
					'taxonomy',
					taxonomy.slug,
					{
						per_page: -1,
					}
				);
				maps.set(
					taxonomy.slug,
					new Map(
						( allTerms || [] ).map( ( term: Term ) => [
							term.id,
							term,
						] )
					)
				);
			}

			return maps;
		},
		[ taxonomies ]
	);

	if ( ! taxonomies?.length ) {
		return [];
	}

	return taxonomies.map( ( taxonomy: Taxonomy ) => {
		const getTermIds = ( item: Post ): number[] => {
			const termIds = item[ taxonomy.rest_base as keyof Post ];
			return Array.isArray( termIds ) ? termIds : [];
		};

		return {
			id: taxonomy.slug,
			label: taxonomy.name,
			type: 'text',
			enableSorting: false,
			filterBy: false,
			render: ( { item }: { item: Post } ) => {
				const termMap = taxonomyTermMaps.get( taxonomy.slug )!;
				const termNames = getTermIds( item )
					.map( ( termId ) => termMap.get( termId )?.name )
					.filter( Boolean ) as string[];

				return <TaxonomyTerms termNames={ termNames } />;
			},
		};
	} );
}
