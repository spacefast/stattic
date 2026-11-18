/**
 * Generates a unique preference key for a DataViews view.
 * View settings persist globally across all tabs for the same entity.
 *
 * @param kind    The entity kind (e.g., 'postType', 'root')
 * @param name    The specific entity name (e.g., 'post', 'user', 'site')
 * @param variant Optional. View config unique identifier.
 * @return The preference key string
 */
export function generatePreferenceKey(
	kind: string,
	name: string,
	variant?: string
): string {
	return variant ? `${ kind }-${ name }-${ variant }` : `${ kind }-${ name }`;
}
