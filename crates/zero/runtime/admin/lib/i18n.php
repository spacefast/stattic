<?php
/**
 * Adding support for i18n of script modules in Zero Admin
 *
 * @package ZeroAdmin
 */

/**
 * Prints the import map with translations and scopes.
 */
function zero_admin_print_import_map() {
	// Too many useful methods in `WP_Script_Modules` are private, we need to work around that.
	$script_modules        = wp_script_modules();
	$reflection            = new ReflectionClass( $script_modules );
	$get_import_map_method = $reflection->getMethod( 'get_import_map' );
	$get_import_map_method->setAccessible( true );
	$import_map = $get_import_map_method->invoke( $script_modules );

	if ( empty( $import_map['imports'] ) ) {
		return;
	}

	// Determine the translation file (a JSON chunk file name) for each module URL.
	$translation_files = array();
	foreach ( $import_map['imports'] as $module_id => $module_url ) {
		// Register a dummy script with the module's relative path as src.
		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_register_script( 'zero-admin-translations', $module_url, array(), null, false );

		// Set script translations for this module.
		wp_set_script_translations( 'zero-admin-translations', 'zero-admin', plugin_dir_path( __FILE__ ) . 'languages' );

		// Intercept loading the translations for this module. The module relative path will be used to find the right JSON chunk file.
		$intercept_translation_file = function ( $translations, $file ) use ( &$translation_files, $module_url ) {
			if ( $translations && ! isset( $translation_files[ $module_url ] ) ) {
				$translation_files[ $module_url ] = $file;
				return null;
			}
		};

		add_filter( 'load_script_translations', $intercept_translation_file, 10, 2 );
		wp_scripts()->print_translations( 'zero-admin-translations' );
		remove_filter( 'load_script_translations', $intercept_translation_file );

		// Deregister the dummy script.
		wp_deregister_script( 'zero-admin-translations' );
	}

	// Initialize scopes if it doesn't exist.
	if ( ! isset( $import_map['scopes'] ) ) {
		$import_map['scopes'] = array();
	}

	// Add the default/fallback `__i18n__` module that resolves to an empty JS data URL.
	$import_map['scopes']['/'] = array(
		'__i18n__' => 'data:application/javascript,',
	);

	foreach ( $translation_files as $module_url => $file ) {
		$file_url = esc_js( str_replace( WP_CONTENT_DIR, content_url(), $file ) );

		// Create the JavaScript code for loading translations.
		$translation_code = <<<JS
import translations from '$file_url' with { type: 'json' };
const localeData = translations.locale_data['zero-admin'] || translations.locale_data.messages;
localeData[''].domain = 'zero-admin';
wp.i18n.setLocaleData( localeData, 'zero-admin' );
JS;

		// Create a data URL with the translation loading code.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$data_url = 'data:application/javascript;base64,' . base64_encode( $translation_code );

		// Add to scopes, mapping the module URL to the data URL.
		$import_map['scopes'][ $module_url ] = array(
			'__i18n__' => $data_url,
		);
	}

	wp_print_inline_script_tag(
		wp_json_encode( $import_map, JSON_HEX_TAG | JSON_HEX_AMP ),
		array(
			'type' => 'importmap',
			'id'   => 'wp-importmap',
		)
	);
}
