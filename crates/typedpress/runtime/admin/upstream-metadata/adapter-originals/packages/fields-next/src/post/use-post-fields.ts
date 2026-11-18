import { unlock } from '@zero-admin/admin-toolkit';
import { useFieldCollections } from '@zero-admin/core-data-next';
import type { Field } from '@zero-admin/dataviews';
import { useMemo } from '@wordpress/element';
import { privateApis as editorPrivateApis } from '@wordpress/editor';
import type { Post } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';
import { useTaxonomyFields } from './use-taxonomy-fields';

// Unlock WordPress private APIs
const { usePostFields: useCorePostFields } = unlock( editorPrivateApis );

/*
 * This needs to be ported to wordpress/fields,
 * but we can't until automattic/dataviews is also ported.
 * In the meantime, we keep the changes locally.
 */
export function usePostFields( { postType }: { postType: string } ) {
	const coreFields = useCorePostFields( { postType } );
	const taxonomyFields = useTaxonomyFields( postType );
	const fieldCollections = useFieldCollections( 'postType', postType );
	const postTypeObject = useSelect(
		( select ) => select( coreStore ).getPostType( postType ),
		[ postType ]
	);

	const fields = useMemo( () => {
		if ( ! coreFields || ! coreFields.length || ! postTypeObject ) {
			return [];
		}

		// Ideally this logic should be moved to the field definition.
		const fieldsVisibility: Record< string, () => boolean > = {
			comment_count: () =>
				! coreFields.some(
					( field: Field< Post > ) => field.id === 'comment_count'
				) && !! postTypeObject.supports.comments,
			year: () =>
				coreFields.some(
					( field: Field< Post > ) => field.id === 'date'
				),
			'content-preview': () =>
				!! postTypeObject.supports.editor || postType === 'wp_template',
			excerpt: () => !! postTypeObject.supports.excerpt,
			'featured-mixed': () => !! postTypeObject.supports.excerpt,
		};

		const fieldCollectionsIds = new Set(
			fieldCollections.map( ( f ) => f.id )
		);

		return [
			...coreFields
				.map( ( rawField: Field< Post > ) => {
					// Avoid including fields that are already included in a field collection.
					if ( fieldCollectionsIds.has( rawField.id ) ) {
						return null;
					}
					// Create a copy of the field to avoid mutating the original field.
					const field = { ...rawField };
					// These are overrides to Gutenberg core fields.
					// We should ideally move all these fields to the Fields collections API.
					if (
						[
							// post & page post type
							'password',
							'slug',
							'template',
							'title',
							'date',
							// page post type
							'parent',
						].includes( field.id )
					) {
						field.filterBy = false;
					}
					if ( field.id === 'author' ) {
						field.filterBy = {
							...field.filterBy,
							operators: [ 'is', 'isNot' ],
						};
					}
					if ( field.id === 'comment_status' ) {
						field.filterBy = {
							...field.filterBy,
							operators: [ 'is', 'isNot' ],
						};
					}
					if ( postType === 'attachment' && field.id === 'status' ) {
						field.filterBy = false;
					}
					return field;
				} )
				.filter( Boolean ), // Remove null values
			...( postType === 'wp_template'
				? [ { id: 'description', type: 'text' } ]
				: [] ),
			...taxonomyFields,
			...fieldCollections.filter( ( field ) => {
				if ( fieldsVisibility[ field.id ] ) {
					return fieldsVisibility[ field.id ]();
				}
				return true;
			} ),
		];
	}, [
		coreFields,
		postType,
		postTypeObject,
		taxonomyFields,
		fieldCollections,
	] );

	return {
		isLoading: ! postTypeObject || ! coreFields,
		fields,
	};
}
