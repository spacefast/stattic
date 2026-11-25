import { useMemo } from '@wordpress/element';
// @ts-expect-error - No type declarations available for @wordpress/block-editor
import { BlockEditorProvider } from '@wordpress/block-editor';
// @ts-expect-error - No type declarations available for @wordpress/blocks
import { createBlock } from '@wordpress/blocks';
import './style.scss';
import NavigationMenuContent from './content';

const noop = () => {};

export default function NavigationMenuEditor( { id }: { id: number } ) {
	const blocks = useMemo( () => {
		if ( ! id ) {
			return [];
		}

		return [ createBlock( 'core/navigation', { ref: id } ) ];
	}, [ id ] );

	if ( ! id || ! blocks ) {
		return null;
	}

	return (
		<BlockEditorProvider
			settings={ {} }
			value={ blocks }
			onChange={ noop }
			onInput={ noop }
		>
			<NavigationMenuContent rootClientId={ blocks[ 0 ].clientId } />
		</BlockEditorProvider>
	);
}
