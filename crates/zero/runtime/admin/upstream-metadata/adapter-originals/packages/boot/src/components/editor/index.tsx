import clsx from 'clsx';
import { useEffect, useMemo, useRef } from 'react';
import { useNavigate, useLinkProps } from '@wordpress/route';
import { unlock, SiteIconBackButton } from '@zero-admin/admin-toolkit';
import { withEditor, withStylesId } from '@zero-admin/lazy-editor';
import { privateApis as editorPrivateApis } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { useMergeRefs, useRefEffect, usePrevious } from '@wordpress/compose';
import '../media-modal';
import './style.scss';
import { useNavigateToEntity } from './use-navigate-to-entity';
import useIconLabelsPreference from './use-icon-labels-preference';
import { useRedirectOnSave } from './use-redirect-on-save';

/* eslint-disable @typescript-eslint/no-unsafe-assignment -- unlock() returns untyped private APIs */
const { Editor, FullscreenMode, BackButton, PreferencesModal } =
	unlock( editorPrivateApis );
/* eslint-enable @typescript-eslint/no-unsafe-assignment */

function SiteEditor( {
	settings,
	postType,
	postId,
	templateId,
	isPreview = false,
	editLink = `/types/${ postType }/edit/${ encodeURIComponent( postId ) }`,
}: {
	settings: Record< string, unknown >;
	postType: string;
	postId: string;
	templateId?: string;
	isPreview?: boolean;
	editLink?: string;
} ) {
	useIconLabelsPreference();
	useRedirectOnSave( postType, postId );
	const navigate = useNavigate();
	const iframeBodyRef = useRef< HTMLElement >();
	const inertRef = useRefEffect(
		( element: HTMLElement ) => {
			// We want the iframe contents to be scrollable, so we can't make
			// the iframe itself inert. This also means that clicks will be
			// captured by the iframe, inside the iframe instead of on the
			// button element.
			element.inert = true;
			function onClick() {
				void navigate( { to: editLink } );
			}
			element.parentElement?.addEventListener( 'click', onClick );
			return () => {
				element.parentElement?.removeEventListener( 'click', onClick );
				element.inert = false;
			};
		},
		[ navigate, editLink ]
	);
	const contentRef = useMergeRefs( [
		isPreview ? inertRef : null,
		iframeBodyRef,
	] );
	const linkRef = useRef< HTMLAnchorElement >( null );
	const linkProps = useLinkProps( { to: editLink } );
	const previousIsPreview = usePrevious( isPreview ) ?? isPreview;
	// Move focus to the iframe manually when the link is used.
	useEffect( () => {
		if ( isPreview !== previousIsPreview ) {
			if ( ! isPreview ) {
				iframeBodyRef.current?.focus();
			} else {
				linkRef.current?.focus();
			}
		}
	}, [ isPreview, previousIsPreview ] );

	const navigationEditorSettings = useNavigateToEntity(
		{
			postType,
			postId,
		},
		isPreview
	);
	const allEditorSettings = useMemo( () => {
		return {
			...settings,
			...navigationEditorSettings,
			isPreviewMode: isPreview,
		};
	}, [ settings, isPreview, navigationEditorSettings ] );

	if ( ! postType || ! postId ) {
		return null;
	}
	return (
		<div className="zero-admin-site-editor">
			<Editor
				postType={ postType }
				postId={ postId }
				templateId={ templateId }
				settings={ allEditorSettings }
				styles={ settings.styles }
				forceDisableBlockTools={ isPreview }
				contentRef={ contentRef }
				iframeProps={ { tabIndex: isPreview ? -1 : 0 } }
			>
				<BackButton>
					<SiteIconBackButton
						to={
							postId && postType
								? `/types/${ postType }?postId=${ postId }`
								: '/'
						}
					/>
				</BackButton>
				<PreferencesModal />
			</Editor>
			<FullscreenMode isActive={ ! isPreview } />
			{ isPreview && (
				<a
					{ ...linkProps }
					className={ clsx(
						'zero-admin-site-editor__hyperlink',
						linkProps.className
					) }
					aria-label={ __( 'Edit', 'zero-admin' ) }
					ref={ linkRef }
				/>
			) }
		</div>
	);
}

export default withStylesId( withEditor( SiteEditor ) );
