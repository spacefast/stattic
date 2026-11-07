<?php
/**
 * REST API: Zero_Admin_REST_Plugins_Controller class
 *
 * @package Zero_Admin
 * @subpackage REST_API
 */

/**
 * Core class to access plugins with extended fields via the REST API.
 *
 * @since 1.0.0
 *
 * @see WP_REST_Plugins_Controller
 */
class Zero_Admin_REST_Plugins_Controller extends WP_REST_Plugins_Controller {
	/**
	 * Purges entries in the recently_activated option that are older than a week.
	 *
	 * Mirrors the cleanup performed by WP_Plugins_List_Table::prepare_items(), so
	 * that plugin listings obtained via REST remain consistent with wp-admin.
	 * On Multisite, this also purges the network-level option when relevant.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function purge_recently_activated_options(): void {
		// Site-level option.
		$recent = (array) get_option( 'recently_activated', array() );
		foreach ( $recent as $key => $time ) {
			if ( $time + WEEK_IN_SECONDS < time() ) {
				unset( $recent[ $key ] );
			}
		}
		update_option( 'recently_activated', $recent, false );

		// Network-level option (Multisite), mirror behavior when network admin loads.
		if ( is_multisite() ) {
			$recent_network = (array) get_site_option( 'recently_activated', array() );
			foreach ( $recent_network as $key => $time ) {
				if ( $time + WEEK_IN_SECONDS < time() ) {
					unset( $recent_network[ $key ] );
				}
			}
			update_site_option( 'recently_activated', $recent_network );
		}
	}

	/**
	 * Overrides parent to purge recently_activated entries before listing plugins.
	 *
	 * Ensures the server-side cleanup happens when the collection endpoint is
	 * accessed, mimicking the behavior of the classic plugins list table.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error on failure.
	 */
	public function get_items( $request ) {
		$this->purge_recently_activated_options();
		return parent::get_items( $request );
	}

	// Allow dots in plugin path segments; only disallow slashes.
	const PATTERN = '[^\/]+(?:\/[^\/]+)?';

	/**
	 * Adds extended filterable fields to the collection parameters.
	 *
	 * Allows filtering by:
	 * - recently_active (boolean)
	 * - update_available (boolean)
	 * - auto_update (boolean)
	 *
	 * @return array Collection parameters.
	 */
	public function get_collection_params(): array {
		$params = parent::get_collection_params();

		$params['recently_active'] = array(
			'description'       => __( 'Limit result set to plugins that were recently active (within a week).', 'zero-admin' ),
			'type'              => array( 'boolean', 'string' ), // Accept boolean or string that can be coerced.
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['update_available'] = array(
			'description'       => __( 'Limit result set to plugins with an available update.', 'zero-admin' ),
			'type'              => array( 'boolean', 'string' ),
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['auto_update'] = array(
			'description'       => __( 'Limit result set to plugins with auto-updates enabled or disabled.', 'zero-admin' ),
			'type'              => array( 'boolean', 'string' ),
			'validate_callback' => 'rest_validate_request_arg',
		);

		return $params;
	}

	/**
	 * Checks if the plugin matches the requested parameters.
	 *
	 * Extends the core matching logic (search and status) by adding support for
	 * additional boolean filters exposed by this controller:
	 * - recently_active
	 * - update_available
	 * - auto_update
	 *
	 * Applying the filters here ensures filtering happens prior to pagination and
	 * aligns with core design.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request to match against.
	 * @param array           $item    The raw plugin array (from get_plugins()).
	 * @return bool Whether the plugin matches the request filters.
	 */
	protected function does_plugin_match_request( $request, $item ): bool {
		// Honor core checks (search and status) first.
		if ( ! parent::does_plugin_match_request( $request, $item ) ) {
			return false;
		}

		// Read extra filters when explicitly provided in the request.
		$recently_active_param  = isset( $request['recently_active'] ) ? rest_sanitize_boolean( $request['recently_active'] ) : null;
		$update_available_param = isset( $request['update_available'] ) ? rest_sanitize_boolean( $request['update_available'] ) : null;
		$auto_update_param      = isset( $request['auto_update'] ) ? rest_sanitize_boolean( $request['auto_update'] ) : null;

		if ( null === $recently_active_param && null === $update_available_param && null === $auto_update_param ) {
			return true;
		}

		$plugin_file = $item['_file'] ?? '';
		if ( '' === $plugin_file ) {
			return false;
		}

		// recently_active: check presence in the option (site-level mirrors wp-admin screen behavior for non-network admin).
		if ( null !== $recently_active_param ) {
			$recent             = (array) get_option( 'recently_activated', array() );
			$is_recently_active = isset( $recent[ $plugin_file ] );
			if ( $is_recently_active !== $recently_active_param ) {
				return false;
			}
		}

		// auto_update: check network-level option per core behavior.
		if ( null !== $auto_update_param ) {
			$auto_updates    = (array) get_site_option( 'auto_update_plugins', array() );
			$has_auto_update = in_array( $plugin_file, $auto_updates, true );
			if ( $has_auto_update !== $auto_update_param ) {
				return false;
			}
		}

		// update_available: presence in update_plugins->response.
		if ( null !== $update_available_param ) {
			$update_plugins = get_site_transient( 'update_plugins' );
			$has_update     = isset( $update_plugins->response[ $plugin_file ] );
			if ( $has_update !== $update_available_param ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Registers the routes for the plugins controller using this class pattern.
	 *
	 * This overrides the core controller to ensure self::PATTERN resolves to this
	 * class instead of the parent when building the route regex and args pattern.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		// Unregister core routes first to ensure our controller handles the endpoints.
		if ( function_exists( 'unregister_rest_route' ) ) {
			// Collection route: /wp/v2/plugins.
			unregister_rest_route( $this->namespace, '/' . $this->rest_base );
			// Single item route: unregister both the core pattern and our extended pattern.
			unregister_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<plugin>' . WP_REST_Plugins_Controller::PATTERN . ')' );
			unregister_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<plugin>' . self::PATTERN . ')' );
		}

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(
						'slug'   => array(
							'type'        => 'string',
							'required'    => true,
							'description' => __( 'WordPress.org plugin directory slug.', 'zero-admin' ),
							'pattern'     => '[\w\-]+',
						),
						'status' => array(
							'description' => __( 'The plugin activation status.', 'zero-admin' ),
							'type'        => 'string',
							'enum'        => is_multisite() ? array( 'inactive', 'active', 'network-active' ) : array( 'inactive', 'active' ),
							'default'     => 'inactive',
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<plugin>' . self::PATTERN . ')',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
				),
				'args'   => array(
					'context' => $this->get_context_param( array( 'default' => 'view' ) ),
					'plugin'  => array(
						'type'              => 'string',
						'pattern'           => self::PATTERN,
						'validate_callback' => array( $this, 'validate_plugin_param' ),
						'sanitize_callback' => array( $this, 'sanitize_plugin_param' ),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Retrieves the plugin schema, conforming to JSON Schema.
	 *
	 * @return array Item schema data.
	 * @since 1.0.0
	 */
	public function get_item_schema(): array {
		$schema = parent::get_item_schema();

		$schema['properties']['recently_active'] = array(
			'description' => __( 'Whether the plugin was recently active.', 'zero-admin' ),
			'type'        => array( 'boolean' ),
			'context'     => array( 'view', 'edit', 'embed' ),
			'readonly'    => true,
		);

		$schema['properties']['auto_update'] = array(
			'description' => __( 'Whether auto-updates are enabled for this plugin.', 'zero-admin' ),
			'type'        => array( 'boolean' ),
			'context'     => array( 'view', 'edit', 'embed' ),
		);

		$schema['properties']['update_available'] = array(
			'description' => __( 'Whether an update is available for this plugin.', 'zero-admin' ),
			'type'        => 'boolean',
			'context'     => array( 'view', 'edit', 'embed' ),
			'readonly'    => true,
		);

		// Write-only flag to trigger updating the plugin to its latest version when set to true on PATCH.
		$schema['properties']['update'] = array(
			'description' => __( 'Trigger updating the plugin to the latest available version.', 'zero-admin' ),
			'type'        => array( 'boolean' ),
			'context'     => array( 'edit' ),
			'readonly'    => false,
		);

		return $schema;
	}

	/**
	 * Prepares a single plugin output for response.
	 *
	 * @param array           $plugin Plugin data.
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response Response object.
	 * @since 1.0.0
	 */
	public function prepare_item_for_response( $plugin, $request ): WP_REST_Response {
		$response    = parent::prepare_item_for_response( $plugin, $request );
		$data        = $response->get_data();
		$plugin_file = $plugin['_file'];

		// Add recently active status.
		$recently_active         = get_option( 'recently_activated', array() );
		$data['recently_active'] = isset( $recently_active[ $plugin_file ] );

		// Add auto-update status.
		$auto_updates        = (array) get_site_option( 'auto_update_plugins', array() );
		$data['auto_update'] = in_array( $plugin_file, $auto_updates, true );

		// Add update available status.
		$update_plugins           = get_site_transient( 'update_plugins' );
		$data['update_available'] = isset( $update_plugins->response[ $plugin_file ] );

		$response->set_data( $data );

		return $response;
	}

	/**
	 * Updates a single plugin.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 * @since 1.0.0
	 */
	public function update_item( $request ) {
		$response = parent::update_item( $request );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Handle auto_update field if it's set in the request.
		if ( isset( $request['auto_update'] ) ) {
			$response = $this->update_plugin_auto_update( $request );
		}

		// Handle version update if requested via the standard update route.
		if ( isset( $request['update'] ) && rest_sanitize_boolean( $request['update'] ) ) {
			return $this->update_plugin_version( $request );
		}

		return $response;
	}

	/**
	 * Enables or disables auto-updates for a plugin and returns the updated item response.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_plugin_auto_update( $request ) {
		$plugin_file  = $request['plugin'];
		$auto_updates = (array) get_site_option( 'auto_update_plugins', array() );

		// Coerce to strict boolean to support string inputs like "true"/"false", "1"/"0".
		$auto_update_requested = rest_sanitize_boolean( $request['auto_update'] );

		$currently_enabled = in_array( $plugin_file, $auto_updates, true );

		if ( $auto_update_requested && ! $currently_enabled ) {
			$auto_updates[] = $plugin_file;
			update_site_option( 'auto_update_plugins', $auto_updates );
		} elseif ( ! $auto_update_requested && $currently_enabled ) {
			$auto_updates = array_diff( $auto_updates, array( $plugin_file ) );
			update_site_option( 'auto_update_plugins', $auto_updates );
		}

		// Refresh the response data to include the updated auto_update status.
		$plugin = $this->get_plugin_data( $plugin_file );
		if ( is_wp_error( $plugin ) ) {
			return $plugin;
		}

		return $this->prepare_item_for_response( $plugin, $request );
	}

	/**
	 * Get the "update_plugins->response[$plugin]" entry, with a safe refresh.
	 * Returns the update object or null if none.
	 *
	 * @param string $plugin_file Plugin file path.
	 *
	 * @return mixed|null
	 */
	private function get_updates( string $plugin_file ) {
		$updates = get_site_transient( 'update_plugins' );

		// Normalize shape to avoid notices.
		if ( ! is_object( $updates ) ) {
			$updates = (object) array(
				'response'  => array(),
				'no_update' => array(),
			);
		}

		// If the entry isn't present, try a refresh. Core often clears this during/after updates.
		if ( empty( $updates->response[ $plugin_file ] ) ) {
			// Safe to refresh from a REST request.
			wp_update_plugins();

			$updates = get_site_transient( 'update_plugins' );
			if ( is_object( $updates ) && ! empty( $updates->response[ $plugin_file ] ) ) {
				set_site_transient( 'zero_admin_prev_update_plugins', $updates );
				return $updates->response[ $plugin_file ];
			}

			// Optional: fallback to a snapshot you maintain (good for UIs under flaky network).
			$snapshot = get_site_transient( 'zero_admin_prev_update_plugins' );
			if ( is_object( $snapshot ) && ! empty( $snapshot->response[ $plugin_file ] ) ) {
				return $snapshot->response[ $plugin_file ];
			}

			return null;
		}

		return $updates->response[ $plugin_file ];
	}

	/**
	 * Updates a plugin to the latest available version.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_plugin_version( $request ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$requested_plugin_file = $request['plugin'];

		// Early check: ensure an update is actually available for this plugin before proceeding.
		$should_update_plugin = $this->get_updates( $requested_plugin_file );
		if ( is_null( $should_update_plugin ) ) {
			return new WP_Error(
				'zero_admin_no_update_available',
				__( 'No update is currently available for this plugin.', 'zero-admin' ),
				array( 'status' => 400 )
			);
		}

		// Ensure filesystem available.
		$filesystem_available = $this->is_filesystem_available();
		if ( is_wp_error( $filesystem_available ) ) {
			return $filesystem_available;
		}

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );

		$result = $upgrader->upgrade( $requested_plugin_file );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 500 ) );
			return $result;
		}

		// Propagate filesystem errors from the upgrader skin.
		if ( is_wp_error( $skin->result ) ) {
			$skin->result->add_data( array( 'status' => 500 ) );
			return $skin->result;
		}

		if ( $skin->get_errors()->has_errors() ) {
			$error = $skin->get_errors();
			$error->add_data( array( 'status' => 500 ) );
			return $error;
		}

		// Derive the potentially new plugin file after upgrade.
		$new_plugin_file = $upgrader->plugin_info();

		if ( ! $new_plugin_file || ! is_string( $new_plugin_file ) ) {
			// Fallback: try to resolve by matching the main file name and slug prefix.
			$requested_basename = basename( $requested_plugin_file ); // e.g., hello.php.
			$requested_dir      = dirname( $requested_plugin_file );  // e.g., hello-dolly-1.6.

			// strip version suffix if present.
			$requested_prefix = preg_replace( '/-v?\d+(?:\.\d+)+(?:-(?:alpha|beta|rc|dev|nightly|stable)[\w.-]*)?$/i', '', $requested_dir );

			$plugins    = get_plugins();
			$candidates = array();
			foreach ( array_keys( $plugins ) as $file ) {
				if ( basename( $file ) === $requested_basename ) {
					$candidates[] = $file;
				}
			}

			if ( 1 === count( $candidates ) ) {
				$new_plugin_file = $candidates[0];
			} elseif ( count( $candidates ) > 1 ) {
				// Prefer a candidate whose directory starts with the requested prefix.
				foreach ( $candidates as $candidate ) {
					if ( 0 === strpos( dirname( $candidate ), $requested_prefix ) ) {
						$new_plugin_file = $candidate;
						break;
					}
				}
				// If still not found, fall back to the first candidate.
				if ( empty( $new_plugin_file ) ) {
					$new_plugin_file = $candidates[0];
				}
			}
		}

		// If resolution failed, fall back to the originally requested plugin file.
		if ( empty( $new_plugin_file ) ) {
			$new_plugin_file = $requested_plugin_file;
		}

		// Prepare updated plugin data in response.
		$data = $this->get_plugin_data( $new_plugin_file );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$request['context'] = 'edit';

		return $this->prepare_item_for_response( $data, $request );
	}

	/**
	 * Override validation to use this class pattern allowing dots in path segments.
	 *
	 * @param string $file The plugin file parameter (without .php).
	 * @return bool
	 */
	public function validate_plugin_param( $file ) {
		if ( ! is_string( $file ) || ! preg_match( '/' . self::PATTERN . '/u', $file ) ) {
			return false;
		}

		$validated = validate_file( plugin_basename( $file ) );

		return 0 === $validated;
	}

	/**
	 * Sanitizes the plugin param, preserving existing .php suffix or appending it if missing.
	 *
	 * @param string $file The plugin file parameter.
	 * @return string
	 */
	public function sanitize_plugin_param( $file ) {
		$file = sanitize_text_field( $file );
		if ( substr( $file, -4 ) === '.php' ) {
			return plugin_basename( $file );
		}
		return plugin_basename( $file . '.php' );
	}
}

add_action(
	'rest_api_init',
	function () {
		( new Zero_Admin_REST_Plugins_Controller() )->register_routes();
	}
);
