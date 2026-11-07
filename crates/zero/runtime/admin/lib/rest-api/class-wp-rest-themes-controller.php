<?php
/**
 * REST API: Zero_Admin_REST_Themes_Controller class
 *
 * @package Zero_Admin
 * @subpackage REST_API
 */

/**
 * REST API: Zero Admin Themes Controller
 *
 * Handles theme update via the REST API.
 *
 * @TODO: Remove once the update themes endpoint is available on core (see [upstream private reference omitted])
 *
 * @package Zero_Admin
 * @since   1.0.0
 * @version 1.0.0
 */
class Zero_Admin_REST_Themes_Controller extends WP_REST_Themes_Controller {

	/**
	 * Registers the routes for the controller.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			sprintf( '/%s/(?P<stylesheet>%s)', $this->rest_base, self::PATTERN ),
			array(
				'args'   => array(
					'stylesheet' => array(
						'description'       => __( "The theme's stylesheet. This uniquely identifies the theme.", 'zero-admin' ),
						'type'              => 'string',
						'sanitize_callback' => array( $this, '_sanitize_stylesheet_callback' ),
						'validate_callback' => array( $this, 'validate_theme' ),
					),
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
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Checks if a given request has access to delete the theme.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return true|WP_Error True if the request has delete access for the item, otherwise WP_Error object.
	 */
	public function delete_item_permissions_check( $request ) {
		if ( current_user_can( 'delete_themes' ) && ! is_multisite() ) {
			return true;
		}

		return new WP_Error(
			'rest_cannot_delete_themes',
			__( 'Sorry, you are not allowed to delete themes.', 'zero-admin' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Deletes the theme.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response Response object.
	 */
	public function delete_item( $request ) {
		// Include the admin theme functions.
		if ( ! function_exists( 'delete_theme' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}

		// Include file functions if needed.
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$theme = wp_get_theme( $request['stylesheet'] );

		if ( ! $theme->exists() ) {
			return new WP_Error(
				'rest_theme_not_found',
				__( 'The requested theme does not exist.', 'zero-admin' ),
				array( 'status' => 404 )
			);
		}

		$result = delete_theme( $request['stylesheet'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'message' => __( 'Theme deleted successfully.', 'zero-admin' ),
			)
		);
	}

	/**
	 * Checks if a given request has access to update the theme.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return true|WP_Error True if the request has update access for the item, otherwise WP_Error object.
	 */
	public function update_item_permissions_check( $request ) {
		if ( isset( $request['status'] ) ) {
			if ( ! current_user_can( 'switch_themes' ) && ! current_user_can( 'manage_network_themes' ) ) {
				return new WP_Error(
					'rest_cannot_activate_themes',
					__( 'Sorry, you are not allowed to activate themes.', 'zero-admin' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}
		}

		if ( isset( $request['version'] ) || isset( $request['auto_updates'] ) ) {
			if ( ! current_user_can( 'update_themes' ) ) {
				return new WP_Error(
					'rest_cannot_update_themes',
					__( 'Sorry, you are not allowed to update themes.', 'zero-admin' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}
		}

		return true;
	}

	/**
	 * Validates the theme.
	 *
	 * @param string $stylesheet The stylesheet of the theme.
	 *
	 * @return true|WP_Error True if the theme is valid, otherwise WP_Error object.
	 */
	public function validate_theme( $stylesheet ) {
		$theme = wp_get_theme( $stylesheet );

		if ( ! $theme->exists() ) {
			return new WP_Error(
				'rest_theme_not_found',
				__( 'The requested theme does not exist.', 'zero-admin' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $theme->is_allowed() ) {
			return new WP_Error(
				'rest_theme_not_allowed',
				__( 'The requested theme is not network enabled.', 'zero-admin' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Updates the theme.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response Response object.
	 */
	public function update_item( $request ) {
		$stylesheet   = $request['stylesheet'];
		$theme        = wp_get_theme( $stylesheet );
		$active_theme = wp_get_theme();

		$new_status                       = $request['status'];
		$theme_under_edit_is_active_theme = $this->is_same_theme( $theme, $active_theme );

		if ( 'active' === $new_status ) {
			if ( $theme_under_edit_is_active_theme ) {
				// Nothing to do, the theme is already active.
				return rest_ensure_response( $this->prepare_item_for_response( $theme, $request ) );
			} else {
				if ( ! is_wp_version_compatible( $theme->get( 'RequiresWP' ) ) ) {
					return new WP_Error(
						'rest_theme_not_compatible',
						__( 'The requested theme is not compatible with your WordPress version.', 'zero-admin' ),
						array( 'status' => 400 )
					);
				}

				if ( ! is_php_version_compatible( $theme->get( 'RequiresPHP' ) ) ) {
					return new WP_Error(
						'rest_theme_not_compatible',
						__( 'The requested theme is not compatible with your PHP version.', 'zero-admin' ),
						array( 'status' => 400 )
					);
				}

				switch_theme( $stylesheet );
			}
		}

		if ( 'inactive' === $new_status ) {
			if ( $theme_under_edit_is_active_theme ) {
				// Activate the default theme instead.
				$default_theme = wp_get_theme( WP_DEFAULT_THEME );
				if ( $default_theme->exists() ) {
					switch_theme( WP_DEFAULT_THEME );
				}
			} else {
				// Nothing to do, the theme is already inactive.
				return rest_ensure_response( $this->prepare_item_for_response( $theme, $request ) );
			}
		}

		// Handle theme version update.
		if ( isset( $request['version'] ) ) {
			// Check for available updates.
			$current = get_site_transient( 'update_themes' );
			if ( empty( $current ) ) {
				wp_update_themes();
			}
			$current = get_site_transient( 'update_themes' );

			$is_requesting_update_to_new_version =
				isset( $current->response[ $stylesheet ] ) &&
				version_compare( $current->response[ $stylesheet ]['new_version'], $request['version'], '=' );
			if ( $is_requesting_update_to_new_version ) {
				// Include the base upgrader class first.
				if ( ! class_exists( 'WP_Upgrader_Skin' ) ) {
					require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
				}

				// Include the base upgrader skin class first.
				if ( ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
					require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
				}

				// Include the admin upgrade functions.
				if ( ! class_exists( 'WP_Ajax_Upgrader_Skin' ) ) {
					require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
				}

				// Include the base upgrader class first.
				if ( ! class_exists( 'WP_Upgrader' ) ) {
					require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
				}

				// Include the theme upgrader class.
				if ( ! class_exists( 'Theme_Upgrader' ) ) {
					require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
				}

				// Include file functions if needed.
				if ( ! function_exists( 'WP_Filesystem' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}

				$skin     = new WP_Ajax_Upgrader_Skin();
				$upgrader = new Theme_Upgrader( $skin );
				$result   = $upgrader->bulk_upgrade( array( $stylesheet ) );

				if ( is_wp_error( $skin->result ) ) {
					return new WP_Error(
						$skin->result->get_error_code(),
						$skin->result->get_error_message(),
						array( 'status' => 400 )
					);
				} elseif ( $skin->get_errors()->has_errors() ) {
					return new WP_Error(
						'rest_theme_update_failed',
						$skin->result->get_error_messages(),
						array( 'status' => 400 )
					);
				} elseif ( is_array( $result ) && ! empty( $result[ $stylesheet ] ) ) {
					// Theme is already at the latest version.
					if ( true === $result[ $stylesheet ] ) {
						return new WP_Error(
							'rest_theme_update_failed',
							$upgrader->strings['up_to_date'],
							array( 'status' => 400 )
						);
					}

					// Successfully updated the theme, no need to return an error.
				} elseif ( false === $result ) {
					global $wp_filesystem;

					$error_message = __( 'Unable to connect to the filesystem. Please confirm your credentials.', 'zero-admin' );

					// Pass through the error from WP_Filesystem if one was raised.
					if ( $wp_filesystem instanceof WP_Filesystem_Base && is_wp_error( $wp_filesystem->errors ) && $wp_filesystem->errors->has_errors() ) {
						$error_message = esc_html( $wp_filesystem->errors->get_error_message() );
					}

					return new WP_Error(
						'unable_to_connect_to_filesystem',
						$error_message,
						array( 'status' => 400 )
					);
				} else {
					// An unhandled error occurred.
					return new WP_Error(
						'rest_theme_update_failed',
						__( 'Theme update failed.', 'zero-admin' ),
						array( 'status' => 400 )
					);
				}
			}
		}

		// Handle theme auto-updates setting.
		if ( isset( $request['auto_updates']['enabled'] ) ) {
			$next_auto_updates_enabled = rest_sanitize_boolean( $request['auto_updates']['enabled'] );
			$auto_updates_data         = zero_admin_get_theme_auto_updates_data(
				array( 'stylesheet' => $stylesheet )
			);

			$is_requesting_auto_updates_toggle = $next_auto_updates_enabled !== $auto_updates_data['enabled'];

			if ( $is_requesting_auto_updates_toggle ) {
				if ( ! $auto_updates_data['supported'] ) {
					return new WP_Error(
						'rest_theme_not_auto_update_supported',
						__( 'The requested theme does not support auto-updates.', 'zero-admin' ),
						array( 'status' => 400 )
					);
				}

				if ( $auto_updates_data['forced'] && false === $next_auto_updates_enabled ) {
					return new WP_Error(
						'rest_theme_auto_update_forced',
						__( 'Auto-updates are forced for this theme and cannot be disabled.', 'zero-admin' ),
						array( 'status' => 400 )
					);
				}

				$themes_set_to_auto_update      = (array) get_site_option( 'auto_update_themes', array() );
				$auto_updates_currently_enabled = in_array( $stylesheet, $themes_set_to_auto_update, true );

				$should_update_option = false;
				if ( $next_auto_updates_enabled && ! $auto_updates_currently_enabled ) {
					$should_update_option           = true;
					$next_themes_set_to_auto_update = array_unique(
						array(
							...$themes_set_to_auto_update,
							$stylesheet,
						)
					);
				} elseif ( ! $next_auto_updates_enabled && $auto_updates_currently_enabled ) {
					$should_update_option           = true;
					$next_themes_set_to_auto_update = array_diff(
						$themes_set_to_auto_update,
						array( $stylesheet )
					);
				}

				if ( $should_update_option ) {
					// Remove themes that have been deleted since the site option was last updated.
					$all_themes                     = wp_get_themes();
					$next_themes_set_to_auto_update = array_intersect(
						$next_themes_set_to_auto_update,
						array_keys( $all_themes )
					);

					update_site_option( 'auto_update_themes', $next_themes_set_to_auto_update );
				}
			}
		}

		$request['context'] = 'edit';

		return $this->prepare_item_for_response( $theme, $request );
	}
}

add_action(
	'rest_api_init',
	function () {
		( new Zero_Admin_REST_Themes_Controller() )->register_routes();
	}
);
