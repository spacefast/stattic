import { isBlobURL } from '@wordpress/blob';
import { store as coreStore } from '@wordpress/core-data';
import { store as noticesStore } from '@wordpress/notices';
import { uploadMedia } from '@wordpress/media-utils';
import { __, sprintf, _n, _x } from '@wordpress/i18n';

export default async function importAttachment(
	registry: any,
	htmlOrFiles: string | File[]
): Promise< void > {
	const noticesDispatch = registry.dispatch( noticesStore );

	// HTML content - unsupported currently.
	// TODO - it might be nice to try extracting media from selected and
	// dragged html content.
	if ( typeof htmlOrFiles === 'string' ) {
		noticesDispatch.createErrorNotice(
			__( 'Please drop media files only.', 'zero-admin' ),
			{ type: 'snackbar' }
		);
		return;
	}

	// Handle file drops
	if ( Array.isArray( htmlOrFiles ) ) {
		const files = htmlOrFiles as File[];
		const file: File | undefined = files[ 0 ];

		let uploadingText: string = __( 'Uploading file…', 'zero-admin' );

		if ( files.length > 1 ) {
			uploadingText = sprintf(
				// translators: %d: number of files (number).
				__( 'Uploading %d files…', 'zero-admin' ),
				files.length
			);
		} else if ( file.name ) {
			uploadingText = sprintf(
				// translators: %s: filename.
				_x( 'Uploading %s…', 'filename', 'zero-admin' ),
				file.name
			);
		}

		noticesDispatch.createInfoNotice( uploadingText, {
			type: 'snackbar',
			id: 'media-upload-notice',
		} );

		const coreDispatch = registry.dispatch( coreStore );

		uploadMedia( {
			filesList: files,
			onFileChange: ( changedFiles ): void => {
				const media = changedFiles[ 0 ];
				if ( isBlobURL( media?.url ) ) {
					// Skip if media is a temporary blob URL.
					return;
				}

				if ( files.length === 1 ) {
					noticesDispatch.createSuccessNotice(
						media?.file || media?.filename
							? sprintf(
									// translators: 1: filename.
									__( '%1$s uploaded', 'zero-admin' ),
									( media?.filename || media?.file ) as string
							  )
							: __( 'File uploaded', 'zero-admin' ),
						{
							type: 'snackbar',
							id: 'media-upload-notice',
						}
					);
				} else {
					noticesDispatch.createSuccessNotice(
						sprintf(
							// translators: 1: number of uploaded files (number), 2: total number of files to upload (number).
							__( 'Uploaded (%1$d/%2$d) files', 'zero-admin' ),
							changedFiles.length,
							files.length
						),
						{
							type: 'snackbar',
							id: 'media-upload-notice',
						}
					);
				}

				const entityFiles = changedFiles.filter(
					( _file ) => _file?.id
				);
				if ( entityFiles?.length ) {
					const invalidateCache = true;

					coreDispatch.receiveEntityRecords(
						'postType',
						'attachment',
						entityFiles,
						undefined,
						invalidateCache
					);
				}
			},
			onError( message ) {
				noticesDispatch.createErrorNotice( message.toString(), {
					type: 'snackbar',
					id: 'media-upload-notice',
				} );
			},
		} );
	}
}
