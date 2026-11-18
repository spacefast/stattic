import {
	generateGlobalStyles,
	mergeGlobalStyles,
	type GlobalStylesConfig,
} from '@zero-admin/global-styles-engine';
import { useThemeProviderStyles } from '@zero-admin/theme';
import { store as coreDataNextStore } from '@zero-admin/core-data-next';
import { store as coreStore } from '@wordpress/core-data';
import { useMemo } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { useUserGlobalStyles } from './use-global-styles';

/**
 * This is a React hook that provides the editor settings from the REST API.
 *
 * @param {Object} props            - The props object.
 * @param {string} [props.stylesId] - The ID of the user's global styles to use.
 * @return Editor settings.
 */
export function useEditorSettings( { stylesId }: { stylesId: string } ) {
	const { baseGlobalStyles, editorSettings } = useSelect< {
		baseGlobalStyles: GlobalStylesConfig | undefined;
		editorSettings: {
			styles: Array< any >;
		};
	} >(
		( select ) => ( {
			baseGlobalStyles:
				select(
					coreStore
				).__experimentalGetCurrentThemeBaseGlobalStyles(),
			editorSettings: select( coreDataNextStore ).getEditorSettings(),
		} ),
		[]
	);
	const { user: userGlobalStyles } = useUserGlobalStyles( stylesId );

	const [ globalStylesCSS, globalSettingsForFeatures ] = useMemo( () => {
		let mergedStyles;
		if ( ! baseGlobalStyles && userGlobalStyles ) {
			mergedStyles = userGlobalStyles;
		} else if ( ! userGlobalStyles && baseGlobalStyles ) {
			mergedStyles = baseGlobalStyles;
		} else if ( baseGlobalStyles && userGlobalStyles ) {
			mergedStyles = mergeGlobalStyles(
				baseGlobalStyles,
				userGlobalStyles
			);
		}
		return generateGlobalStyles( mergedStyles );
	}, [ baseGlobalStyles, userGlobalStyles ] );

	const { themeProviderStyles } = useThemeProviderStyles();
	const themeProviderStylesString = useMemo( () => {
		return {
			css: `
				:root {
					${ Object.entries( themeProviderStyles ?? {} )
						.map( ( [ key, value ] ) => `${ key }: ${ value };` )
						.join( '\n' ) }
				}
			`,
		};
	}, [ themeProviderStyles ] );
	const hasEditorSettings = !! editorSettings;
	const styles = useMemo( () => {
		if ( ! hasEditorSettings ) {
			return [];
		}
		return [
			...( editorSettings?.styles ?? [] ),
			themeProviderStylesString,
			...globalStylesCSS,
		];
	}, [
		hasEditorSettings,
		editorSettings?.styles,
		themeProviderStylesString,
		globalStylesCSS,
	] );

	return {
		isReady: hasEditorSettings,
		editorSettings: useMemo(
			() => ( {
				...( editorSettings ?? {} ),
				styles,
				__experimentalFeatures: globalSettingsForFeatures,
			} ),
			[ editorSettings, styles, globalSettingsForFeatures ]
		),
	};
}
