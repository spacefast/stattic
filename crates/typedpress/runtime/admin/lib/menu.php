<?php
/** TypedPress navigation. Data endpoints remain the authority. @package TypedPress_Admin */
function tp_admin_navigation() {
    $plugin = \TypedPress\Wp\Runtime::plugin();
    $viewer = \TypedPress\Wp\CoreAccess::viewer();
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
add_action( 'tp_admin_init', static function () {
    tp_admin_register_menu_item( 'collections', array( 'parent_type' => 'dropdown', 'label' => __( 'Collections', 'typedpress-admin' ), 'icon' => 'dashicons-database' ) );
    tp_admin_register_menu_item( 'all-collections', array( 'to' => '/collections', 'label' => __( 'All collections', 'typedpress-admin' ), 'parent' => 'collections' ) );
    foreach ( tp_admin_navigation() as $name ) {
        $collection = \TypedPress\Wp\Runtime::plugin()->schema->collection( $name );
        tp_admin_register_menu_item( 'collection-' . $name, array( 'to' => '/collections/' . rawurlencode( $name ), 'label' => $collection['label'] ?? $name, 'parent' => 'collections' ) );
    }
    tp_admin_register_menu_item( 'media', array( 'to' => '/media', 'label' => __( 'Media', 'typedpress-admin' ), 'icon' => 'dashicons-format-image' ) );
    tp_admin_register_menu_item( 'globals', array( 'to' => '/globals', 'label' => __( 'Globals', 'typedpress-admin' ), 'icon' => 'dashicons-admin-settings' ) );
    // Classic wp-admin on its own is for developers: everyone else reaches its pages framed, below.
    if ( tp_admin_developer_mode() ) {
        tp_admin_register_menu_item( 'classic', array( 'to' => admin_url(), 'label' => __( 'Classic admin', 'typedpress-admin' ), 'icon' => 'dashicons-wordpress' ) );
    }
    if ( tp_admin_comments_relevant() && current_user_can( 'moderate_comments' ) ) {
        tp_admin_register_classic_children( 'comments', __( 'Comments', 'typedpress-admin' ), 'dashicons-admin-comments', 'edit-comments.php' );
    }
    if ( current_user_can( 'edit_posts' ) ) {
        tp_admin_register_classic_children( 'tools', __( 'Tools', 'typedpress-admin' ), 'dashicons-admin-tools', 'tools.php' );
    }
} );

/**
 * Whether comments are part of this app: a collection takes them (`comments: true`, the only kinds
 * the `comments` hardening switch leaves open), or the site has comments to moderate anyway.
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
 * A sidebar dropdown for a classic menu (Comments, Tools): one entry per page wp-admin lists under
 * it for this user, each opening framed at `/classic/<slug>`. Plugin pages under it are included;
 * pages the user may not open (an editor the site refuses, say) wp-admin has already left out.
 *
 * @param string $id     Menu id.
 * @param string $label  Menu label.
 * @param string $icon   Dashicon.
 * @param string $parent wp-admin parent file.
 * @param array  $skip   Parent-menu slugs TypedPress Admin shows natively.
 */
function tp_admin_register_classic_children( $id, $label, $icon, $parent, $skip = array() ) {
    global $submenu;
    $children = array();
    foreach ( (array) ( $submenu[ $parent ] ?? array() ) as $item ) {
        // A declared screen (`typedpress-screen-<id>`) has its own sidebar entry (lib/screens.php).
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
        tp_admin_register_menu_item( $id, array( 'label' => $label, 'icon' => $icon, 'parent_type' => 'dropdown' ) );
    }
    foreach ( $children as $item ) {
        tp_admin_register_menu_item(
            $id . '-' . sanitize_key( $item[2] ),
            array(
                'to'     => tp_admin_get_menu_to_from_slug( $item[2], $parent ),
                'label'  => wp_strip_all_tags( preg_replace( '/<span.*/s', '', $item[0] ) ),
                'parent' => $id,
            )
        );
    }
}

// TypedPress collections already have access-aware entries. Keep native WordPress types alongside them.
add_filter( 'tp_admin_native_post_types', static function ( $types ) {
    unset( $types['attachment'] );
    foreach ( \TypedPress\Wp\Runtime::plugin()->schema->collections() as $collection ) {
        if ( isset( $collection['wp']['postType'] ) ) {
            unset( $types[$collection['wp']['postType']] );
        }
    }
    return $types;
} );

// Inside wp-admin, the classic menu lists TypedPress's post types and its globals page; TypedPress Admin
// has its own entries for both (Collections, Globals), so the copied plugin menu leaves them out.
add_filter( 'tp_admin_classic_menu_items_exclude', static function ( $slugs ) {
    $plugin = \TypedPress\Wp\Runtime::plugin();
    foreach ( $plugin->schema->collections() as $collection ) {
        if ( isset( $collection['wp']['postType'] ) ) {
            $slugs[] = 'edit.php?post_type=' . $collection['wp']['postType'];
        }
    }
    $slugs[] = 'typedpress-' . $plugin->schema->name();
    return $slugs;
} );
