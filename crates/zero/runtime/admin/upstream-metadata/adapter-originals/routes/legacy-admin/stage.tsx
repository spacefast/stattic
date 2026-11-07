import {
	useParams,
	useNavigate,
	privateApis as routePrivateApis,
} from '@wordpress/route';
import { useEffect, useRef, useState } from 'react';
import { unlock } from '@zero-admin/admin-toolkit';
import { useRefEffect, useMergeRefs } from '@wordpress/compose';

const { useRouter } = unlock( routePrivateApis );

function LegacyAdmin() {
	const { slug } = useParams( { from: '/legacy/$slug' } );
	const navigate = useNavigate();
	const router = useRouter();
	const { basepath } = router.options;

	// Construct the iframe URL - decode the slug and prepend the admin URL
	const decodedSlug = decodeURIComponent( slug );

	const iframeUrl = String(
		new URL( decodedSlug, window.location.origin + basepath )
	);
	const adminBase = iframeUrl.slice( 0, -decodedSlug.length );

	const [ iframeUrlState, setIframeUrlState ] = useState( iframeUrl );
	const iframeRef = useRef< HTMLIFrameElement >( null );

	// Avoid remounting the iframe content when the location is already correct.
	useEffect( () => {
		if ( iframeUrl !== iframeRef.current?.contentWindow?.location.href ) {
			setIframeUrlState( iframeUrl );
		}
	}, [ iframeUrl ] );

	const handleIframeLoad = (
		event: React.SyntheticEvent< HTMLIFrameElement >
	) => {
		const iframe = event.target as HTMLIFrameElement;
		if ( ! iframe || ! iframe.contentWindow ) {
			return;
		}

		const iframeWindow = iframe.contentWindow;

		function notifyParent( href: string ) {
			const match = (
				router.state.pendingMatches ?? router.state.matches
			).at( -1 );
			const currentSlug = match?.params.slug;

			if ( ! currentSlug ) {
				return;
			}

			const newSlug = href.slice( adminBase.length );

			// Only navigate if the slug actually changed
			if ( newSlug !== decodeURIComponent( currentSlug ) ) {
				navigate( {
					to: '/legacy/$slug',
					params: { slug: encodeURIComponent( newSlug ) },
				} );
			}
		}

		// Listen for browser navigation events
		iframeWindow.addEventListener( 'popstate', () => {
			notifyParent( iframeWindow.location.href );
		} );
		iframeWindow.addEventListener( 'hashchange', () => {
			notifyParent( iframeWindow.location.href );
		} );

		iframeWindow.addEventListener( 'click', ( e ) => {
			const target = e.target as HTMLElement;
			const link = target.closest( 'a' );
			if (
				link &&
				! link.target &&
				link.origin !== iframeWindow.location.origin
			) {
				link.setAttribute( 'target', '_parent' );
			}
		} );

		// Override pushState and replaceState to catch programmatic navigation
		const originalPushState = iframeWindow.history.pushState;
		const originalReplaceState = iframeWindow.history.replaceState;

		iframeWindow.history.pushState = function ( ...args ) {
			originalPushState.apply( iframeWindow.history, args );
			notifyParent( iframeWindow.location.href );
		};
		iframeWindow.history.replaceState = function ( ...args ) {
			originalReplaceState.apply( iframeWindow.history, args );
			notifyParent( iframeWindow.location.href );
		};

		// Notify on initial load in case of redirects.
		notifyParent( iframeWindow.location.href );
	};

	const iframeRefEffect = useRefEffect( ( iframe: HTMLIFrameElement ) => {
		// @ts-expect-error - Custom property added to iframe element
		iframe._zeroAdminLoad = () => {
			const iframeDoc = iframe.contentDocument;
			if ( iframeDoc ) {
				const style = iframeDoc.createElement( 'style' );
				style.textContent = `
						#adminmenumain { display: none !important; }
						#wpadminbar { display: none !important; }
						#wpfooter { display: none !important; }
						#wpcontent { margin-left: 0 !important; margin-top: 0 !important; }
						.folded #wpcontent { margin-left: 0 !important; }
						html.wp-toolbar { padding-top: 0 !important; }
						#wpbody-content { padding-bottom: 0 !important; }
					`;
				iframeDoc.head.appendChild( style );
			}
		};
	}, [] );

	return (
		<iframe
			ref={ useMergeRefs( [ iframeRef, iframeRefEffect ] ) }
			src={ iframeUrlState }
			onLoad={ handleIframeLoad }
			style={ {
				width: '100%',
				height: '100%',
				border: 'none',
				display: 'block',
				backgroundColor: 'white',
			} }
			title="Legacy Admin Page"
		/>
	);
}

export default LegacyAdmin;
