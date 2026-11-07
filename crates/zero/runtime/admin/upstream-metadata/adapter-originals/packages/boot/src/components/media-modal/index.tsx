import type { ReactNode } from 'react';
import importAttachment from '@zero-admin/attachment-importer';
import { addFilter } from '@wordpress/hooks';
import { Modal, Button, FormFileUpload } from '@wordpress/components';
import { useState, useMemo } from '@wordpress/element';
import { transformAttachment as originalTransformAttachment } from '@wordpress/media-utils';
import type { Attachment, RestAttachment } from '@wordpress/media-utils';
import { __ } from '@wordpress/i18n';
import { upload } from '@wordpress/icons';
import { useRegistry } from '@wordpress/data';
import PostPicker from '../post-picker';
import './style.scss';

type MediaKind = 'image' | 'video' | 'audio' | 'application';

type AttachmentWithTypeOverride = Omit< Attachment, 'type' > & {
	type: Attachment[ 'media_type' ];
};

const transformAttachment = (
	attachment: RestAttachment
): AttachmentWithTypeOverride => {
	// Use the original transformAttachment to do the heavy lifting.
	const transformed = originalTransformAttachment( attachment );

	// Set `type` from `media_type` instead of `type`.
	// The REST API always returns `type` as `attachment` for media.
	// However, the block editor checks `type` to validate media for
	// blocks, such as when adding images to a gallery block.
	// Ideally, this should be handled in `@wordpress/media-utils`
	// or within the image and gallery blocks themselves.
	return {
		...transformed,
		type: attachment.media_type,
	};
};

type MediaModalProps = {
	/**
	 * Renders the media modal.
	 *
	 * Consumers define a `render` callback prop that generally renders the button that
	 * opens the media library.
	 *
	 * It receives an `open` param, which is a function that opens the modal.
	 *
	 * @param {Object}   props      - The props object.
	 * @param {Function} props.open - The function to open the modal.
	 *
	 * @return {ReactNode} The rendered media modal.
	 */
	render: ( { open }: { open: () => void } ) => ReactNode;

	/**
	 * A callback function that's called when the selection changes.
	 *
	 * @param {Attachment | Attachment[]} selection - The selected media entities.
	 */
	onSelect: (
		selection:
			| Attachment
			| Attachment[]
			| AttachmentWithTypeOverride
			| AttachmentWithTypeOverride[]
	) => void;

	/**
	 * Whether the media modal should allow multiple selections.
	 *
	 * @default false
	 */
	multiple?: boolean;

	/**
	 * The allowed mime types.
	 *
	 * @default 'image'
	 */
	allowedTypes?: MediaKind[];

	/**
	 * The id of any existing selected media, or an array of ids if multiple is supported..
	 *
	 * For example, if a block already has an media added to it and the
	 * user opens the media modal to replace the media, the value should
	 * be the id of the existing media.
	 *
	 * @default undefined
	 */
	value?: string | string[];
};

function MediaModal( {
	render,
	onSelect,
	allowedTypes,
	multiple,
	value,
}: MediaModalProps ) {
	const [ isOpen, setIsOpen ] = useState( false );
	const registry = useRegistry();

	const currentSelection = useMemo( () => {
		if ( ! value ) {
			return [];
		}

		if ( Array.isArray( value ) ) {
			return value.map( ( id ) => id.toString() );
		}

		return [ value.toString() ];
	}, [ value ] );

	return (
		<>
			{ render( { open: () => setIsOpen( true ) } ) }
			{ isOpen && (
				<Modal
					size="fill"
					className="zero-admin-media-modal"
					title={ __( 'Media Library', 'zero-admin' ) }
					onRequestClose={ () => setIsOpen( false ) }
					headerActions={
						<FormFileUpload
							accept={
								allowedTypes
									? allowedTypes
											.map( ( type ) => `${ type }/*` )
											.join( ',' )
									: '*'
							}
							onChange={ (
								event: React.ChangeEvent< HTMLInputElement >
							) => {
								const files = event.target.files;
								if ( files ) {
									importAttachment(
										registry,
										Array.from( files )
									);
								}
							} }
							multiple={ true }
							render={ ( { openFileDialog } ) => (
								<Button
									icon={ upload }
									onClick={ openFileDialog }
								>
									{ __( 'Upload', 'zero-admin' ) }
								</Button>
							) }
						/>
					}
				>
					<PostPicker
						currentSelection={ currentSelection }
						onSelect={ ( items ) => {
							if ( Array.isArray( items ) ) {
								onSelect(
									items.map( ( item ) =>
										transformAttachment(
											// TODO: Improve this type.
											// `transformAttachment` should ideally work with core-data types (e.g. `Post`).
											// @ts-expect-error - This likely requires a PR to the `@wordpress/media-utils` package.
											item as RestAttachment
										)
									)
								);
							} else {
								onSelect(
									transformAttachment(
										// TODO: Improve this type.
										// `transformAttachment` should ideally work with core-data types (e.g. `Post`).
										// @ts-expect-error - This likely requires a PR to the `@wordpress/media-utils` package.
										items as RestAttachment
									)
								);
							}
							setIsOpen( false );
						} }
						multiple={ multiple }
						postType="attachment"
						queryParams={ {
							media_type: allowedTypes,
						} }
						itemListLabel={ __( 'Media list', 'zero-admin' ) }
					/>
				</Modal>
			) }
		</>
	);
}

addFilter(
	'editor.MediaUpload',
	'core/editor/components/media-upload',
	() => MediaModal,
	10
);
