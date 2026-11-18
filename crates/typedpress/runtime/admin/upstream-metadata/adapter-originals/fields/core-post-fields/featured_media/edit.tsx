import { Button, __experimentalGrid as Grid } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useCallback, useRef } from '@wordpress/element';
// @ts-expect-error - WordPress private APIs
import { MediaUpload } from '@wordpress/block-editor';
import { lineSolid } from '@wordpress/icons';
import { store as coreStore } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';
import type { Attachment, Post } from '@wordpress/core-data';
import type { DataFormControlProps } from '@zero-admin/dataviews';

export const FeaturedImageEdit = ( {
	data,
	field,
	onChange,
}: DataFormControlProps< Post > ) => {
	const { id } = field;

	const value = field.getValue( { item: data } );

	const media = useSelect< Attachment | undefined >(
		( select ) => {
			const { getEntityRecord } = select( coreStore );
			return getEntityRecord( 'postType', 'attachment', value );
		},
		[ value ]
	);

	const onChangeControl = useCallback(
		( newValue: number ) =>
			onChange( {
				[ id ]: newValue,
			} ),
		[ id, onChange ]
	);

	const url = media?.source_url;
	const title = media?.title?.rendered;
	const ref = useRef( null );

	return (
		<fieldset className="fields-controls__featured-image">
			<div className="fields-controls__featured-image-container">
				<MediaUpload
					onSelect={ ( selectedMedia: { id: number } ) => {
						onChangeControl( selectedMedia.id );
					} }
					allowedTypes={ [ 'image' ] }
					render={ ( { open }: { open: () => void } ) => {
						return (
							<div
								ref={ ref }
								role="button"
								tabIndex={ -1 }
								onClick={ () => {
									open();
								} }
								onKeyDown={ open }
							>
								<Grid
									rowGap={ 0 }
									columnGap={ 8 }
									templateColumns="24px 1fr 24px"
								>
									{ url && (
										<>
											<img
												className="fields-controls__featured-image-image"
												alt=""
												width={ 24 }
												height={ 24 }
												src={ url }
											/>
											<span className="fields-controls__featured-image-title">
												{ title }
											</span>
										</>
									) }
									{ ! url && (
										<>
											<span
												className="fields-controls__featured-image-placeholder"
												style={ {
													width: '24px',
													height: '24px',
												} }
											/>
											<span className="fields-controls__featured-image-title">
												{ __(
													'Choose an image…',
													'zero-admin'
												) }
											</span>
										</>
									) }
									{ url && (
										<>
											<Button
												size="small"
												className="fields-controls__featured-image-remove-button"
												icon={ lineSolid }
												onClick={ (
													event: React.MouseEvent< HTMLButtonElement >
												) => {
													event.stopPropagation();
													onChangeControl( 0 );
												} }
											/>
										</>
									) }
								</Grid>
							</div>
						);
					} }
				/>
			</div>
		</fieldset>
	);
};
