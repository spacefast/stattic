import { SiteIconBackButton, unlock } from '@zero-admin/admin-toolkit';
import { IconButton } from '@wordpress/ui';
import { __ } from '@wordpress/i18n';
import { useDispatch, useSelect } from '@wordpress/data';
import { privateApis as editorPrivateApis } from '@wordpress/editor';
import { store as coreStore, useEntityId } from '@wordpress/core-data';
import { useViewportMatch } from '@wordpress/compose';
import { crop as cropIcon } from '@wordpress/icons';
import MediaEditorSaveButton from '../save-button';
import DocumentBar from '../document-bar';
import { getMediaTypeFromMimeType } from '../../utils';
import type { MediaItemUpdatable } from '../../types';
import { useMediaEditorState } from '../../provider/with-media-editor-state-provider';
import { MEDIA_EDITOR_SIDEBAR } from '../sidebar';

const { PinnedItems, interfaceStore } = unlock( editorPrivateApis );

export default function MediaEditorHeader() {
	const postId = useEntityId( 'postType', 'attachment' );
	const isMobileViewport = useViewportMatch( 'medium', '<' );
	const { isImageEditorOpen, setIsImageEditorOpen } = useMediaEditorState();
	const post = useSelect(
		( select ) =>
			select( coreStore ).getEditedEntityRecord(
				'postType',
				'attachment',
				postId
			),
		[ postId ]
	) as MediaItemUpdatable;
	const { disableComplementaryArea } = useDispatch( interfaceStore );

	if ( ! post ) {
		return null;
	}

	const mediaType = getMediaTypeFromMimeType( post?.mime_type || '' );

	return (
		<div className="zero-admin-media-details__header-container">
			<div className="zero-admin-media-details__header-container-back-button">
				<SiteIconBackButton to="/types/attachment/list" />
			</div>
			<div className="zero-admin-media-details__header-container-toolbar">
				{ isMobileViewport && (
					<IconButton
						variant="minimal"
						tone="neutral"
						size="compact"
						icon={ cropIcon }
						label={ __( 'Crop', 'zero-admin' ) }
						onClick={ () => {
							// If we're not already in the image editor,
							// ensure the sidebar is closed. This makes it easier
							// to focus on the image editing experience.
							if ( ! isImageEditorOpen ) {
								disableComplementaryArea(
									MEDIA_EDITOR_SIDEBAR
								);
							}
							setIsImageEditorOpen( ! isImageEditorOpen );
						} }
						aria-expanded={ isImageEditorOpen }
						aria-pressed={ isImageEditorOpen }
						aria-controls="zero-admin-media-editor-toolbar"
					/>
				) }
			</div>
			<div className="zero-admin-media-details__header-container-center">
				{ ! isMobileViewport && (
					<DocumentBar
						title={ post?.title }
						icon={ mediaType.icon }
					/>
				) }
			</div>
			<div className="zero-admin-media-details__header-container-actions">
				<PinnedItems.Slot scope="core/edit-media" />
				<MediaEditorSaveButton postId={ postId } />
			</div>
		</div>
	);
}
