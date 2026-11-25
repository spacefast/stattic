import type { Attachment, Post } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import type { Field, DataViewRenderFieldProps } from '@zero-admin/dataviews';
import './style.scss';

function FeaturedMixed( { item }: DataViewRenderFieldProps< Post > ) {
	const mediaId = item.featured_media;

	const media = useSelect(
		( select ) => {
			const { getEntityRecord } = select( coreStore );
			return mediaId
				? getEntityRecord< Attachment >(
						'postType',
						'attachment',
						mediaId
				  )
				: null;
		},
		[ mediaId ]
	);
	const url = media?.source_url;

	if ( url ) {
		return (
			<img
				className="fields-controls__featured-image-image"
				src={ url }
				alt=""
			/>
		);
	}

	return (
		<div
			className="dataviews-media-field__featured-mixed"
			dangerouslySetInnerHTML={ {
				__html:
					typeof item.excerpt === 'string'
						? item.excerpt
						: item.excerpt?.rendered ?? '',
			} }
		/>
	);
}

export const fieldExtensions: Partial< Field< Post > > = {
	render: FeaturedMixed,
};
