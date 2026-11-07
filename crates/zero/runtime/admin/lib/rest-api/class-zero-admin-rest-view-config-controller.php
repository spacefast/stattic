<?php
/**
 * REST API: Zero_Admin_REST_View_Config_Controller class
 *
 * @package ZeroAdmin
 * @since 1.0.0
 */

/**
 * Core controller used to access layout view configs via the REST API.
 *
 * @since 1.0.0
 *
 * @see WP_REST_Controller
 */
class Zero_Admin_REST_View_Config_Controller extends WP_REST_Controller {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->namespace = 'wp/v2';
		$this->rest_base = 'view-config';
	}

	/**
	 * Registers the routes for layout view configs.
	 *
	 * @since 1.0.0
	 *
	 * @see register_rest_route()
	 */
	public function register_routes() {
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
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Checks if a given request has access to read layout view configs.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access, WP_Error object otherwise.
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden_context',
				__( 'Sorry, you are not allowed to view layout view configs.', 'zero-admin' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Retrieves layout view configs.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_items( $request ) {
		$kind    = $request->get_param( 'kind' );
		$name    = $request->get_param( 'name' );
		$variant = $request->get_param( 'variant' );

		// Get configuration for the specified entity (with optional variant).
		$config = zero_admin_get_entity_view_config( $kind, $name, $variant );

		// Add metadata to the config for the response.
		$config['kind'] = $kind;
		$config['name'] = $name;
		if ( $variant ) {
			$config['variant'] = $variant;
		}

		$data = $this->prepare_item_for_response( $config, $request );

		return rest_ensure_response( $data );
	}

	/**
	 * Prepares a single layout view config output for response.
	 *
	 * @since 1.0.0
	 *
	 * @param array           $item    Layout view config data with metadata.
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function prepare_item_for_response( $item, $request ) {
		// Extract kind, name, and variant from the item if available.
		$kind    = isset( $item['kind'] ) ? $item['kind'] : '';
		$name    = isset( $item['name'] ) ? $item['name'] : '';
		$variant = isset( $item['variant'] ) ? $item['variant'] : null;

		// Remove metadata fields before constructing response.
		$config = array_diff_key( $item, array_flip( array( 'kind', 'name', 'variant' ) ) );

		// Construct the response with metadata.
		// The 'id' field is the unique identifier for this view config record.
		$id_parts = array( $kind, $name );
		if ( $variant ) {
			$id_parts[] = $variant;
		}
		$data = array_merge(
			array(
				'id'   => implode( '-', $id_parts ),
				'kind' => $kind,
				'name' => $name,
			),
			$config
		);

		if ( $variant ) {
			$data['variant'] = $variant;
		}

		$context = ! empty( $request['context'] ) ? $request['context'] : 'view';
		$data    = $this->add_additional_fields_to_object( $data, $request );
		$data    = $this->filter_response_by_context( $data, $context );

		/**
		 * Filters the layout view config data for a REST API response.
		 *
		 * @since 1.0.0
		 *
		 * @param array            $data    The prepared layout view config data.
		 * @param array            $config  Original configuration data.
		 * @param string           $kind    Entity kind.
		 * @param string           $name    Entity name.
		 * @param string|null      $variant Optional. View config identifier.
		 * @param WP_REST_Request  $request Request used to generate the response.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		return apply_filters( 'rest_prepare_view_config', $data, $config, $kind, $name, $variant, $request );
	}

	/**
	 * Retrieves the layout view config schema, conforming to JSON Schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array Item schema data.
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'view-config',
			'type'       => 'object',
			'properties' => array(
				'id'              => array(
					'description' => __( 'Unique identifier for the layout view config.', 'zero-admin' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'kind'            => array(
					'description' => __( 'Entity kind (postType, taxonomy, root, etc.).', 'zero-admin' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'name'            => array(
					'description' => __( 'Entity name or null for universal collections.', 'zero-admin' ),
					'type'        => array( 'string', 'null' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'variant'         => array(
					'description' => __( 'Optional view config identifier.', 'zero-admin' ),
					'type'        => array( 'string', 'null' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'labels'          => array(
					'description' => __( 'Labels for the entity.', 'zero-admin' ),
					'type'        => 'object',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'default_view'    => array(
					'description' => __( 'Default view configuration.', 'zero-admin' ),
					'type'        => 'object',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'default_layouts' => array(
					'description' => __( 'Default layouts configuration.', 'zero-admin' ),
					'type'        => 'object',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'quick_edit_form' => array(
					'description' => __( 'Quick edit form configuration.', 'zero-admin' ),
					'type'        => 'object',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'add_form'        => array(
					'description' => __( 'Add form configuration.', 'zero-admin' ),
					'type'        => 'object',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'edit_form'       => array(
					'description' => __( 'Edit form configuration.', 'zero-admin' ),
					'type'        => 'object',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'tabs'            => array(
					'description' => __( 'Available tabs configuration.', 'zero-admin' ),
					'type'        => 'array',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'allowed_fields'  => array(
					'description' => __( 'Optional list of field IDs available in this view context.', 'zero-admin' ),
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
					),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
			),
		);

		$this->schema = $schema;

		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * Retrieves the query params for layout view configs.
	 *
	 * @since 1.0.0
	 *
	 * @return array Collection parameters.
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();

		$params['kind'] = array(
			'description'       => __( 'Entity kind (postType, taxonomy, root etc.).', 'zero-admin' ),
			'type'              => 'string',
			'required'          => true,
			'sanitize_callback' => function ( $value ) {
				// Case-preserving sanitizer — sanitize_key() lowercases, which
				// breaks camelCase entity kinds like 'postType'.
				return preg_replace( '/[^a-zA-Z0-9_-]/', '', $value );
			},
		);

		$params['name'] = array(
			'description'       => __( 'Entity name (post, product, user, etc.).', 'zero-admin' ),
			'type'              => 'string',
			'required'          => true,
			'sanitize_callback' => function ( $value ) {
				return preg_replace( '/[^a-zA-Z0-9_-]/', '', $value );
			},
		);

		$params['variant'] = array(
			'description' => __( 'Optional view config identifier.', 'zero-admin' ),
			'type'        => 'string',
			'pattern'     => '^[a-z0-9_\/-]+$',
			'required'    => false,
		);

		return $params;
	}
}

add_action(
	'rest_api_init',
	function () {
		( new Zero_Admin_REST_View_Config_Controller() )->register_routes();
	}
);
