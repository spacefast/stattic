import clsx from 'clsx';
// eslint-disable-next-line @zero-admin/design-system/use-new-components -- See: ARC-1359
import { Stack } from '@zero-admin/design-system';
import { useSelect } from '@wordpress/data';
import { store as coreStore, useEntityId } from '@wordpress/core-data';
import MediaRenderer from '../media-renderer';
import { useMediaEditorState } from '../../provider/with-media-editor-state-provider';
import type { MediaItem } from '../../types';

export default function MediaEditorCanvas() {
	/* eslint-disable @typescript-eslint/no-unsafe-assignment, @typescript-eslint/no-unsafe-argument */
	const postId = useEntityId( 'postType', 'attachment' );
	const post = useSelect< MediaItem | undefined >(
		( select ) =>
			select( coreStore ).getEntityRecord(
				'postType',
				'attachment',
				postId,
				{ _embed: 'author,wp:attached-to' }
			),
		[ postId ]
	);
	/* eslint-enable @typescript-eslint/no-unsafe-assignment, @typescript-eslint/no-unsafe-argument */
	const { isImageEditorOpen } = useMediaEditorState();
	if ( ! post ) {
		return null;
	}

	return (
		<Stack
			className={ clsx( 'zero-admin-media-details__content-container', {
				'zero-admin-media-details__content-container--cropper':
					isImageEditorOpen,
			} ) }
			direction="column"
			justify="center"
			align="center"
			gap={ 2 }
		>
			<MediaRenderer post={ post } />
		</Stack>
	);
}
