import { useMemo, useCallback, useEffect, useRef } from 'react';
import { useNavigate, privateApis as routePrivateApis } from '@wordpress/route';
import { unlock } from '@zero-admin/admin-toolkit';

const { useRouter, useCanGoBack } = unlock( routePrivateApis );

type EntityRecord = {
	postType: string;
	postId: string;
};

/**
 * Hook for navigating between entities in the editor with focus mode support.
 *
 * Provides navigation functions that allow users to drill into specific entities
 * (focus mode) while maintaining the ability to return to the original entity
 * they started editing.
 *
 * @param currentEntity The entity currently being edited
 * @param isPreview     Whether the editor is in preview mode.
 *
 * @return Navigation functions
 *
 * @example
 * ```tsx
 * // In original editor for Post #123
 * const { onNavigateToEntityRecord, onNavigateToPreviousEntityRecord } =
 *   useNavigateToEntity({ postType: 'post', postId: '123' });
 *
 * // Navigate to focus mode (Page #456)
 * onNavigateToEntityRecord({ postType: 'page', postId: '456' });
 *
 * // Back button is now available to return to Post #123
 * if (onNavigateToPreviousEntityRecord) {
 *   // User can go back to original entity
 * }
 * ```
 */
export function useNavigateToEntity(
	currentEntity: EntityRecord,
	isPreview: boolean
) {
	const navigate = useNavigate();
	const router = useRouter();
	const canGoBack = useCanGoBack();

	// Store the first edited entity as the original entity.
	const originalEditedEntity = useRef< EntityRecord | null >(
		isPreview ? null : currentEntity ?? null
	);
	useEffect( () => {
		if ( isPreview || ! currentEntity ) {
			originalEditedEntity.current = null;
		} else if ( ! originalEditedEntity.current && currentEntity ) {
			originalEditedEntity.current = currentEntity;
		}
	}, [ currentEntity, isPreview ] );

	const onNavigateToEntityRecord = useCallback(
		( params: EntityRecord ) => {
			// Navigate to the new entity
			navigate( {
				to: `/types/${ params.postType }/edit/${ encodeURIComponent(
					params.postId
				) }`,
			} );
		},
		[ navigate ]
	);

	// Show back button only if we can go back AND current entity differs from the
	// original entity, so we've navigated to a focus mode entity.
	const isFocusModeEntity =
		currentEntity &&
		originalEditedEntity.current &&
		( currentEntity.postType !== originalEditedEntity.current.postType ||
			currentEntity.postId !== originalEditedEntity.current.postId );
	const canGoBackToOriginalEntity = canGoBack && isFocusModeEntity;

	return useMemo( () => {
		return {
			onNavigateToEntityRecord,
			onNavigateToPreviousEntityRecord: canGoBackToOriginalEntity
				? router.history.back
				: undefined,
		};
	}, [
		onNavigateToEntityRecord,
		canGoBackToOriginalEntity,
		router.history.back,
	] );
}
