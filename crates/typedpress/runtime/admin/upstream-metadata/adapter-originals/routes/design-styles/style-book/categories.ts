// @ts-expect-error - WordPress blocks module doesn't have proper types
import { getCategories } from '@wordpress/blocks';
import type { Example, Category, CategoryExamples } from './types';
import {
	STYLE_BOOK_CATEGORIES,
	STYLE_BOOK_THEME_SUBCATEGORIES,
} from './constants';

/**
 * Returns category examples for a given category definition and list of examples.
 * @param {Category}  categoryDefinition The category definition.
 * @param {Example[]} examples           An array of block examples.
 * @return {CategoryExamples} An object containing the category examples.
 */
export function getExamplesByCategory(
	categoryDefinition: Category,
	examples: Example[]
): CategoryExamples | undefined {
	if ( ! categoryDefinition?.slug || ! examples?.length ) {
		return undefined;
	}

	const categories: Category[] = categoryDefinition?.subcategories ?? [];
	if ( categories.length ) {
		const subcategoryResults = categories
			.map( ( subcategoryDefinition ) => {
				return getExamplesByCategory( subcategoryDefinition, examples );
			} )
			.filter(
				( result ): result is CategoryExamples => result !== undefined
			);

		return {
			title: categoryDefinition.title,
			slug: categoryDefinition.slug,
			subcategories:
				subcategoryResults.length > 0 ? subcategoryResults : undefined,
		};
	}

	const blocksToInclude = categoryDefinition?.blocks || [];
	const blocksToExclude = categoryDefinition?.exclude || [];
	const categoryExamples = examples.filter( ( example ) => {
		return (
			! blocksToExclude.includes( example.name ) &&
			( example.category === categoryDefinition.slug ||
				blocksToInclude.includes( example.name ) )
		);
	} );

	if ( ! categoryExamples.length ) {
		return undefined;
	}

	return {
		title: categoryDefinition.title,
		slug: categoryDefinition.slug,
		examples: categoryExamples,
	};
}

/**
 * Returns category examples for a given category definition and list of examples.
 *
 * @return {Category[]} An array of top-level category definitions.
 */
export function getTopLevelStyleBookCategories(): Category[] {
	const reservedCategories = [
		...STYLE_BOOK_THEME_SUBCATEGORIES,
		...STYLE_BOOK_CATEGORIES,
	].map( ( { slug } ) => slug );
	const extraCategories: Category[] = getCategories();
	const extraCategoriesFiltered = extraCategories.filter(
		( { slug } ) => ! reservedCategories.includes( slug )
	);
	return [ ...STYLE_BOOK_CATEGORIES, ...extraCategoriesFiltered ];
}
