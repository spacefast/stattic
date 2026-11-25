import { useState, useMemo, useEffect, useCallback } from 'react';
import { usePostFields } from '@zero-admin/fields-next';
import { unlock, viewToQuery } from '@zero-admin/admin-toolkit';
import { DataViewsPicker } from '@zero-admin/dataviews';
import type { View } from '@zero-admin/dataviews';
import {
	privateApis as coreDataPrivateApis,
	useEntityRecord,
	store as coreStore,
} from '@wordpress/core-data';
import type { Post, Type } from '@wordpress/core-data';
import { __ } from '@wordpress/i18n';
import { DropZone } from '@wordpress/components';
import { useSelect, useRegistry } from '@wordpress/data';
import './style.scss';

/**
 * Type guard for import handler modules
 */
type ImportHandlerModule = {
	default: (
		registry: ReturnType< typeof useRegistry >,
		files: File[]
	) => void;
};

function isImportHandlerModule(
	module: unknown
): module is ImportHandlerModule {
	return (
		typeof module === 'object' &&
		module !== null &&
		'default' in module &&
		typeof module.default === 'function'
	);
}

/**
 * Extract private APIs with proper typing
 */
type UseEntityRecordsWithPermissions = < T >(
	kind: string,
	name: string,
	query: Record< string, unknown >,
	options: { enabled: boolean }
) => {
	records?: T[];
	totalItems?: number;
	totalPages?: number;
	hasResolved: boolean;
};

// eslint-disable-next-line @typescript-eslint/no-explicit-any, @typescript-eslint/no-unsafe-assignment -- unlock() returns untyped private APIs
const unlockedApis: any = unlock( coreDataPrivateApis );
// eslint-disable-next-line @typescript-eslint/no-unsafe-assignment -- accessing untyped private APIs
const useEntityRecordsWithPermissions: UseEntityRecordsWithPermissions =
	// eslint-disable-next-line @typescript-eslint/no-unsafe-member-access -- accessing untyped private APIs
	unlockedApis.useEntityRecordsWithPermissions;

const getItemId = ( item: Post ) => item.id.toString();

type QueryParams = {
	media_type?: string | string[];
	[ key: string ]: unknown;
};

type PostPickerProps = {
	/**
	 * The post type to display.
	 *
	 * @type {string}
	 */
	postType: string;

	/**
	 * The query parameters to pass to the data view.
	 *
	 * @type {QueryParams}
	 */
	queryParams?: QueryParams;

	/**
	 * Any preselected items before the data view is rendered.
	 *
	 * @type {string[]}
	 */
	currentSelection: string[];

	/**
	 * The callback to call when an item or items are selected.
	 *
	 * If `multiple` is `true`, the callback will receive an array of items.
	 * Otherwise, the callback will receive a single item.
	 *
	 * @type {( items: Post[] | Post ) => void}
	 */
	onSelect: ( items: Post[] | Post ) => void;

	/**
	 * Whether the post picker should allow multiple selections.
	 *
	 * @type {boolean}
	 * @default false
	 */
	multiple?: boolean;

	/**
	 * The label for the items list, used for accessibility.
	 *
	 * @type {string}
	 * @default 'Posts list'
	 */
	itemListLabel?: string;
};

const fallbackView: View = {
	type: 'pickerGrid',
	page: 1,
	search: '',
};

export default function PostPicker( {
	currentSelection,
	postType,
	queryParams,
	onSelect,
	multiple,
	itemListLabel = __( 'Posts list', 'zero-admin' ),
}: PostPickerProps ) {
	const [ selection, setSelection ] =
		useState< string[] >( currentSelection );
	const registry = useRegistry();
	const { record: postTypeObject, hasResolved: hasResolvedPostType } =
		useEntityRecord< Type >( 'root', 'postType', postType );

	const canCreateRecord = useSelect(
		( select ) =>
			select( coreStore ).canUser( 'create', {
				kind: 'postType',
				name: postType,
			} ),
		[ postType ]
	);

	// The view is initially undefined, but then set via an effect once the post type is resolved.
	const [ view, setView ] = useState< View | undefined >();
	useEffect( () => {
		if ( hasResolvedPostType && ! view ) {
			// Ensure the default view is always a pickerGrid in the post picker.
			// Once DataViewsPicker supports other layouts this can be removed.
			const defaultView = postTypeObject?.default_view ?? fallbackView;

			setView( { ...defaultView, type: 'pickerGrid', layout: {} } );
		}
	}, [ postTypeObject?.default_view, hasResolvedPostType, view ] );

	const baseQuery = useMemo( () => {
		return view ? viewToQuery( view, postType ) : {};
	}, [ postType, view ] );

	const query = useMemo( () => {
		return { ...baseQuery, ...queryParams };
	}, [ baseQuery, queryParams ] );

	const { isLoading: isLoadingFields, fields } = usePostFields( {
		postType,
	} );

	const {
		records: posts,
		totalItems,
		totalPages,
		hasResolved: hasResolvedPosts,
	} = useEntityRecordsWithPermissions< Post >( 'postType', postType, query, {
		// Avoid fetching posts until the post type is resolved.
		// Also wait for the view to be set, once the view is in place
		// additional params are added to the query (e.g. per_page),
		// so waiting prevents multiple requests.
		enabled: hasResolvedPostType && Boolean( view ),
	} );

	const actions = useMemo(
		() => [
			{
				id: 'select',
				label: __( 'Select', 'zero-admin' ),
				isPrimary: true,
				async callback() {
					if ( selection.length === 0 ) {
						return;
					}

					const selectedPostsQuery = {
						include: selection,
						per_page: -1,
					};

					// eslint-disable-next-line @typescript-eslint/no-explicit-any, @typescript-eslint/no-unsafe-assignment, @typescript-eslint/no-unsafe-call, @typescript-eslint/no-unsafe-member-access, @typescript-eslint/no-unsafe-type-assertion -- resolveSelect is not typed in DataRegistry
					const store: any = ( registry as any ).resolveSelect(
						coreStore
					);
					// eslint-disable-next-line @typescript-eslint/no-unsafe-assignment -- assignment from untyped store
					const selectedPosts: Post[] | undefined =
						// eslint-disable-next-line @typescript-eslint/no-unsafe-call, @typescript-eslint/no-unsafe-member-access -- store methods are untyped
						await store.getEntityRecords(
							'postType',
							postType,
							selectedPostsQuery
						);

					const selectedItems = multiple
						? selectedPosts
						: selectedPosts?.[ 0 ];
					if ( selectedItems ) {
						onSelect( selectedItems );
					}
				},
				supportsBulk: Boolean( multiple ),
			},
		],
		[ multiple, onSelect, registry, selection, postType ]
	);

	const onChangeView = useCallback(
		( _view: View ) => {
			// Dataviews will try updating the view by itself if there are missing params in the default view.
			// We want to avoid it overriding the view set from the post type. Return early to avoid this.
			if ( ! hasResolvedPostType ) {
				return;
			}
			setView( _view );
		},
		[ setView, hasResolvedPostType ]
	);

	return (
		<>
			{ canCreateRecord &&
				postTypeObject &&
				'import_handler' in postTypeObject && (
					<DropZone
						onFilesDrop={ ( files ) => {
							let filteredFiles = files;
							// Filter files by allowed types if specified in queryParams
							const mediaType = queryParams?.media_type;
							if ( mediaType ) {
								// Handle both array and comma-separated string
								const mediaTypes = Array.isArray( mediaType )
									? mediaType
									: mediaType.split( ',' );
								filteredFiles = files.filter( ( file ) =>
									mediaTypes.some( ( allowedType ) => {
										// Check if the file type matches the allowed MIME type
										return (
											file.type === allowedType ||
											file.type.startsWith(
												allowedType.replace( '*', '' )
											)
										);
									} )
								);
							}
							if (
								filteredFiles.length > 0 &&
								typeof postTypeObject.import_handler ===
									'string'
							) {
								import( postTypeObject.import_handler )
									.then( ( module: unknown ) => {
										if ( isImportHandlerModule( module ) ) {
											module.default(
												registry,
												filteredFiles
											);
										}
									} )
									.catch( ( error ) => {
										// eslint-disable-next-line no-console
										console.error(
											'Error loading import handler:',
											error
										);
									} );
							}
						} }
						label={ __( 'Drop files to upload', 'zero-admin' ) }
						className="post-picker__drop-zone"
					/>
				) }
			<DataViewsPicker
				data={ posts ?? [] }
				fields={ fields }
				view={ view ?? fallbackView }
				actions={ actions }
				defaultLayouts={ { pickerGrid: {} } }
				onChangeView={ onChangeView }
				isLoading={
					! hasResolvedPostType ||
					! hasResolvedPosts ||
					isLoadingFields
				}
				paginationInfo={ {
					totalItems: totalItems ?? 0,
					totalPages: totalPages ?? 0,
				} }
				selection={ selection }
				onChangeSelection={ setSelection }
				getItemId={ getItemId }
				itemListLabel={ itemListLabel }
			/>
		</>
	);
}
