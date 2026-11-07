<?php
/**
 * REST API: Zero_Admin_REST_Resolved_Template_Controller class
 *
 * Resolves a complete template and all its block dependencies (template parts,
 * navigation posts, menus, pages) in a single server-side round-trip.
 *
 * @package ZeroAdmin
 * @since 1.0.0
 */

/**
 * Controller that returns all entities needed for canvas template resolution.
 *
 * Mirrors the client-side getTemplateId selector logic and preloadBlockContent
 * waterfall, performing all resolution server-side with zero network latency
 * between steps.
 *
 * @since 1.0.0
 *
 * @see WP_REST_Controller
 */
class Zero_Admin_REST_Resolved_Template_Controller extends WP_REST_Controller {

	/**
	 * Collected entity records keyed by "{kind}/{name}".
	 *
	 * @var array<string, array<string|int, array>>
	 */
	private $entities = array();

	/**
	 * Collected queries keyed by "{kind}/{name}".
	 *
	 * @var array<string, array<int, array>>
	 */
	private $queries = array();

	/**
	 * Query parameters for singular entity records, keyed by "{kind}/{name}" then entity ID.
	 *
	 * Used to finish resolution for getEntityRecord calls that include query
	 * parameters (e.g. { context: 'edit' }), preventing unnecessary re-fetches.
	 *
	 * @var array<string, array<string|int, array>>
	 */
	private $record_queries = array();

	/**
	 * Set of already-processed block dependency keys for deduplication.
	 *
	 * @var array<string, true>
	 */
	private $processed = array();

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->namespace = 'wp/v2';
		$this->rest_base = 'resolved-template';
	}

	/**
	 * Registers the route.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'post_type' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'post_id'   => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission check — matches canvas requirement.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to resolve templates.', 'zero-admin' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * Resolves the template and all block dependencies.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		// Must be a block theme.
		if ( ! wp_get_theme()->is_block_theme() ) {
			return new WP_Error(
				'rest_not_block_theme',
				__( 'The active theme is not a block theme.', 'zero-admin' ),
				array( 'status' => 400 )
			);
		}

		$post_type = $request->get_param( 'post_type' );
		$post_id   = $request->get_param( 'post_id' );

		// Collect site settings via internal dispatch.
		$this->collect_site_settings();

		// Resolve the template ID.
		$template_id = $this->resolve_template_id( $post_type, $post_id );

		if ( is_wp_error( $template_id ) ) {
			return $template_id;
		}

		// Collect all templates (the client always fetches per_page:-1).
		$this->collect_all_templates();

		// If we have a specific post, collect it.
		if ( $post_type && $post_id ) {
			$this->collect_post( $post_type, $post_id );
		}

		// Fetch the resolved template and recursively collect block dependencies.
		if ( $template_id ) {
			$template = get_block_template( $template_id, 'wp_template' );
			if ( $template && ! empty( $template->content ) ) {
				$this->collect_block_dependencies( $template->content );
			}
		}

		return rest_ensure_response(
			array(
				'template_id' => $template_id,
				'entities'    => $this->build_entity_groups(),
			)
		);
	}

	/**
	 * Resolves the template ID, mirroring client-side getTemplateId selector.
	 *
	 * @param string|null $post_type Post type.
	 * @param int|null    $post_id   Post ID.
	 * @return string|null|WP_Error Template ID or null.
	 */
	private function resolve_template_id( $post_type, $post_id ) {
		$show_on_front  = get_option( 'show_on_front' );
		$page_on_front  = $this->normalize_page_id( get_option( 'page_on_front' ) );
		$page_for_posts = $this->normalize_page_id( get_option( 'page_for_posts' ) );

		// No params or front page → front page resolution.
		if (
			( ! $post_type && ! $post_id ) ||
			( 'page' === $post_type && $page_on_front && (string) $post_id === $page_on_front )
		) {
			$templates           = get_block_templates( array(), 'wp_template' );
			$front_page_template = null;

			foreach ( $templates as $template ) {
				if ( 'front-page' === $template->slug ) {
					$front_page_template = $template;
					break;
				}
			}

			if ( $front_page_template ) {
				return $front_page_template->id;
			}

			// Fall back to page template for page_on_front.
			if ( $page_on_front && 'page' === $show_on_front ) {
				$post_type = 'page';
				$post_id   = (int) $page_on_front;
			} else {
				// Use default front-page template.
				return $this->get_default_template_id( 'front-page' );
			}
		}

		// Posts page.
		if ( 'page' === $post_type && $page_for_posts && (string) $post_id === $page_for_posts ) {
			return $this->get_default_template_id( 'home' );
		}

		// Specific post — check assigned template.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error(
				'rest_post_not_found',
				__( 'Post not found.', 'zero-admin' ),
				array( 'status' => 404 )
			);
		}

		if ( ! empty( $post->page_template ) && 'default' !== $post->page_template ) {
			$templates = get_block_templates( array(), 'wp_template' );
			foreach ( $templates as $template ) {
				if ( $template->slug === $post->page_template ) {
					return $template->id;
				}
			}
		}

		// Default template for this post type.
		$slug = $this->get_template_slug( $post->post_type, $post->post_name );
		return $this->get_default_template_id( $slug );
	}

	/**
	 * Gets the default template ID for a given slug via internal REST dispatch.
	 *
	 * Uses the /wp/v2/templates/lookup endpoint which handles the full
	 * template hierarchy fallback, matching the client-side getDefaultTemplateId.
	 *
	 * @param string $slug Template slug.
	 * @return string|null Template ID.
	 */
	private function get_default_template_id( $slug ) {
		$request = new WP_REST_Request( 'GET', '/wp/v2/templates/lookup' );
		$request->set_param( 'slug', $slug );
		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			return null;
		}

		$data = rest_get_server()->response_to_data( $response, false );
		return isset( $data['id'] ) ? $data['id'] : null;
	}

	/**
	 * Mirrors client-side getTemplateSlug utility.
	 *
	 * @param string $post_type Post type.
	 * @param string $post_slug Post slug.
	 * @return string Template slug.
	 */
	private function get_template_slug( $post_type, $post_slug = '' ) {
		if ( $post_slug ) {
			return 'page' === $post_type
				? "{$post_type}-{$post_slug}"
				: "single-{$post_type}-{$post_slug}";
		}
		return 'page' === $post_type ? 'page' : "single-{$post_type}";
	}

	/**
	 * Mirrors client-side normalizePageId utility.
	 *
	 * @param mixed $value Value to normalize.
	 * @return string|null Normalized page ID or null.
	 */
	private function normalize_page_id( $value ) {
		if ( empty( $value ) || 0 === (int) $value ) {
			return null;
		}
		return (string) $value;
	}

	/**
	 * Recursively collects block dependencies from template/part content.
	 *
	 * @param string $content Block content to parse.
	 */
	private function collect_block_dependencies( $content ) {
		if ( empty( $content ) ) {
			return;
		}

		$blocks = parse_blocks( $content );
		$this->process_blocks( $blocks );
	}

	/**
	 * Processes a list of blocks, collecting dependencies.
	 *
	 * @param array $blocks Parsed blocks.
	 */
	private function process_blocks( $blocks ) {
		foreach ( $blocks as $block ) {
			$this->process_single_block( $block );

			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->process_blocks( $block['innerBlocks'] );
			}
		}
	}

	/**
	 * Processes a single block for dependencies.
	 *
	 * @param array $block Parsed block.
	 */
	private function process_single_block( $block ) {
		if ( empty( $block['blockName'] ) ) {
			return;
		}

		if ( 'core/template-part' === $block['blockName'] ) {
			$this->collect_template_part( $block );
		} elseif ( 'core/navigation' === $block['blockName'] ) {
			$this->collect_navigation( $block );
		} elseif ( 'core/page-list' === $block['blockName'] ) {
			$this->collect_page_list();
		}
	}

	/**
	 * Collects a template part and recurses into its content.
	 *
	 * @param array $block Parsed template-part block.
	 */
	private function collect_template_part( $block ) {
		$attrs = $block['attrs'] ?? array();
		$theme = $attrs['theme'] ?? '';
		$slug  = $attrs['slug'] ?? '';

		if ( ! $theme || ! $slug ) {
			return;
		}

		$part_id = "{$theme}//{$slug}";

		// Deduplicate to avoid infinite recursion.
		if ( isset( $this->processed[ "template_part:{$part_id}" ] ) ) {
			return;
		}
		$this->processed[ "template_part:{$part_id}" ] = true;

		$part = get_block_template( $part_id, 'wp_template_part' );
		if ( ! $part ) {
			return;
		}

		// Collect via internal dispatch for proper REST format.
		$this->collect_entity_via_dispatch(
			'postType',
			'wp_template_part',
			$part_id,
			'/wp/v2/template-parts/' . rawurlencode( $part_id ) . '?context=edit'
		);

		// Recurse into the part's content.
		if ( ! empty( $part->content ) ) {
			$this->collect_block_dependencies( $part->content );
		}
	}

	/**
	 * Collects navigation-related entities.
	 *
	 * @param array $block Parsed navigation block.
	 */
	private function collect_navigation( $block ) {
		$attrs = $block['attrs'] ?? array();

		// Collect menus.
		// The client query uses per_page:-1, but the menus REST endpoint only
		// accepts per_page:1-100. Dispatch with 100 to get all, but record
		// the client query with -1 so finishResolution matches.
		if ( ! isset( $this->processed['menus'] ) ) {
			$this->processed['menus'] = true;

			$client_query    = array(
				'per_page' => -1,
				'context'  => 'view',
			);
			$dispatch_params = array(
				'per_page' => 100,
				'context'  => 'view',
			);
			$this->collect_records_via_dispatch(
				'root',
				'menu',
				'/wp/v2/menus?' . http_build_query( $dispatch_params ),
				$client_query
			);
		}

		// Collect top-level pages for navigation.
		// Same per_page:-1 → 100 translation as menus.
		if ( ! isset( $this->processed['nav_pages'] ) ) {
			$this->processed['nav_pages'] = true;

			$client_query    = array(
				'parent'   => 0,
				'order'    => 'asc',
				'orderby'  => 'id',
				'per_page' => -1,
				'context'  => 'view',
			);
			$dispatch_params = array(
				'parent'   => 0,
				'order'    => 'asc',
				'orderby'  => 'id',
				'per_page' => 100,
				'context'  => 'view',
			);
			$this->collect_records_via_dispatch(
				'postType',
				'page',
				'/wp/v2/pages?' . http_build_query( $dispatch_params ),
				$client_query
			);
		}

		// Collect navigation posts.
		if ( ! isset( $this->processed['nav_posts'] ) ) {
			$this->processed['nav_posts'] = true;

			$query = array(
				'per_page' => 100,
				'status'   => array( 'publish', 'draft' ),
				'order'    => 'desc',
				'orderby'  => 'date',
			);
			$path  = '/wp/v2/navigation?' . http_build_query(
				array(
					'per_page' => 100,
					'status'   => 'publish,draft',
					'order'    => 'desc',
					'orderby'  => 'date',
				)
			);
			$this->collect_records_via_dispatch(
				'postType',
				'wp_navigation',
				$path,
				$query
			);
		}

		// Collect specific navigation post if ref exists.
		if ( ! empty( $attrs['ref'] ) ) {
			$ref     = (int) $attrs['ref'];
			$ref_key = "nav_ref:{$ref}";
			if ( ! isset( $this->processed[ $ref_key ] ) ) {
				$this->processed[ $ref_key ] = true;

				$this->collect_entity_via_dispatch(
					'postType',
					'wp_navigation',
					$ref,
					'/wp/v2/navigation/' . $ref . '?context=edit'
				);

				// Recurse into navigation post content.
				$nav_post = get_post( $ref );
				if ( $nav_post && ! empty( $nav_post->post_content ) ) {
					$this->collect_block_dependencies( $nav_post->post_content );
				}
			}
		} elseif ( ! isset( $this->processed['nav_fallback'] ) ) {
			// Fallback: get most recent published wp_navigation post.
			// Note: the client-side waterfall uses getNavigationFallbackId()
			// which can *create* a fallback post if none exists. We
			// intentionally only read here — post creation is a side effect
			// this endpoint should not have. In practice a navigation post
			// will already exist; if not, the client waterfall fallback
			// handles creation.
			$this->processed['nav_fallback'] = true;

			$fallback_posts = get_posts(
				array(
					'post_type'   => 'wp_navigation',
					'post_status' => 'publish',
					'orderby'     => 'date',
					'order'       => 'DESC',
					'numberposts' => 1,
				)
			);

			if ( ! empty( $fallback_posts ) ) {
				$fallback = $fallback_posts[0];
				$this->collect_entity_via_dispatch(
					'postType',
					'wp_navigation',
					$fallback->ID,
					'/wp/v2/navigation/' . $fallback->ID . '?context=edit'
				);

				if ( ! empty( $fallback->post_content ) ) {
					$this->collect_block_dependencies( $fallback->post_content );
				}
			}
		}
	}

	/**
	 * Collects pages for core/page-list block.
	 */
	private function collect_page_list() {
		if ( isset( $this->processed['page_list'] ) ) {
			return;
		}
		$this->processed['page_list'] = true;

		$query = array(
			'per_page' => 100,
			'_fields'  => array( 'id', 'link', 'menu_order', 'parent', 'title', 'type' ),
			'orderby'  => 'menu_order',
			'order'    => 'asc',
		);
		$path  = '/wp/v2/pages?' . http_build_query(
			array(
				'per_page' => 100,
				'_fields'  => 'id,link,menu_order,parent,title,type',
				'orderby'  => 'menu_order',
				'order'    => 'asc',
			)
		);
		$this->collect_records_via_dispatch(
			'postType',
			'page',
			$path,
			$query
		);
	}

	/**
	 * Collects site settings via internal REST dispatch.
	 */
	private function collect_site_settings() {
		$request  = new WP_REST_Request( 'GET', '/wp/v2/settings' );
		$response = rest_do_request( $request );

		if ( ! $response->is_error() ) {
			$key                    = 'root/site';
			$this->entities[ $key ] = array( 0 => $response->get_data() );
			$this->queries[ $key ]  = array();
		}
	}

	/**
	 * Collects all templates (matching client per_page:-1 query).
	 */
	private function collect_all_templates() {
		if ( isset( $this->processed['all_templates'] ) ) {
			return;
		}
		$this->processed['all_templates'] = true;

		$query = array( 'per_page' => -1 );
		$this->collect_records_via_dispatch(
			'postType',
			'wp_template',
			'/wp/v2/templates?per_page=-1&context=edit',
			$query
		);
	}

	/**
	 * Collects a specific post via internal dispatch.
	 *
	 * @param string $post_type Post type.
	 * @param int    $post_id   Post ID.
	 */
	private function collect_post( $post_type, $post_id ) {
		$rest_base = $this->get_rest_base( $post_type );
		if ( ! $rest_base ) {
			return;
		}

		$this->collect_entity_via_dispatch(
			'postType',
			$post_type,
			$post_id,
			"/wp/v2/{$rest_base}/{$post_id}?context=edit"
		);
	}

	/**
	 * Gets the REST base for a post type.
	 *
	 * @param string $post_type Post type.
	 * @return string|null REST base or null.
	 */
	private function get_rest_base( $post_type ) {
		$post_type_obj = get_post_type_object( $post_type );
		if ( ! $post_type_obj || empty( $post_type_obj->rest_base ) ) {
			return null;
		}
		return $post_type_obj->rest_base;
	}

	/**
	 * Collects a single entity record via internal REST dispatch.
	 *
	 * @param string     $kind Entity kind.
	 * @param string     $name Entity name.
	 * @param string|int $id   Entity ID.
	 * @param string     $path REST API path.
	 */
	private function collect_entity_via_dispatch( $kind, $name, $id, $path ) {
		$key = "{$kind}/{$name}";

		// Deduplicate by ID.
		if ( isset( $this->entities[ $key ][ $id ] ) ) {
			return;
		}

		$request      = new WP_REST_Request( 'GET', wp_parse_url( $path, PHP_URL_PATH ) );
		$query_string = wp_parse_url( $path, PHP_URL_QUERY );
		if ( $query_string ) {
			parse_str( $query_string, $params );
			foreach ( $params as $param_key => $param_value ) {
				$request->set_param( $param_key, $param_value );
			}
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return;
		}

		if ( ! isset( $this->entities[ $key ] ) ) {
			$this->entities[ $key ] = array();
			$this->queries[ $key ]  = array();
		}

		$record = rest_get_server()->response_to_data( $response, false );

		$this->entities[ $key ][ $id ] = $this->normalize_record( $name, $record, $id );

		if ( $query_string ) {
			$this->record_queries[ $key ][ $id ] = $params;
		}
	}

	/**
	 * Collects multiple entity records via internal REST dispatch.
	 *
	 * @param string $kind  Entity kind.
	 * @param string $name  Entity name.
	 * @param string $path  REST API path.
	 * @param array  $query Query parameters to record for finishResolution.
	 */
	private function collect_records_via_dispatch( $kind, $name, $path, $query ) {
		$key = "{$kind}/{$name}";

		$request      = new WP_REST_Request( 'GET', wp_parse_url( $path, PHP_URL_PATH ) );
		$query_string = wp_parse_url( $path, PHP_URL_QUERY );
		if ( $query_string ) {
			parse_str( $query_string, $params );
			foreach ( $params as $param_key => $param_value ) {
				$request->set_param( $param_key, $param_value );
			}
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return;
		}

		if ( ! isset( $this->entities[ $key ] ) ) {
			$this->entities[ $key ] = array();
			$this->queries[ $key ]  = array();
		}

		$records = rest_get_server()->response_to_data( $response, false );
		if ( is_array( $records ) ) {
			foreach ( $records as $record ) {
				$id = isset( $record['id'] ) ? $record['id'] : null;
				if ( null !== $id && ! isset( $this->entities[ $key ][ $id ] ) ) {
					$this->entities[ $key ][ $id ] = $this->normalize_record( $name, $record, $id );
				}
			}
		}

		$this->queries[ $key ][] = $query;
	}

	/**
	 * Normalizes REST records before injecting them into the resolved payload.
	 *
	 * The canvas preload path expects raw entity data, so always source
	 * content.raw and title.raw from the underlying entity when a record
	 * exposes those REST fields.
	 *
	 * @param string          $name   Entity name.
	 * @param mixed           $record REST record.
	 * @param string|int|null $id     Entity ID.
	 * @return mixed Normalized record.
	 */
	private function normalize_record( $name, $record, $id = null ) {
		if ( ! is_array( $record ) ) {
			return $record;
		}

		$template_id = null !== $id ? $id : ( $record['id'] ?? null );
		if ( ! is_string( $template_id ) && ! is_int( $template_id ) ) {
			return $record;
		}

		if ( in_array( $name, array( 'wp_template', 'wp_template_part' ), true ) ) {
			$template = get_block_template( (string) $template_id, $name );
			if ( $template ) {
				if ( isset( $record['content'] ) && is_array( $record['content'] ) ) {
					$record['content']['raw'] = isset( $template->content ) && is_string( $template->content )
						? $template->content
						: '';
				}
				if ( isset( $record['title'] ) && is_array( $record['title'] ) ) {
					$record['title']['raw'] = isset( $template->title ) && is_string( $template->title )
						? $template->title
						: '';
				}
				return $record;
			}
		} else {
			$post = get_post( (int) $template_id );
			if ( $post ) {
				if ( isset( $record['content'] ) && is_array( $record['content'] ) ) {
					$record['content']['raw'] = $post->post_content;
				}
				if ( isset( $record['title'] ) && is_array( $record['title'] ) ) {
					$record['title']['raw'] = $post->post_title;
				}
				return $record;
			}
		}

		return $record;
	}

	/**
	 * Builds the entity groups for the response.
	 *
	 * @return array Entity groups.
	 */
	private function build_entity_groups() {
		$groups = array();

		foreach ( $this->entities as $key => $records ) {
			list( $kind, $name ) = explode( '/', $key, 2 );

			$record_queries = array();
			if ( isset( $this->record_queries[ $key ] ) ) {
				foreach ( $this->record_queries[ $key ] as $id => $query ) {
					$record_queries[] = array(
						'id'    => $id,
						'query' => $query,
					);
				}
			}

			$groups[] = array(
				'kind'           => $kind,
				'name'           => $name,
				'records'        => array_values( $records ),
				'queries'        => isset( $this->queries[ $key ] ) ? $this->queries[ $key ] : array(),
				'record_queries' => $record_queries,
			);
		}

		return $groups;
	}
}

// Register the resolved template REST controller.
add_action(
	'rest_api_init',
	function () {
		( new Zero_Admin_REST_Resolved_Template_Controller() )->register_routes();
	}
);
