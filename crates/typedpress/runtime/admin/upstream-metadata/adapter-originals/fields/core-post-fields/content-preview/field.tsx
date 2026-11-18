import { withEditor, withStylesId } from '@zero-admin/lazy-editor';
import type { Field, DataViewRenderFieldProps } from '@zero-admin/dataviews';
import { __ } from '@wordpress/i18n';
import { useMemo } from '@wordpress/element';
// @ts-expect-error - @wordpress/blocks is not fully typed
import { parse } from '@wordpress/blocks';
// @ts-expect-error - @wordpress/block-editor is not fully typed
import { BlockPreview } from '@wordpress/block-editor';
import { EditorProvider } from '@wordpress/editor';
import './style.scss';
import type { PostWithContent } from '../types';

function ContentPreviewField( {
	item,
	settings,
}: DataViewRenderFieldProps< PostWithContent > & {
	settings: Record< string, unknown >;
} ) {
	const blocks = useMemo( () => {
		if ( item.blocks ) {
			return item.blocks;
		}
		// Handle both raw content and rendered content
		let content = '';
		if ( typeof item.content === 'string' ) {
			content = item.content;
		} else if ( item.content && typeof item.content === 'object' ) {
			content =
				( item.content as any ).raw ||
				( item.content as any ).rendered ||
				'';
		}
		return parse( content );
	}, [ item.content, item.blocks ] );

	const isEmpty = ! blocks?.length;

	return (
		<EditorProvider post={ item } settings={ settings }>
			<div className="fields-content-preview">
				{ isEmpty && __( 'Empty content', 'zero-admin' ) }
				{ ! isEmpty && (
					<BlockPreview.Async>
						<BlockPreview blocks={ blocks } />
					</BlockPreview.Async>
				) }
			</div>
		</EditorProvider>
	);
}

const WrappedContentPreviewField = withStylesId(
	withEditor( ContentPreviewField )
);

export const fieldExtensions: Partial< Field< PostWithContent > > = {
	render: ( { item, ...props } ) => {
		return (
			<WrappedContentPreviewField
				item={ item }
				postId={ item.id.toString() }
				postType={ item.type }
				{ ...props }
			/>
		);
	},
};
