import type { Post } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';
import type { Field, DataViewRenderFieldProps } from '@zero-admin/dataviews';

function ExcerptField( { item }: DataViewRenderFieldProps< Post > ) {
	const excerpt =
		typeof item.excerpt === 'string'
			? item.excerpt
			: item.excerpt?.rendered ?? '';

	if ( ! excerpt ) {
		return <span className="excerpt-field__empty">—</span>;
	}

	return (
		<div
			className="excerpt-field__content"
			dangerouslySetInnerHTML={ {
				__html: excerpt,
			} }
		/>
	);
}

export const fieldExtensions: Partial< Field< Post > > = {
	render: ExcerptField,
};
