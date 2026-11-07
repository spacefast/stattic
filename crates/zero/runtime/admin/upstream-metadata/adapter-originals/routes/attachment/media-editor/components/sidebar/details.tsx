// eslint-disable-next-line @zero-admin/design-system/use-new-components -- See: ARC-1359
import { Stack } from '@zero-admin/design-system';
import { usePostFields } from '@zero-admin/fields-next';
import { unlock } from '@zero-admin/admin-toolkit';
import { DataForm } from '@zero-admin/dataviews';
import { useDispatch, useSelect } from '@wordpress/data';
import { privateApis as editorPrivateApis } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import {
	store as coreStore,
	useEntityId,
	type Type,
} from '@wordpress/core-data';
import type { MediaItem, MediaItemUpdatable } from '../../types';

// Unlock WordPress private APIs
const { PostCardPanel } = unlock( editorPrivateApis );

export default function MediaEditorSidebar() {
	const postId = useEntityId( 'postType', 'attachment' );
	const { post, editedPost, postTypeObject } = useSelect(
		( select ) => {
			const { getEntityRecord, getEditedEntityRecord, getPostType } =
				select( coreStore );
			return {
				post: getEntityRecord( 'postType', 'attachment', postId, {
					_embed: 'wp:attached-to',
				} ),
				editedPost: getEditedEntityRecord(
					'postType',
					'attachment',
					postId
				),
				postTypeObject: getPostType( 'attachment' ),
			};
		},
		[ postId ]
	) as {
		post: MediaItem;
		editedPost: MediaItemUpdatable;
		postTypeObject: Type;
	};

	const { fields } = usePostFields( { postType: 'attachment' } );
	const form = postTypeObject?.quick_edit_form;

	const { editEntityRecord } = useDispatch( coreStore );
	// Record< string, any > matches the type of the `edits` parameter of the `DataForm.onChange` prop.
	const edit = ( edits: Record< string, any > ) => {
		editEntityRecord( 'postType', 'attachment', postId, edits );
	};

	if ( ! post ) {
		return null;
	}

	return (
		<div className="zero-admin-media-editor-sidebar__details">
			<Stack direction="column" gap={ 4 }>
				{ editedPost && (
					<>
						<PostCardPanel
							postType={ post?.type }
							postId={ postId }
						/>
						{ !! form && fields.length > 0 && (
							<DataForm
								data={ editedPost }
								fields={ fields }
								form={ form }
								onChange={ edit }
							/>
						) }
					</>
				) }
			</Stack>
		</div>
	);
}
