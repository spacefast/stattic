import { __ } from '@wordpress/i18n';

export default function FrontEndPreview( { url }: { url: string } ) {
	// Add na_site_preview parameter to hide admin bar
	const previewUrl = new URL( url, window.location.origin );
	previewUrl.searchParams.set( 'na_site_preview', '1' );

	return (
		<iframe
			src={ previewUrl.toString() }
			title={ __( 'Site Preview', 'zero-admin' ) }
			style={ {
				display: 'block',
				border: 'none',
				width: '100%',
				height: '100%',
				backgroundColor: '#fff',
			} }
		/>
	);
}
