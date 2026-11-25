import { notFound } from '@wordpress/route';
import { preloadFieldCollections } from '@first-admin/core-data-next';
import { unlock } from '@first-admin/admin-toolkit';
import { store as coreStore } from '@wordpress/core-data';
import { dispatch, resolveSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { MediaEditor } from './media-editor';

export const route = {
	canvas: async ( { params }: { params: { id: string } } ) => {
		const { unregisterEntityAction } = unlock( dispatch( editorStore ) );
		await unregisterEntityAction(
			'postType',
			'attachment',
			'duplicate-post'
		);
		await unregisterEntityAction(
			'postType',
			'attachment',
			'move-to-trash'
		);

		return {
			postType: 'attachment',
			postId: params.id,
			component: MediaEditor,
		};
	},
	loader: async () => {
		await preloadFieldCollections( 'postType', 'attachment' );
	},
	beforeLoad: async () => {
		try {
			const postTypeObject =
				await resolveSelect( coreStore ).getPostType( 'attachment' );

			if ( ! postTypeObject?.visibility?.show_ui ) {
				throw notFound();
			}

			const { registerPostTypeSchema } = unlock(
				dispatch( editorStore )
			);

			// Make sure that the post type schema has loaded before `canvas`, so that
			// the actions can be unregistered _after_ the entity is set to `isReady`.
			await registerPostTypeSchema( 'attachment' );
		} catch {
			throw notFound();
		}
	},
};
