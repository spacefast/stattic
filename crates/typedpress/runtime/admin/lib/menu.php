<?php
/**
 * TypedPress navigation. Data endpoints remain the authority.
 *
 * Promotes collections flat to top-level Content, SQL tables to Data, commerce kinds to Commerce,
 * globals to Site (hidden when none exist), and hides unused surfaces.
 *
 * @package TypedPress_Admin
 */

/**
 * Readable collections & tables for the current viewer.
 *
 * @return string[]
 */
function tp_admin_navigation() {
	$plugin   = \TypedPress\Wp\Runtime::plugin();
	$viewer   = \TypedPress\Wp\CoreAccess::viewer();
	$readable = array();
	if ( $viewer === null ) {
		return $readable;
	}
	foreach ( $plugin->schema->collections() as $name => $collection ) {
		$rule = \TypedPress\Access::collectionRules( $collection )['read'];
		if ( isset( $rule['fn'] ) || \TypedPress\Access::evaluate( $rule, $viewer, 'read' ) !== false ) {
			$readable[] = $name;
		}
	}
	return $readable;
}

/**
 * Readable globals for the current viewer.
 *
 * @return array<string, array>
 */
function tp_admin_readable_globals() {
	$plugin   = \TypedPress\Wp\Runtime::plugin();
	$viewer   = \TypedPress\Wp\CoreAccess::viewer();
	$readable = array();
	if ( $viewer === null ) {
		return $readable;
	}
	foreach ( $plugin->schema->globals() as $name => $global ) {
		if ( ! empty( $global['ui']['hidden'] ) ) {
			continue;
		}
		$rule = \TypedPress\Access::globalRules( $global )['read'];
		if ( isset( $rule['fn'] ) || \TypedPress\Access::evaluate( $rule, $viewer, 'read' ) !== false ) {
			$readable[ $name ] = $global;
		}
	}
	return $readable;
}

/**
 * Whether Media should appear in the Content section.
 *
 * Shown if the app has any post-backed collection, any media/mediaList field, any WP pages,
 * or any uploaded attachment.
 *
 * @return bool
 */
function tp_admin_media_relevant() {
	$plugin = \TypedPress\Wp\Runtime::plugin();
	foreach ( $plugin->schema->collections() as $collection ) {
		if ( isset( $collection['wp']['postType'] ) ) {
			return true;
		}
		foreach ( (array) ( $collection['columns'] ?? array() ) as $col ) {
			if ( in_array( $col['type'] ?? '', array( 'media', 'mediaList' ), true ) ) {
				return true;
			}
		}
	}
	foreach ( $plugin->schema->globals() as $global ) {
		foreach ( (array) ( $global['fields'] ?? array() ) as $col ) {
			if ( in_array( $col['type'] ?? '', array( 'media', 'mediaList' ), true ) ) {
				return true;
			}
		}
	}
	if ( function_exists( 'tp_admin_pages_relevant' ) && tp_admin_pages_relevant() ) {
		return true;
	}
	$counts = wp_count_posts( 'attachment' );
	return is_object( $counts ) && ! empty( $counts->inherit );
}

add_action(
	'tp_admin_init',
	static function () {
		$plugin       = \TypedPress\Wp\Runtime::plugin();
		$content_pos  = 100;
		$commerce_pos = 300;
		$data_pos     = 400;

		foreach ( tp_admin_navigation() as $name ) {
			$collection = $plugin->schema->collection( $name );
			$ui         = is_array( $collection['ui'] ?? null ) ? $collection['ui'] : array();
			if ( ! empty( $ui['hidden'] ) ) {
				continue;
			}
			// Skip userProjection kinds (managed under System → Users).
			if ( ! empty( $collection['auth'] ) ) {
				continue;
			}

			$is_commerce = ! empty( $collection['commerce'] );
			$is_post     = isset( $collection['wp']['postType'] );

			if ( $is_commerce ) {
				$default_section = 'commerce';
				$default_icon    = 'order' === $collection['commerce'] ? 'box' : 'store';
				$pos             = $commerce_pos++;
			} elseif ( $is_post ) {
				$default_section = 'content';
				$default_icon    = 'post';
				$pos             = $content_pos++;
			} else {
				$default_section = 'data';
				$default_icon    = 'table';
				$pos             = $data_pos++;
			}

			$section = ! empty( $ui['group'] ) ? (string) $ui['group'] : $default_section;

			tp_admin_register_menu_item(
				'collection-' . $name,
				array(
					'to'       => '/collections/' . rawurlencode( $name ),
					'label'    => $collection['label'] ?? $name,
					'icon'     => ! empty( $ui['icon'] ) ? (string) $ui['icon'] : $default_icon,
					'section'  => $section,
					'position' => $pos,
				)
			);
		}

		if ( tp_admin_media_relevant() ) {
			tp_admin_register_menu_item(
				'media',
				array(
					'to'       => '/media',
					'label'    => __( 'Media', 'typedpress-admin' ),
					'icon'     => 'image',
					'section'  => 'content',
					'position' => 250,
				)
			);
		}

		if ( tp_admin_comments_relevant() && current_user_can( 'moderate_comments' ) ) {
			tp_admin_register_menu_item(
				'comments',
				array(
					'to'       => tp_admin_get_menu_to_from_slug( 'edit-comments.php' ),
					'label'    => __( 'Comments', 'typedpress-admin' ),
					'icon'     => 'comment',
					'section'  => 'content',
					'position' => 260,
				)
			);
		}

		// Globals: hide when none exist; promote directly into Site when 1–3, or group as dropdown for 4+.
		$globals = tp_admin_readable_globals();
		if ( count( $globals ) > 0 && count( $globals ) <= 3 ) {
			$g_pos = 510;
			foreach ( $globals as $name => $global ) {
				$ui = is_array( $global['ui'] ?? null ) ? $global['ui'] : array();
				tp_admin_register_menu_item(
					'global-' . $name,
					array(
						'to'       => '/globals/' . rawurlencode( $name ),
						'label'    => $global['label'] ?? $name,
						'icon'     => ! empty( $ui['icon'] ) ? (string) $ui['icon'] : 'globe',
						'section'  => ! empty( $ui['group'] ) ? (string) $ui['group'] : 'site',
						'position' => $g_pos++,
					)
				);
			}
		} elseif ( count( $globals ) > 3 ) {
			tp_admin_register_menu_item(
				'globals',
				array(
					'label'       => __( 'Globals', 'typedpress-admin' ),
					'icon'        => 'globe',
					'parent_type' => 'dropdown',
					'section'     => 'site',
					'position'    => 510,
				)
			);
			foreach ( $globals as $name => $global ) {
				tp_admin_register_menu_item(
					'global-' . $name,
					array(
						'to'     => '/globals/' . rawurlencode( $name ),
						'label'  => $global['label'] ?? $name,
						'parent' => 'globals',
					)
				);
			}
		}

		// Classic wp-admin on its own is only for self-hosted Developer Mode (never on SpaceFast/managed).
		if ( tp_admin_developer_mode() ) {
			tp_admin_register_menu_item(
				'classic',
				array(
					'to'       => admin_url(),
					'label'    => __( 'Classic admin', 'typedpress-admin' ),
					'icon'     => 'wordpress',
					'section'  => 'system',
					'position' => 990,
				)
			);
		}
	}
);

/**
 * Whether comments are part of this app: a collection takes them (`comments: true`),
 * or the site has comments to moderate anyway.
 *
 * @return bool
 */
function tp_admin_comments_relevant() {
	foreach ( \TypedPress\Wp\Runtime::plugin()->schema->collections() as $collection ) {
		if ( true === ( $collection['comments'] ?? false ) ) {
			return true;
		}
	}
	return (int) wp_count_comments()->total_comments > 0;
}

/**
 * Register submenu entries from a classic wp-admin parent menu under a TypedPress Admin menu id,
 * each opening framed at `/classic/<slug>`.
 *
 * @param string      $id     Target TypedPress Admin menu id.
 * @param string|null $label  Optional dropdown label (null to append into an existing parent).
 * @param string|null $icon   Optional icon.
 * @param string      $parent wp-admin parent file.
 * @param array       $skip   Parent-menu slugs TypedPress Admin shows natively or hides.
 */
function tp_admin_register_classic_children( $id, $label, $icon, $parent, $skip = array() ) {
	global $submenu;
	$children = array();
	foreach ( (array) ( $submenu[ $parent ] ?? array() ) as $item ) {
		if ( empty( $item[0] ) || empty( $item[2] ) || in_array( $item[2], $skip, true ) || str_starts_with( $item[2], 'typedpress-screen-' )
			|| ( ! empty( $item[1] ) && ! current_user_can( $item[1] ) ) ) {
			continue;
		}
		$children[] = $item;
	}
	if ( array() === $children ) {
		return;
	}
	if ( null !== $label ) {
		tp_admin_register_menu_item(
			$id,
			array(
				'label'       => $label,
				'icon'        => $icon,
				'parent_type' => 'dropdown',
			)
		);
	}
	foreach ( $children as $item ) {
		tp_admin_register_menu_item(
			$id . '-' . sanitize_key( $item[2] ),
			array(
				'to'     => tp_admin_get_menu_to_from_slug( $item[2], $parent ),
				'label'  => trim( wp_strip_all_tags( preg_replace( '/<span.*/s', '', $item[0] ) ) ),
				'parent' => $id,
			)
		);
	}
}

// TypedPress collections already have access-aware entries. Keep native WordPress types alongside them.
add_filter(
	'tp_admin_native_post_types',
	static function ( $types ) {
		unset( $types['attachment'] );
		foreach ( \TypedPress\Wp\Runtime::plugin()->schema->collections() as $collection ) {
			if ( isset( $collection['wp']['postType'] ) ) {
				unset( $types[ $collection['wp']['postType'] ] );
			}
		}
		return $types;
	}
);

// Inside wp-admin, the classic menu lists TypedPress's post types and its globals page; TypedPress Admin
// has its own flat entries for both, so the copied plugin menu leaves them out.
add_filter(
	'tp_admin_classic_menu_items_exclude',
	static function ( $slugs ) {
		$plugin = \TypedPress\Wp\Runtime::plugin();
		foreach ( $plugin->schema->collections() as $collection ) {
			if ( isset( $collection['wp']['postType'] ) ) {
				$slugs[] = 'edit.php?post_type=' . $collection['wp']['postType'];
			}
		}
		$slugs[] = 'typedpress-' . $plugin->schema->name();
		return $slugs;
	}
);
