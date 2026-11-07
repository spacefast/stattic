<?php
/**
 * Hierarchical sorting for taxonomy terms in REST API.
 *
 * Adapts Gutenberg's Gutenberg_Hierarchical_Sort (for posts) to work with
 * taxonomy terms, enabling tree-ordered responses with level metadata for
 * DataViews em-dash indentation.
 *
 * @package Zero_Admin
 */

/**
 * Sort terms by parent-child hierarchy, producing a flat list with level info.
 *
 * @param array $terms Array of term objects with term_id and parent properties.
 * @return array{term_ids: int[], levels: array<int, int>} Sorted IDs and level map.
 */
function zero_admin_sort_terms_hierarchically( array $terms ): array {
	$top_level = array();
	$children  = array();

	foreach ( $terms as $term ) {
		if ( empty( $term->parent ) ) {
			$top_level[] = (int) $term->term_id;
		} else {
			$children[ (int) $term->parent ][] = (int) $term->term_id;
		}
	}

	$ids    = array();
	$levels = array();
	zero_admin_add_hierarchical_term_ids( $ids, $levels, 0, $top_level, $children );

	// Process orphans whose parents weren't in the initial set.
	if ( ! empty( $children ) ) {
		foreach ( $children as $parent_id => $child_ids ) {
			$level    = 0;
			$ancestor = (int) $parent_id;
			while ( 0 !== $ancestor ) {
				++$level;
				$parent_term = get_term( $ancestor );
				$ancestor    = ( $parent_term && ! is_wp_error( $parent_term ) ) ? (int) $parent_term->parent : 0;
			}
			zero_admin_add_hierarchical_term_ids( $ids, $levels, $level, $child_ids, $children );
		}
	}

	return array(
		'term_ids' => $ids,
		'levels'   => $levels,
	);
}

/**
 * Recursively add term IDs in tree order.
 *
 * @param int[]             $ids      Accumulated sorted IDs (by reference).
 * @param array<int, int>   $levels   Level map keyed by term ID (by reference).
 * @param int               $level    Current depth.
 * @param int[]             $to_process IDs to process at this level.
 * @param array<int, int[]> $children  Children map (by reference, entries removed after processing).
 */
function zero_admin_add_hierarchical_term_ids( array &$ids, array &$levels, int $level, array $to_process, array &$children ): void {
	foreach ( $to_process as $id ) {
		if ( in_array( $id, $ids, true ) ) {
			continue;
		}
		$ids[]         = $id;
		$levels[ $id ] = $level;

		if ( isset( $children[ $id ] ) ) {
			zero_admin_add_hierarchical_term_ids( $ids, $levels, $level + 1, $children[ $id ], $children );
			unset( $children[ $id ] );
		}
	}
}

/**
 * Register orderby_hierarchy support for all hierarchical taxonomies.
 */
function zero_admin_register_taxonomy_hierarchy_support(): void {
	$taxonomies = get_taxonomies( array( 'hierarchical' => true ), 'names' );

	foreach ( $taxonomies as $taxonomy ) {
		// Register the query param.
		add_filter(
			"rest_{$taxonomy}_collection_params",
			'zero_admin_add_orderby_hierarchy_param'
		);

		// Intercept the term query to sort hierarchically.
		add_filter(
			"rest_{$taxonomy}_query",
			'zero_admin_intercept_hierarchy_query',
			10,
			2
		);

		// Add level to each term in the response.
		add_filter(
			"rest_prepare_{$taxonomy}",
			'zero_admin_add_term_level_to_response',
			10,
			3
		);
	}
}
add_action( 'rest_api_init', 'zero_admin_register_taxonomy_hierarchy_support' );

/**
 * Add orderby_hierarchy boolean param to taxonomy collection params.
 *
 * @param array $params Existing collection params.
 * @return array Modified params.
 */
function zero_admin_add_orderby_hierarchy_param( array $params ): array {
	$params['orderby_hierarchy'] = array(
		'description' => __( 'Sort terms by hierarchy.', 'zero-admin' ),
		'type'        => 'boolean',
		'default'     => false,
	);
	return $params;
}

/**
 * Stored hierarchy data for the current request, keyed by taxonomy.
 *
 * @var array<string, array{term_ids: int[], levels: array<int, int>}>
 */
global $zero_admin_taxonomy_hierarchy;
$zero_admin_taxonomy_hierarchy = array();

/**
 * Intercept term query when orderby_hierarchy is requested.
 *
 * Fetches all terms, sorts them hierarchically, then constrains the query
 * to the sorted IDs so pagination works correctly.
 *
 * @param array           $args    Term query args.
 * @param WP_REST_Request $request The REST request.
 * @return array Modified args.
 */
function zero_admin_intercept_hierarchy_query( array $args, WP_REST_Request $request ): array {
	if ( empty( $request['orderby_hierarchy'] ) ) {
		return $args;
	}

	global $zero_admin_taxonomy_hierarchy;

	$taxonomy = $args['taxonomy'] ?? '';
	if ( empty( $taxonomy ) ) {
		return $args;
	}

	// Fetch all terms to build the complete hierarchy tree.
	$all_terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => $args['hide_empty'] ?? false,
			'fields'     => 'all', // Need parent info.
			'number'     => 0,     // All terms.
		)
	);

	if ( is_wp_error( $all_terms ) || empty( $all_terms ) ) {
		return $args;
	}

	$result = zero_admin_sort_terms_hierarchically( $all_terms );

	// Store for later use in response filter.
	$zero_admin_taxonomy_hierarchy[ $taxonomy ] = $result;

	if ( empty( $result['term_ids'] ) ) {
		return $args;
	}

	// Constrain to sorted order via include + orderby=include.
	$args['include'] = $result['term_ids'];
	$args['orderby'] = 'include';

	return $args;
}

/**
 * Add level field to term REST response when hierarchy sorting is active.
 *
 * @param WP_REST_Response $response The response object.
 * @param WP_Term          $term     The term object.
 * @param WP_REST_Request  $request  The request object.
 * @return WP_REST_Response Modified response.
 */
function zero_admin_add_term_level_to_response( WP_REST_Response $response, WP_Term $term, WP_REST_Request $request ): WP_REST_Response {
	if ( empty( $request['orderby_hierarchy'] ) ) {
		return $response;
	}

	global $zero_admin_taxonomy_hierarchy;

	$taxonomy = $term->taxonomy;
	$levels   = $zero_admin_taxonomy_hierarchy[ $taxonomy ]['levels'] ?? array();

	$response->data['level'] = $levels[ $term->term_id ] ?? 0;

	return $response;
}
