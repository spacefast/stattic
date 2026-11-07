<?php
/**
 * REST API: Zero_Admin_REST_Editor_Controller class
 *
 * @package ZeroAdmin
 * @since 1.0.0
 */

/**
 * Core controller used to access editor settings and assets via the REST API.
 *
 * @since 1.0.0
 *
 * @see WP_REST_Controller
 */
class Zero_Admin_REST_Editor_Controller extends WP_REST_Controller {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->namespace = 'wp/v2';
		$this->rest_base = 'editor';
	}

	/**
	 * Registers the routes for editor endpoints.
	 *
	 * @since 1.0.0
	 *
	 * @see register_rest_route()
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'get_settings_permissions_check' ),
				),
				'schema' => array( $this, 'get_settings_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/assets',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_assets' ),
					'permission_callback' => array( $this, 'get_assets_permissions_check' ),
					'args'                => array(
						'standalone' => array(
							'description' => __( 'Include the scripts and styles the dashboard boot module already loads, for an editor running outside the dashboard.', 'zero-admin' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
				'schema' => array( $this, 'get_assets_schema' ),
			)
		);
	}

	/**
	 * Checks if a given request has access to read editor settings.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access, WP_Error object otherwise.
	 */
	public function get_settings_permissions_check( $request ) {
		return $this->editor_permissions_check();
	}

	/**
	 * Checks if a given request has access to read editor assets.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access, WP_Error object otherwise.
	 */
	public function get_assets_permissions_check( $request ) {
		return $this->editor_permissions_check();
	}

	/**
	 * The editor's settings and assets describe the block editor an editor sees; nobody else needs them.
	 *
	 * @return true|WP_Error
	 */
	private function editor_permissions_check() {
		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}
		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to load the editor.', 'zero-admin' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Retrieves editor settings.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_settings( $request ) {
		// Load WordPress admin environment.
		$this->load_admin_environment();

		// Set up the block editor context.
		set_current_screen();
		$current_screen = get_current_screen();
		if ( $current_screen ) {
			$current_screen->is_block_editor( true );
		}

		// Get block editor settings.
		$block_editor_context = new WP_Block_Editor_Context( array( 'name' => 'zero-admin' ) );
		$editor_settings      = get_block_editor_settings( array(), $block_editor_context );

		return rest_ensure_response( $editor_settings );
	}

	/**
	 * Retrieves editor assets (scripts, styles, and inline content).
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_assets( $request ) {
		// Load WordPress admin environment.
		$this->load_admin_environment();

		// Set up the block editor context.
		set_current_screen();
		$current_screen = get_current_screen();
		if ( $current_screen ) {
			$current_screen->is_block_editor( true );
		}

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$hook_suffix = 'zero-admin-editor';

		// REST initializes the asset registries before there is an admin screen.
		// Register core's admin dependencies now, before plugins enqueue editor
		// assets such as SCF's alpha picker (which depends on Iris's Color global).
		$defaults = new WP_Scripts();
		foreach ( $defaults->registered as $handle => $dependency ) {
			if ( ! isset( wp_scripts()->registered[ $handle ] ) ) {
				wp_scripts()->add( $handle, $dependency->src, $dependency->deps, $dependency->ver, $dependency->args );
				wp_scripts()->registered[ $handle ] = $dependency;
			}
		}
		$defaults = new WP_Styles();
		foreach ( $defaults->registered as $handle => $dependency ) {
			if ( ! isset( wp_styles()->registered[ $handle ] ) ) {
				wp_styles()->add( $handle, $dependency->src, $dependency->deps, $dependency->ver, $dependency->args );
				wp_styles()->registered[ $handle ] = $dependency;
			}
		}

		// Remove unwanted scripts/styles.
		remove_action( 'admin_enqueue_scripts', 'gutenberg_enqueue_command_palette_assets', 9 );
		remove_action( 'admin_enqueue_scripts', 'gutenberg_enqueue_command_palette_assets' );
		remove_action( 'admin_enqueue_scripts', 'wp_auth_check_load' );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );

		// Pre-populate block categories so third-party blocks (MailPoet, WooCommerce Bookings, etc.)
		// find their custom categories already registered when their scripts call registerBlockType().
		// Without this, loadEditorAssets() executes block scripts before BlockEditorProvider mounts
		// and dispatches setCategories(), causing "registered with an invalid category" warnings.
		$block_editor_context = new WP_Block_Editor_Context( array( 'name' => 'zero-admin' ) );
		$block_categories     = get_block_categories( $block_editor_context );
		wp_add_inline_script(
			'wp-block-library',
			'wp.blocks.setCategories(' . wp_json_encode( $block_categories, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ) . ');',
			'before'
		);

		// Preload blocks - this creates inline scripts.
		$server_block_settings = get_block_editor_server_block_settings();
		wp_add_inline_script(
			'wp-block-library',
			'wp.blocks.unstable__bootstrapServerSideBlockDefinitions(' . wp_json_encode( $server_block_settings, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ) . ');',
			'before'
		);

		// Preload server-registered block bindings sources.
		$registered_sources = get_all_registered_block_bindings_sources();
		if ( ! empty( $registered_sources ) ) {
			$filtered_sources = array();
			foreach ( $registered_sources as $source ) {
				$filtered_sources[] = array(
					'name'        => $source->name,
					'label'       => $source->label,
					'usesContext' => $source->uses_context,
				);
			}
			$script = sprintf( 'for ( const source of %s ) { wp.blocks.registerBlockBindingsSource( source ); }', wp_json_encode( $filtered_sources, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ) );
			wp_add_inline_script(
				'wp-block-library',
				$script,
				'before'
			);
		}

		$load_blocks_scripts = 'wp.blockLibrary.registerCoreBlocks( wp.blockLibrary.__experimentalGetCoreBlocks().filter( ( block ) => block.name !== "core/freeform" ) );';
		wp_add_inline_script(
			'wp-block-library',
			$load_blocks_scripts,
			'after'
		);

		// Enqueue editor assets.
		wp_enqueue_script( 'wp-format-library' );
		wp_enqueue_script( 'wp-block-library' );
		wp_enqueue_style( 'wp-editor' );
		wp_enqueue_style( 'wp-block-library' );
		wp_enqueue_style( 'wp-format-library' );

		do_action( 'enqueue_block_editor_assets' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		// Never allow old admin styles as a dependency.
		wp_deregister_style( 'common' );
		wp_deregister_style( 'forms' );

		// Trigger rendering hooks to capture all assets.
		ob_start();
		do_action( 'admin_enqueue_scripts', $hook_suffix ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( "admin_print_styles-{$hook_suffix}" ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'admin_print_styles' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( "admin_print_scripts-{$hook_suffix}" ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'admin_print_scripts' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( "admin_head-{$hook_suffix}" ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'admin_head' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'admin_footer', '' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'wp_print_footer_scripts' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( "admin_print_footer_scripts-{$hook_suffix}" ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'admin_print_footer_scripts' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( "admin_footer-{$hook_suffix}" ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		ob_get_clean();

		// WooPayments block scripts access `window.wcpayAssets.url` at module init,
		// but don't declare `WCPAY_ASSETS` (which sets that global) as a dependency.
		// Add it explicitly so the global is available when block scripts execute.
		global $wp_scripts;
		if ( isset( $wp_scripts->registered['WCPAY_ASSETS'] ) ) {
			foreach ( $wp_scripts->registered as $handle => $script ) {
				if ( 0 === strpos( $handle, 'woocommerce-payments/' ) && ! in_array( 'WCPAY_ASSETS', $script->deps, true ) ) {
					$wp_scripts->registered[ $handle ]->deps[] = 'WCPAY_ASSETS';
				}
			}
		}

		// Process the captured assets.
		return rest_ensure_response( $this->process_assets( (bool) $request['standalone'] ) );
	}

	/**
	 * Process WordPress assets and return structured data.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $standalone Whether the caller has no boot module, and needs its scripts and styles too.
	 * @return array Structured asset data.
	 */
	private function process_assets( $standalone = false ) {
		global $wp_scripts, $wp_styles;

		$styles_data    = array();
		$scripts_data   = array();
		$inline_styles  = array(
			'before' => array(),
			'after'  => array(),
		);
		$inline_scripts = array(
			'before' => array(),
			'after'  => array(),
		);

		// Get boot module dependencies to exclude from preloading.
		$boot_asset_file   = include ZERO_ADMIN_PLUGIN_DIR . 'build/packages/boot/index.asset.php';
		$boot_dependencies = isset( $boot_asset_file['dependencies'] ) ? $boot_asset_file['dependencies'] : array();

		// Get all dependencies that should be excluded (boot dependencies + their deep dependencies).
		$excluded_scripts = $standalone ? array() : $this->get_all_dependencies( $boot_dependencies, $wp_scripts );
		$excluded_styles  = $standalone ? array() : $this->get_all_dependencies( array( 'wp-components', 'wp-commands', 'dashicons' ), $wp_styles );

		// Process styles - include all dependencies of queued styles.
		$all_needed_styles = array();
		foreach ( $wp_styles->queue as $handle ) {
			$all_needed_styles = array_merge( $all_needed_styles, $this->get_all_dependencies( array( $handle ), $wp_styles ) );
		}
		$all_needed_styles = array_unique( $all_needed_styles );

		foreach ( $all_needed_styles as $handle ) {
			if ( in_array( $handle, $excluded_styles, true ) ) {
				continue;
			}

			if ( isset( $wp_styles->registered[ $handle ] ) ) {
				$style                  = $wp_styles->registered[ $handle ];
				$styles_data[ $handle ] = array(
					'src'     => $this->asset_url( $style->src, $wp_styles->base_url ),
					'deps'    => $style->deps,
					'version' => $style->ver,
					'media'   => $style->args,
				);

				$inline_style = $wp_styles->get_data( $handle, 'after' );
				if ( ! empty( $inline_style ) ) {
					$inline_styles['after'][ $handle ] = $inline_style;
				}
				$inline_style = $wp_styles->get_data( $handle, 'before' );
				if ( ! empty( $inline_style ) ) {
					$inline_styles['before'][ $handle ] = $inline_style;
				}
			}
		}

		// Process scripts - include all dependencies of queued scripts.
		$all_needed_scripts = array();
		foreach ( $wp_scripts->queue as $handle ) {
			$all_needed_scripts = array_merge( $all_needed_scripts, $this->get_all_dependencies( array( $handle ), $wp_scripts ) );
		}
		$all_needed_scripts = array_unique( $all_needed_scripts );

		foreach ( $all_needed_scripts as $handle ) {
			// Skip scripts that are already loaded by the boot module.
			if ( in_array( $handle, $excluded_scripts, true ) ) {
				continue;
			}

			if ( isset( $wp_scripts->registered[ $handle ] ) ) {
				$script                  = $wp_scripts->registered[ $handle ];
				$scripts_data[ $handle ] = array(
					'src'       => $this->asset_url( $script->src, $wp_scripts->base_url ),
					'deps'      => $script->deps,
					'version'   => $script->ver,
					'in_footer' => isset( $script->extra['group'] ) && 1 === $script->extra['group'],
				);

				// Get inline scripts using proper WordPress methods.
				$before_script = $wp_scripts->get_inline_script_data( $handle, 'before' );
				$after_script  = $wp_scripts->get_inline_script_data( $handle, 'after' );

				// Get extra script data (localized data).
				$extra_script = $wp_scripts->print_extra_script( $handle, false );

				// Store before scripts (includes extra/localized data).
				$before_content = '';
				if ( ! empty( $before_script ) ) {
					$before_content .= $before_script . "\n";
				}
				if ( ! empty( $extra_script ) ) {
					$before_content .= $extra_script . "\n";
				}

				if ( ! empty( $before_content ) ) {
					$inline_scripts['before'][ $handle ] = trim( $before_content );
				}

				// Store after scripts separately.
				if ( ! empty( $after_script ) ) {
					$inline_scripts['after'][ $handle ] = trim( $after_script );
				}
			}
		}

		return array(
			'scripts'        => $scripts_data,
			'styles'         => $styles_data,
			'inline_scripts' => $inline_scripts,
			'inline_styles'  => $inline_styles,
		);
	}

	/** Resolve registered relative paths against WordPress's installation URL. */
    private function asset_url( $src, $base_url ) {
        if ( ! $src ) {
            return '';
        }
        if ( preg_match( '|^(https?:)?//|', $src ) ) {
            return $src;
        }
        return rtrim( $base_url, '/' ) . '/' . ltrim( $src, '/' );
    }

	/**
	 * Get all dependencies recursively (works for both scripts and styles).
	 *
	 * @since 1.0.0
	 *
	 * @param array|string    $handles   The handles to get dependencies for.
	 * @param WP_Dependencies $wp_assets The WordPress dependencies object.
	 * @return array Array of all dependencies.
	 */
	private function get_all_dependencies( $handles, $wp_assets ) {
		$all_deps = array();
		$handles  = (array) $handles;

		foreach ( $handles as $handle ) {
			if ( isset( $wp_assets->registered[ $handle ] ) ) {
				$asset      = $wp_assets->registered[ $handle ];
				$all_deps[] = $handle;

				if ( ! empty( $asset->deps ) ) {
					$child_deps = $this->get_all_dependencies( $asset->deps, $wp_assets );
					$all_deps   = array_merge( $all_deps, $child_deps );
				}
			}
		}

		return array_unique( $all_deps );
	}

	/**
	 * Load WordPress admin environment and required functions.
	 *
	 * This mirrors the initialization that happens in wp-admin context
	 * to ensure all necessary functions and hooks are available.
	 *
	 * @since 1.0.0
	 */
	private function load_admin_environment() {
		// Load core admin files that provide essential functions.
		require_once ABSPATH . 'wp-admin/includes/admin.php';
	}

	/**
	 * Retrieves the editor settings schema, conforming to JSON Schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array Item schema data.
	 */
	public function get_settings_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'editor-settings',
			'type'       => 'object',
			'properties' => array(
				'alignWide'            => array(
					'description' => __( 'Whether theme supports wide alignment.', 'zero-admin' ),
					'type'        => 'boolean',
					'readonly'    => true,
				),
				'availableTemplates'   => array(
					'description' => __( 'Available templates for the editor.', 'zero-admin' ),
					'type'        => 'object',
					'readonly'    => true,
				),
				'allowedBlockTypes'    => array(
					'description' => __( 'Allowed block types in the editor.', 'zero-admin' ),
					'type'        => array( 'boolean', 'array' ),
					'readonly'    => true,
				),
				'disallowedBlockTypes' => array(
					'description' => __( 'Disallowed block types in the editor.', 'zero-admin' ),
					'type'        => 'array',
					'readonly'    => true,
				),
				'blockCategories'      => array(
					'description' => __( 'Available block categories.', 'zero-admin' ),
					'type'        => 'array',
					'readonly'    => true,
				),
				'hasFixedToolbar'      => array(
					'description' => __( 'Whether the editor has a fixed toolbar.', 'zero-admin' ),
					'type'        => 'boolean',
					'readonly'    => true,
				),
				'hasReducedUI'         => array(
					'description' => __( 'Whether the editor has reduced UI.', 'zero-admin' ),
					'type'        => 'boolean',
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $schema );
	}

	/**
	 * Retrieves the editor assets schema, conforming to JSON Schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array Item schema data.
	 */
	public function get_assets_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'editor-assets',
			'type'       => 'object',
			'properties' => array(
				'scripts'        => array(
					'description' => __( 'Editor scripts data.', 'zero-admin' ),
					'type'        => 'object',
					'readonly'    => true,
				),
				'styles'         => array(
					'description' => __( 'Editor styles data.', 'zero-admin' ),
					'type'        => 'object',
					'readonly'    => true,
				),
				'inline_scripts' => array(
					'description' => __( 'Inline scripts for editor assets.', 'zero-admin' ),
					'type'        => 'object',
					'readonly'    => true,
				),
				'inline_styles'  => array(
					'description' => __( 'Inline styles for editor assets.', 'zero-admin' ),
					'type'        => 'object',
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $schema );
	}
}

// Register the editor REST controller.
add_action(
	'rest_api_init',
	function () {
		( new Zero_Admin_REST_Editor_Controller() )->register_routes();
	}
);
