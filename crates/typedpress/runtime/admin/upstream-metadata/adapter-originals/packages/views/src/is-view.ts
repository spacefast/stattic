import type { View } from '@zero-admin/dataviews';

type ValidViewType = { [ K in View[ 'type' ] ]: true };

const VALID_VIEW_TYPES: ValidViewType = {
	table: true,
	grid: true,
	list: true,
	activity: true,
	pickerGrid: true,
	pickerTable: true,
};

const isValidViewType = ( type: string ): type is View[ 'type' ] => {
	return type in VALID_VIEW_TYPES;
};

/**
 * Type guard to check if a value is a valid View object.
 *
 * @param value The value to check
 * @return True if the value is a valid View object
 */
export function isView( value: unknown ): value is View {
	if ( ! value || typeof value !== 'object' ) {
		return false;
	}

	return (
		'type' in value &&
		typeof value.type === 'string' &&
		isValidViewType( value.type )
	);
}
