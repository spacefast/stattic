<?php
/**
 * Taxonomy overrides for Zero Admin.
 *
 * @package Zero_Admin
 */

/**
 * Add delete warning labels to taxonomy objects.
 *
 * @param object $labels    Taxonomy labels object.
 * @return object Modified labels object.
 */
function zero_admin_add_category_labels( $labels ) {
	$labels->delete_warning = sprintf(
		/* translators: %s: Default category. */
		__( 'Deleting a category does not delete the posts in that category. Instead, posts that were only assigned to the deleted category are set to the default category %s. The default category cannot be deleted.', 'zero-admin' ),
		/** This filter is documented in wp-includes/category-template.php */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		'<strong>' . apply_filters( 'the_category', get_cat_name( get_option( 'default_category' ) ), '', '' ) . '</strong>'
	);

	return $labels;
}
add_filter( 'taxonomy_labels_category', 'zero_admin_add_category_labels', 10, 1 );

/**
 * Automatically ensure unique slugs when creating terms via the REST API.
 *
 * The Gutenberg data store sends the slug provided in the form straight to the REST API.
 * Core will short-circuit with a `term_exists` error before it appends a suffix, so we pre-empt
 * that behaviour by normalising the slug and letting `wp_unique_term_slug()` generate a suffix.
 *
 * @param stdClass        $prepared_term The prepared term data.
 * @param WP_REST_Request $request       The REST request.
 * @param string          $fallback_taxonomy Taxonomy fallback when the prepared term is missing taxonomy data.
 *
 * @return stdClass Filtered term data.
 */
function zero_admin_rest_taxonomy_unique_slug( stdClass $prepared_term, WP_REST_Request $request, string $fallback_taxonomy = 'category' ) {
	// Only adjust during term creation requests.
	if ( 'POST' !== $request->get_method() ) {
		return $prepared_term;
	}

	$taxonomy = property_exists( $prepared_term, 'taxonomy' )
		? (string) $prepared_term->taxonomy
		: $fallback_taxonomy;

	$raw_slug = '';
	if ( property_exists( $prepared_term, 'slug' ) && ! empty( $prepared_term->slug ) ) {
		$raw_slug = (string) $prepared_term->slug;
	} elseif ( property_exists( $prepared_term, 'name' ) ) {
		$raw_slug = (string) $prepared_term->name;
	}

	$sanitized_slug = sanitize_title( $raw_slug );
	if ( '' === $sanitized_slug ) {
		return $prepared_term;
	}

	// Always normalise the slug that will be sent to core.
	$prepared_term->slug = $sanitized_slug;

	// If the slug is already unused we can bail early.
	if ( ! get_term_by( 'slug', $prepared_term->slug, $taxonomy ) ) {
		return $prepared_term;
	}

	$parent              = property_exists( $prepared_term, 'parent' ) ? (int) $prepared_term->parent : 0;
	$prepared_term->slug = wp_unique_term_slug(
		$prepared_term->slug,
		(object) array(
			'taxonomy' => $taxonomy,
			'parent'   => $parent,
		)
	);

	return $prepared_term;
}

/**
 * Register the REST term slug filter for all public taxonomies.
 */
function zero_admin_register_rest_taxonomy_slug_filter(): void {
	$taxonomies = get_taxonomies(
		array(
			'public' => true,
		),
		'names'
	);

	foreach ( $taxonomies as $taxonomy ) {
		add_filter(
			"rest_pre_insert_{$taxonomy}",
			static function ( stdClass $prepared_term, WP_REST_Request $request ) use ( $taxonomy ) {
				return zero_admin_rest_taxonomy_unique_slug( $prepared_term, $request, $taxonomy );
			},
			10,
			2
		);
	}
}
add_action( 'rest_api_init', 'zero_admin_register_rest_taxonomy_slug_filter', 9 );
