<?php
/** Zero navigation. Data endpoints remain the authority. @package Zero_Admin */
function zero_admin_navigation() {
    $plugin = \Zero\Wp\Runtime::plugin();
    $viewer = \Zero\Wp\CoreAccess::viewer();
    $readable = array();
    if ( $viewer === null ) {
        return $readable;
    }
    foreach ( $plugin->schema->collections() as $name => $collection ) {
        $rule = \Zero\Access::collectionRules( $collection )['read'];
        if ( isset( $rule['fn'] ) || \Zero\Access::evaluate( $rule, $viewer, 'read' ) !== false ) {
            $readable[] = $name;
        }
    }
    return $readable;
}
add_action( 'zero_admin_init', static function () {
    zero_admin_register_menu_item( 'collections', array( 'parent_type' => 'dropdown', 'label' => __( 'Collections', 'zero-admin' ), 'icon' => 'dashicons-database' ) );
    zero_admin_register_menu_item( 'all-collections', array( 'to' => '/', 'label' => __( 'All collections', 'zero-admin' ), 'parent' => 'collections' ) );
    foreach ( zero_admin_navigation() as $name ) {
        $collection = \Zero\Wp\Runtime::plugin()->schema->collection( $name );
        zero_admin_register_menu_item( 'collection-' . $name, array( 'to' => '/collections/' . rawurlencode( $name ), 'label' => $collection['label'] ?? $name, 'parent' => 'collections' ) );
    }
    zero_admin_register_menu_item( 'media', array( 'to' => '/media', 'label' => __( 'Media', 'zero-admin' ), 'icon' => 'dashicons-format-image' ) );
    zero_admin_register_menu_item( 'globals', array( 'to' => '/globals', 'label' => __( 'Globals', 'zero-admin' ), 'icon' => 'dashicons-admin-settings' ) );
    zero_admin_register_menu_item( 'classic', array( 'to' => admin_url(), 'label' => __( 'Classic admin', 'zero-admin' ), 'icon' => 'dashicons-wordpress' ) );
} );

// Zero collections already have access-aware entries. Keep native WordPress types alongside them.
add_filter( 'zero_admin_native_post_types', static function ( $types ) {
    unset( $types['attachment'] );
    foreach ( \Zero\Wp\Runtime::plugin()->schema->collections() as $collection ) {
        if ( isset( $collection['wp']['postType'] ) ) {
            unset( $types[$collection['wp']['postType']] );
        }
    }
    return $types;
} );
