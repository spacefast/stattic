<?php
/**
 * REST API: Zero_Admin_WP_REST_Attachments_Controller class
 *
 * @package Zero_Admin
 * @since 1.0.0
 */

/**
 * Core controller used to access attachments via the REST API.
 *
 * @since 1.0.0
 * @since x.x.x Support media type and mime type filtering.
 *
 * @see WP_REST_Attachments_Controller
 */
class Zero_Admin_REST_Attachments_Controller extends WP_REST_Attachments_Controller {
	/**
	 * Determines the allowed query_vars for a get_items() response and
	 * prepares for WP_Query.
	 *
	 * @since 4.7.0
	 * @since ?.?.? Added orderby_mime_type filter to add custom ordering.
	 * @since ?.?.? Extends the `media_type` and `mime_type` request arguments to support array values.
	 *
	 * @param array           $prepared_args Optional. Array of prepared arguments. Default empty array.
	 * @param WP_REST_Request $request       Optional. Request to prepare items for.
	 * @return array Array of query arguments.
	 */
	protected function prepare_items_query( $prepared_args = array(), $request = null ) {
		$query_args = parent::prepare_items_query( $prepared_args, $request );

		if ( isset( $query_args['orderby'] ) && isset( $request['orderby'] ) && 'mime_type' === $request['orderby'] ) {
			/*
			 * This filter is used to add custom ordering to the query.
			 * The parameter `post_mime_type` should be added in WP_Query:parse_orderby().
			 * src/wp-includes/class-wp-query.php. See: https://github.com/WordPress/wordpress-develop/pull/10135.
			 */
			add_filter( 'posts_orderby', array( $this, 'orderby_mime_type' ), 10, 2 );
			$query_args['orderby'] = 'post_mime_type';
		}

		$all_mime_types = array();
		$media_types    = $this->get_media_types();

		/*
		 * There is a Core patch for filtering attachments by multiple media/mime types in https://github.com/WordPress/wordpress-develop/pull/10054.
		 * Can be removed when remove once required WordPress version >= 6.9.
		 */
		if ( ! empty( $request['media_type'] ) ) {
			$requested_media_types = is_array( $request['media_type'] ) ? $request['media_type'] : explode( ',', $request['media_type'] );
			foreach ( $requested_media_types as $type ) {
				if ( isset( $media_types[ $type ] ) ) {
					$all_mime_types = array_merge( $all_mime_types, $media_types[ $type ] );
				}
			}
		}

		/*
		 * There is a Core patch for for filtering attachments by multiple media/mime types in https://github.com/WordPress/wordpress-develop/pull/10054.
		 * Can be removed when remove once required WordPress version >= 6.9.
		 */
		if ( ! empty( $request['mime_type'] ) ) {
			$requested_mime_types = is_array( $request['mime_type'] ) ? $request['mime_type'] : explode( ',', $request['mime_type'] );
			foreach ( $requested_mime_types as $mime_type ) {
				$parts = explode( '/', $mime_type );
				if ( isset( $media_types[ $parts[0] ] ) && in_array( $mime_type, $media_types[ $parts[0] ], true ) ) {
					$all_mime_types[] = $mime_type;
				}
			}
		}

		if ( ! empty( $all_mime_types ) ) {
			$query_args['post_mime_type'] = array_values( array_unique( $all_mime_types ) );
		}

		/*
		 * Ensure deterministic ordering to prevent duplicate records across pages.
		 * When multiple attachments have the same post_date, add ID as secondary sort to guarantee consistent ordering.
		 * Note: this is to circumvent a bug that is currently being tracked in https://core.trac.wordpress.org/ticket/44349.
		 */
		if ( ! empty( $query_args['orderby'] ) ) {
			if ( is_array( $query_args['orderby'] ) ) {
				$query_args['orderby']['ID'] = 'DESC';
			} else {
				$query_args['orderby'] .= ', ID DESC';
			}
		}

		return $query_args;
	}

	/**
	 * Ensures deterministic ordering for attachments by MIME type.
	 * When multiple attachments have the same MIME type, adds ID as secondary sort to guarantee consistent ordering.
	 * This prevents the same attachment from appearing on multiple pages when filtering by MIME type.
	 * The parameter `post_mime_type` should be added in WP_Query:parse_orderby().
	 * src/wp-includes/class-wp-query.php. See: https://github.com/WordPress/wordpress-develop/pull/10135.
	 *
	 * @param string   $orderby The orderby clause.
	 * @param WP_Query $query The query object.
	 * @return string The orderby clause.
	 */
	public function orderby_mime_type( $orderby, $query ) {
		$query_orderby = $query->get( 'orderby' );

		if ( ( is_string( $query_orderby ) && strpos( $query_orderby, 'post_mime_type' ) !== false ) ||
			( is_array( $query_orderby ) && array_key_exists( 'post_mime_type', $query_orderby ) ) ) {
			global $wpdb;
			$order = $query->get( 'order' );

			/*
			 * Ensure deterministic ordering to prevent duplicate records across pages.
			 * When multiple attachments have the same post_mime_type, add ID as secondary sort to guarantee consistent ordering.
			 * This prevents the same attachment from appearing on multiple pages when filtering by post_mime_type.
			 * Note: this is to circumvent a bug that is currently being tracked in https://core.trac.wordpress.org/ticket/44349.
			 */
			return "{$wpdb->posts}.post_mime_type {$order}, {$wpdb->posts}.ID {$order}";
		}
		return $orderby;
	}

	/**
	 * Prepares links for the request.
	 * Consistent with the WP_REST_Attachments_Controller response object,
	 * `post` refers to the post that the media is attached to.
	 *
	 * @since 4.7.0
	 *
	 * @param WP_Post $post Post object.
	 * @return array Links for the given post.
	 */
	protected function prepare_links( $post ) {
		$links = parent::prepare_links( $post );

		// Can be removed when remove once required WordPress version >= 6.9.
		// See: https://core.trac.wordpress.org/ticket/64034.
		if ( ! empty( $post->post_parent ) ) {
			$post = get_post( $post->post_parent );

			if ( ! empty( $post ) ) {
				$links['https://api.w.org/attached-to'] = array(
					'href'       => rest_url( rest_get_route_for_post( $post ) ),
					'embeddable' => true,
					'post_type'  => $post->post_type,
					'id'         => $post->ID,
				);
			}
		}

		return $links;
	}

	/**
	 * Retrieves the query params for collections of attachments.
	 *
	 * @since 4.7.0
	 * @since ?.?.? Added the `mime_type` orderby value.
	 * @since ?.?.? Extends the `media_type` and `mime_type` request arguments to support array values.
	 *
	 * @return array Query parameters for the attachment collection as an array.
	 */
	public function get_collection_params() {
		$params                      = parent::get_collection_params();
		$params['orderby']['enum'][] = 'mime_type';
		$media_types                 = array_keys( $this->get_media_types() );

		$params['media_type'] = array(
			'default'     => null,
			'description' => __( 'Limit result set to attachments of a particular media type or media types.', 'zero-admin' ),

			/*
			 * The Core patch for for filtering attachments by multiple media/mime types sets this to an array in https://github.com/WordPress/wordpress-develop/pull/10054.
			 * However we need to support both string and array values for the `media_type` parameter because this class extends the current WP_REST_Attachments_Controller,
			 * and that class supports only string values. See: https://github.com/WordPress/wordpress-develop/pull/10135.
			 */
			'type'        => array(
				'string',
				'array',
			),
			'items'       => array(
				'type' => 'string',
				'enum' => $media_types,
			),
		);

		$params['mime_type'] = array(
			'default'     => null,
			'description' => __( 'Limit result set to attachments of a particular MIME type or MIME types.', 'zero-admin' ),

			/*
			 * The Core patch for for filtering attachments by multiple media/mime types sets this to an array in https://github.com/WordPress/wordpress-develop/pull/10054.
			 * However we need to support both string and array values for the `mime_type` parameter because this class extends the current WP_REST_Attachments_Controller,
			 * and that class supports only string values. See: https://github.com/WordPress/wordpress-develop/pull/10135.
			 */
			'type'        => array(
				'string',
				'array',
			),
			'items'       => array(
				'type' => 'string',
			),
		);
		return $params;
	}
}
