import { useEffect, useRef } from 'react';
import { useNavigate } from '@wordpress/route';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';

/**
 * Hook that redirects to the edit URL when a new post is saved for the first time.
 *
 * When a post is created at /types/post/new, it starts with status 'auto-draft'.
 * After the user saves it (as draft or publishes it), we need to update the URL
 * to /types/$type/edit/$id.
 *
 * @param postType The post type being edited
 * @param postId   The post ID being edited
 */
export function useRedirectOnSave( postType: string, postId: string ) {
	const navigate = useNavigate();
	const hasRedirectedRef = useRef( false );
	const initialStatusRef = useRef< string | null >( null );

	const postStatus = useSelect(
		( select ) => {
			const post = select( coreStore ).getEditedEntityRecord(
				'postType',
				postType,
				postId
			);
			if ( ! post || ! ( 'status' in post ) ) {
				return null;
			}
			return post.status;
		},
		[ postType, postId ]
	);

	useEffect( () => {
		// Store the initial status on mount
		if ( initialStatusRef.current === null && postStatus ) {
			initialStatusRef.current = postStatus;
		}

		// Only redirect if:
		// 1. We haven't redirected yet
		// 2. The post started as auto-draft
		// 3. The status has changed to something other than auto-draft
		if (
			! hasRedirectedRef.current &&
			initialStatusRef.current === 'auto-draft' &&
			postStatus &&
			// @ts-expect-error - post.status is not typed correctly and is missing auto-draft
			postStatus !== 'auto-draft'
		) {
			hasRedirectedRef.current = true;

			navigate( {
				to: `/types/${ postType }/edit/${ encodeURIComponent(
					postId
				) }`,
				replace: true,
			} );
		}
	}, [ postStatus, postType, postId, navigate ] );
}
