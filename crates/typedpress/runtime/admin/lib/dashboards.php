<?php
/**
 * Composable dashboards and widgets for TypedPress Admin.
 *
 * Replaces upstream stub demonstration widgets with real SpacePress widgets and registers
 * declarative `adminWidget()` and `adminDashboard()` exports from the active app.
 *
 * @package TypedPress_Admin
 */

global $tp_admin_declarative_widgets, $tp_admin_dashboards;
$tp_admin_declarative_widgets = array();
$tp_admin_dashboards          = array();

/**
 * Register a declarative widget (`adminWidget()`).
 *
 * @param string $id   Kebab-case widget id.
 * @param array  $args Widget declaration.
 */
function tp_admin_register_declarative_widget( $id, $args ) {
	global $tp_admin_declarative_widgets;
	if ( ! is_string( $id ) || '' === $id || ! is_array( $args ) ) {
		return;
	}
	$tp_admin_declarative_widgets[ $id ] = array_merge(
		array(
			'id'         => $id,
			'type'       => 'tp-widget/' . $id,
			'title'      => $id,
			'capability' => 'manage_options',
			'display'    => 'summary',
		),
		$args
	);
}

/**
 * Register a custom dashboard (`adminDashboard()`).
 *
 * @param string $id   Kebab-case dashboard id.
 * @param array  $args Dashboard declaration.
 */
function tp_admin_register_dashboard( $id, $args ) {
	global $tp_admin_dashboards;
	if ( ! is_string( $id ) || '' === $id || ! is_array( $args ) ) {
		return;
	}
	$tp_admin_dashboards[ $id ] = array_merge(
		array(
			'id'          => $id,
			'title'       => $id,
			'description' => '',
			'capability'  => 'manage_options',
			'home'        => false,
			'menu'        => array(),
			'widgets'     => array(),
		),
		$args,
		array( 'id' => $id )
	);
}

/**
 * Collect declarative widgets and dashboards once per request.
 */
function tp_admin_collect_dashboards() {
	static $collected = false;
	if ( ! $collected && did_action( 'init' ) ) {
		$collected = true;
		do_action( 'tp_admin_dashboards' );
	}
}

/**
 * Declarative widgets the current user may view.
 *
 * @return array<int, array>
 */
function tp_admin_get_declarative_widgets() {
	global $tp_admin_declarative_widgets;
	tp_admin_collect_dashboards();
	$allowed = array();
	foreach ( $tp_admin_declarative_widgets as $widget ) {
		$cap = (string) ( $widget['capability'] ?? 'manage_options' );
		if ( current_user_can( $cap ) ) {
			$allowed[] = $widget;
		}
	}
	return $allowed;
}

/**
 * Custom dashboards the current user may view.
 *
 * @return array<int, array>
 */
function tp_admin_get_dashboards() {
	global $tp_admin_dashboards;
	tp_admin_collect_dashboards();
	$allowed = array();
	foreach ( $tp_admin_dashboards as $dashboard ) {
		$cap = (string) ( $dashboard['capability'] ?? 'manage_options' );
		if ( current_user_can( $cap ) ) {
			$allowed[] = $dashboard;
		}
	}
	return $allowed;
}

/**
 * Remove upstream example/stub demonstration widgets (`core-weather`, `core-quick-stats`,
 * `core-recent-posts`) from the active widget list so only real SpacePress & custom widgets appear.
 */
add_filter(
	'tp_admin_registered_widgets',
	static function ( $widgets ) {
		$stubs = array( 'core-weather', 'core-quick-stats', 'core-recent-posts', 'core/weather', 'core/quick-stats', 'core/recent-posts' );
		return array_values(
			array_filter(
				(array) $widgets,
				static fn ( $w ) => ! in_array( (string) ( $w['name'] ?? '' ), $stubs, true )
			)
		);
	},
	20
);

/**
 * Build the default Home (`/`) dashboard widget layout from the active app's capabilities and schema.
 * An `adminDashboard({ home: true })` declaration overrides this default when the viewer may open it.
 */
add_filter(
	'tp_admin_dashboard_widgets',
	static function ( $committed ) {
		if ( is_array( $committed ) && ! empty( $committed ) ) {
			return $committed;
		}
		foreach ( tp_admin_get_dashboards() as $dashboard ) {
			if ( ! empty( $dashboard['home'] ) && ! empty( $dashboard['widgets'] ) ) {
				return $dashboard['widgets'];
			}
		}

		$layout = array();
		$order  = 0;

		$readable = function_exists( 'tp_admin_navigation' ) ? tp_admin_navigation() : array();
		$plugin   = class_exists( '\\TypedPress\\Wp\\Runtime' ) ? \TypedPress\Wp\Runtime::plugin() : null;
		$has_post_collections = false;
		if ( $plugin ) {
			foreach ( $readable as $name ) {
				$c = $plugin->schema->collection( $name );
				if ( isset( $c['wp']['postType'] ) ) {
					$has_post_collections = true;
					break;
				}
			}
		}

		if ( ! empty( $readable ) || current_user_can( 'edit_posts' ) ) {
			$layout[] = array(
				'uid'    => 'tp-default-content-overview',
				'type'   => 'tp/content-overview',
				'width'  => 2,
				'height' => 2,
				'order'  => $order++,
			);
			$layout[] = array(
				'uid'    => 'tp-default-recent-activity',
				'type'   => 'tp/recent-activity',
				'width'  => 2,
				'height' => 2,
				'order'  => $order++,
			);
		}

		if ( ( $has_post_collections || current_user_can( 'edit_posts' ) ) && current_user_can( 'edit_posts' ) ) {
			$layout[] = array(
				'uid'    => 'tp-default-drafts-queue',
				'type'   => 'tp/drafts-queue',
				'width'  => 2,
				'height' => 2,
				'order'  => $order++,
			);
		}

		if ( $plugin && $plugin->schema->commerce() !== null && current_user_can( 'manage_options' ) ) {
			$layout[] = array(
				'uid'    => 'tp-default-store-pulse',
				'type'   => 'tp/store-pulse',
				'width'  => 2,
				'height' => 2,
				'order'  => $order++,
			);
		}

		if ( current_user_can( 'manage_options' ) ) {
			$layout[] = array(
				'uid'    => 'tp-default-app-status',
				'type'   => 'tp/app-status',
				'width'  => 2,
				'height' => 2,
				'order'  => $order++,
			);
		}

		return $layout;
	},
	10
);

/**
 * Register custom non-home dashboards in the sidebar under `Home`.
 */
add_action(
	'tp_admin_init',
	static function () {
		$pos = 20;
		foreach ( tp_admin_get_dashboards() as $dashboard ) {
			if ( ! empty( $dashboard['home'] ) || false === ( $dashboard['menu'] ?? array() ) ) {
				continue;
			}
			$menu = is_array( $dashboard['menu'] ?? null ) ? $dashboard['menu'] : array();
			tp_admin_register_menu_item(
				'dashboard-' . $dashboard['id'],
				array(
					'to'       => '/dashboards/' . rawurlencode( $dashboard['id'] ),
					'label'    => (string) $dashboard['title'],
					'icon'     => $menu['icon'] ?? 'chartBar',
					'section'  => $menu['section'] ?? 'home',
					'position' => $pos++,
				)
			);
		}
	},
	12
);
