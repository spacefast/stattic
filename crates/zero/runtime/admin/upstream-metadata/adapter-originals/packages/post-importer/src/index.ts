import { store as coreStore } from '@wordpress/core-data';
import { store as noticesStore } from '@wordpress/notices';
// @ts-expect-error No types for blocks
import { pasteHandler, serialize } from '@wordpress/blocks';
import { __, sprintf, _n } from '@wordpress/i18n';

export default async function importPosts(
	registry: any,
	htmlOrFiles: string | File[]
): Promise< void > {
	const coreDispatch = registry.dispatch( coreStore );

	// Handle HTML content
	if ( typeof htmlOrFiles === 'string' ) {
		let content = pasteHandler( { HTML: htmlOrFiles, mode: 'BLOCKS' } );
		let title = '';

		if ( Array.isArray( content ) ) {
			const [ firstBlock ] = content;

			if ( firstBlock.name === 'core/heading' ) {
				title = firstBlock.attributes.content;
				content = content.slice( 1 );
			}
			content = serialize( content );
		}

		await coreDispatch.saveEntityRecord( 'postType', 'post', {
			title,
			content,
		} );
		return;
	}

	const noticesDispatch = registry.dispatch( noticesStore );

	// Handle file drops
	if ( Array.isArray( htmlOrFiles ) ) {
		const files = htmlOrFiles as File[];
		const mdFiles = files.filter( ( file ) =>
			file.name.toLowerCase().endsWith( '.md' )
		);

		if ( mdFiles.length === 0 ) {
			noticesDispatch.createErrorNotice(
				__( 'Please drop Markdown (.md) files only.', 'zero-admin' ),
				{ type: 'snackbar' }
			);
			return;
		}

		let importedCount = 0;
		const totalFiles = mdFiles.length;

		for ( let i = 0; i < mdFiles.length; i++ ) {
			const file = mdFiles[ i ];
			const percentage = Math.round( ( ( i + 1 ) / totalFiles ) * 100 );

			try {
				noticesDispatch.createInfoNotice(
					sprintf(
						// translators: %1$d: percentage, %2$d: current file number, %3$d: total files, %4$s: file name
						__(
							'[%1$d%% - %2$d/%3$d] Importing "%4$s"',
							'zero-admin'
						),
						percentage,
						i + 1,
						totalFiles,
						file.name
					),
					{
						type: 'snackbar',
						id: 'md-import-progress',
						isDismissible: false,
					}
				);

				const text = await file.text();
				const { title, content } = parseMDFile( text );

				// Convert Markdown content to blocks using pasteHandler
				let processedContent = pasteHandler( {
					plainText: content,
					mode: 'BLOCKS',
				} );

				// Serialize blocks to HTML content for WordPress
				if ( Array.isArray( processedContent ) ) {
					processedContent = serialize( processedContent );
				}

				await coreDispatch.saveEntityRecord( 'postType', 'post', {
					title: { raw: title },
					content: { raw: processedContent },
					status: 'draft',
				} );

				importedCount++;
			} catch ( error ) {
				const errorMessage =
					error instanceof Error ? error.message : 'Unknown error';
				noticesDispatch.createErrorNotice(
					sprintf(
						// translators: %1$s: file name, %2$s: error message
						__( 'Failed to import "%1$s": %2$s', 'zero-admin' ),
						file.name,
						errorMessage
					),
					{ type: 'snackbar' }
				);
			}
		}

		// Clear progress notice and show completion notice
		noticesDispatch.removeNotice( 'md-import-progress' );

		if ( importedCount > 0 ) {
			noticesDispatch.createSuccessNotice(
				sprintf(
					// translators: %1$d: number of imported posts
					_n(
						'Imported %1$d post from Markdown files',
						'Imported %1$d posts from Markdown files',
						importedCount,
						'zero-admin'
					),
					importedCount
				),
				{
					type: 'snackbar',
					isDismissible: true,
				}
			);
		}
	}
}

function parseMDFile( mdContent: string ): { title: string; content: string } {
	const lines = mdContent.split( '\n' );
	let title = '';
	let content = '';
	let foundTitle = false;

	for ( let i = 0; i < lines.length; i++ ) {
		const line = lines[ i ];

		// Look for first heading (starts with #)
		if ( ! foundTitle && line.match( /^#+\s+/ ) ) {
			title = line.replace( /^#+\s+/, '' ).trim();
			foundTitle = true;
			continue;
		}

		// Everything else goes to content
		if ( foundTitle || ! line.match( /^#+\s+/ ) ) {
			content += line + '\n';
		}
	}

	return {
		title: title || 'Untitled',
		content: content.trim(),
	};
}
