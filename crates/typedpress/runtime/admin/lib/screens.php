<?php
/**
 * Screens declared once, shown natively in both admins.
 *
 * A screen is a declaration: a DataViews list or a DataForm, with actions bound to Abilities. One
 * React component (`@typedpress-admin/screens`) renders it. TypedPress Admin mounts that component on the
 * route `/screens/<id>`; classic wp-admin mounts the same component on its own page
 * (`add_menu_page` / `add_submenu_page`, slug `typedpress-screen-<id>`), inside wp-admin's chrome and
 * with `@wordpress/components` styling. Both read the declaration from the same REST route and
 * run the same abilities, so the server decides access in both places: `capability` gates the
 * page and the declaration, and each ability checks its own permission when it runs.
 *
 * ```php
 * add_action( 'tp_admin_screens', static function () {
 *     tp_admin_register_screen( 'schema-versions', array(
 *         'title'      => 'Schema versions',
 *         'capability' => 'manage_options',
 *         'classic'    => array( 'parent' => 'tools.php' ),
 *         'view'       => array(
 *             'ability' => 'blog/schema-read',  // read-only ability that returns the items
 *             'items'   => 'versions',           // where the list is in its output
 *             'id'      => 'version',
 *             'fields'  => array( array( 'id' => 'version', 'label' => 'Version', 'type' => 'integer' ) ),
 *             'layout'  => array( 'type' => 'table', 'fields' => array( 'version' ) ),
 *         ),
 *         'actions'    => array( array( 'id' => 'rollback', 'label' => 'Roll back', 'ability' => 'blog/schema-apply', 'input' => array( 'version' => '{item.version}' ) ) ),
 *     ) );
 * } );
 * ```
 *
 * @package TypedPress_Admin
 */

/** @var array<string, array> Registered screens by id. */
global $tp_admin_screens;
$tp_admin_screens = array();

/**
 * Declare one screen. Call it on `tp_admin_screens`.
 *
 * @param string $id   Slug: lowercase letters, digits and hyphens.
 * @param array  $args {
 *     @type string $title       Page title (both admins).
 *     @type string $description Optional line under the title.
 *     @type string $capability  Who may open the screen and read its declaration. Default 'manage_options'.
 *     @type array  $classic     Where wp-admin lists it: `parent` (a top-level menu slug such as
 *                               'tools.php'; absent for a top-level page), `icon`, `position`. `false`
 *                               keeps it out of wp-admin.
 *     @type array  $menu        Where TypedPress Admin lists it: `parent` (a TypedPress Admin menu id), `icon`.
 *                               `false` keeps it out of the sidebar (the route still works).
 *     @type array  $view        A list: `ability` (read-only, returns the items), `input` (its
 *                               input), `items` (dot path to the list in the output; absent when
 *                               the output is the list), `id` (the item key, default 'id'),
 *                               `fields` (DataViews fields: id, label, type, elements), `layout`
 *                               (a DataViews view: type, fields, sort, perPage).
 *     @type array  $form        A form instead of a list: `ability` (read-only, returns the values),
 *                               `input`, `item` (dot path), `fields`, `layout` (a DataForm form),
 *                               `submit` (an action, see below).
 *     @type array  $actions     Item actions: `id`, `label`, `ability`, `input` (a map whose
 *                               string values may name `{item.<field>}`), `destructive`,
 *                               `confirm` (a sentence asked before it runs).
 *     @type array  $data        Plain values the screen also shows (a summary line, for example).
 * }
 * @return bool Whether the screen was registered.
 */
function tp_admin_register_screen( $id, $args ) {
	global $tp_admin_screens;
	if ( ! is_string( $id ) || ! preg_match( '/^[a-z0-9][a-z0-9-]*$/', $id ) ) {
		_doing_it_wrong( __FUNCTION__, 'A screen id is a slug: lowercase letters, digits and hyphens.', '1.0.0' );
		return false;
	}
	if ( empty( $args['view'] ) === empty( $args['form'] ) ) {
		_doing_it_wrong( __FUNCTION__, 'A screen has either a `view` (a list) or a `form`.', '1.0.0' );
		return false;
	}
	$tp_admin_screens[ $id ] = array_merge(
		array(
			'title'       => $id,
			'description' => '',
			'capability'  => 'manage_options',
			'classic'     => array(),
			'menu'        => array(),
			'view'        => null,
			'form'        => null,
			'actions'     => array(),
			'data'        => null,
		),
		$args,
		array( 'id' => $id )
	);
	return true;
}

/**
 * Every declared screen, collected once per request.
 *
 * @return array<string, array>
 */
function tp_admin_screens() {
	static $collected = false;
	global $tp_admin_screens;
	if ( ! $collected && did_action( 'init' ) ) {
		$collected = true;
		/**
		 * Declare screens with tp_admin_register_screen().
		 */
		do_action( 'tp_admin_screens' );
	}
	return $tp_admin_screens;
}

/** The entity a screen is in WordPress's entity fields and actions registries: kind, then name. */
const TP_ADMIN_SCREEN_ENTITY_KIND = 'tpAdminScreen';

/**
 * WordPress 7.2 holds fields and actions per entity in core registries (`wp_register_entity_fields()`,
 * `wp_register_entity_actions()`, served at `/wp/v2/entity-fields` and `/wp/v2/entity-actions`).
 * When the running WordPress has them, every screen's fields and actions are registered there, as the
 * entity `tpAdminScreen` / `<id>`, and the screen reads them back from there: core is the registry,
 * and anything that reads core's registries sees them. On 7.1 (TypedPress's floor) the declaration is the
 * registry. The declared shapes are core's (a field is a DataViews field; an action carries core's
 * `id`, `label`, `isPrimary`, `supportsBulk`, `context`), plus `ability` and `input`, which core
 * keeps because its action schema allows more properties.
 *
 * @return array{fields: bool, actions: bool}
 */
function tp_admin_screen_core_registries() {
	return array(
		'fields'  => function_exists( 'wp_register_entity_fields' ) && function_exists( 'wp_get_registered_entity_fields' ),
		'actions' => function_exists( 'wp_register_entity_actions' ) && function_exists( 'wp_get_registered_entity_actions' ),
	);
}

/** A screen's fields: the list's or the form's. */
function tp_admin_screen_fields( $screen ) {
	return array_values( (array) ( $screen['view']['fields'] ?? $screen['form']['fields'] ?? array() ) );
}

/** A field as core's registry takes it: the declaration's own (core's type list includes `array`). */
function tp_admin_screen_core_field( $field ) {
	return $field;
}

add_action(
	'wp_entity_fields_api_init',
	static function () {
		foreach ( tp_admin_screens() as $id => $screen ) {
			$fields = array_map( 'tp_admin_screen_core_field', tp_admin_screen_fields( $screen ) );
			if ( $fields ) {
				wp_register_entity_fields( 'typedpress-admin', TP_ADMIN_SCREEN_ENTITY_KIND, $id, $fields );
			}
		}
	}
);

add_action(
	'wp_entity_actions_api_init',
	static function () {
		foreach ( tp_admin_screens() as $id => $screen ) {
			$actions = array_values( (array) $screen['actions'] );
			if ( $actions ) {
				wp_register_entity_actions( 'typedpress-admin', TP_ADMIN_SCREEN_ENTITY_KIND, $id, $actions );
			}
		}
	}
);

/**
 * The declaration as the component reads it: data resolved for the current user; fields and actions
 * from core's registries when WordPress has them, else from the declaration.
 *
 * @param array $screen A registered screen.
 * @return array
 */
function tp_admin_screen_payload( $screen ) {
	$data = $screen['data'];
	if ( is_callable( $data ) ) {
		$data = call_user_func( $data );
	}
	$core    = tp_admin_screen_core_registries();
	$fields  = tp_admin_screen_fields( $screen );
	$actions = array_values( (array) $screen['actions'] );
	if ( $core['fields'] ) {
		// Core's definition, with the declared `array` type kept for how the screen shows a list value.
		$declared = array_column( $fields, null, 'id' );
		$fields   = array();
		foreach ( wp_get_registered_entity_fields( TP_ADMIN_SCREEN_ENTITY_KIND, $screen['id'] ) as $field ) {
			unset( $field['origin'] );
			$fields[] = array_merge( $field, array_intersect_key( $declared[ $field['id'] ] ?? array(), array( 'type' => true ) ) );
		}
	}
	if ( $core['actions'] ) {
		$actions = array();
		foreach ( wp_get_registered_entity_actions( TP_ADMIN_SCREEN_ENTITY_KIND, $screen['id'] ) as $action ) {
			unset( $action['origin'] );
			$actions[] = $action;
		}
	}
	$view = $screen['view'];
	$form = $screen['form'];
	if ( is_array( $view ) ) {
		$view['fields'] = $fields;
	}
	if ( is_array( $form ) ) {
		$form['fields'] = $fields;
		if ( isset( $form['submit'] ) ) {
			$form['submit'] = tp_admin_screen_action( $form['submit'] );
		}
	}
	return array(
		'id'          => $screen['id'],
		'title'       => $screen['title'],
		'description' => $screen['description'],
		'view'        => $view,
		'form'        => $form,
		// Only actions bound to an ability run here; one another plugin added to the entity in core
		// without one has a script module of its own, which this screen does not load.
		'actions'     => array_values( array_map( 'tp_admin_screen_action', array_filter( $actions, static fn ( $a ) => isset( $a['ability'] ) ) ) ),
		'data'        => $data,
		'registry'    => array(
			'fields'  => $core['fields'] ? 'core' : 'typedpress-admin',
			'actions' => $core['actions'] ? 'core' : 'typedpress-admin',
		),
	);
}

/**
 * An action with the HTTP method core's run route expects for its ability (read-only GET,
 * destructive and idempotent DELETE, otherwise POST), read from the ability's own annotations.
 *
 * @param array $action A declared action.
 * @return array
 */
function tp_admin_screen_action( $action ) {
	$ability     = function_exists( 'wp_get_ability' ) && wp_has_ability( $action['ability'] ) ? wp_get_ability( $action['ability'] ) : null;
	$annotations = $ability ? (array) $ability->get_meta_item( 'annotations', array() ) : array();
	$method      = 'POST';
	if ( ! empty( $annotations['readonly'] ) ) {
		$method = 'GET';
	} elseif ( ! empty( $annotations['destructive'] ) && ! empty( $annotations['idempotent'] ) ) {
		$method = 'DELETE';
	}
	return $action + array( 'method' => $method );
}

/**
 * `GET /wp-json/typedpress-admin/v1/screens/<id>`: the declaration, for users who may open the screen.
 * Both admins read it from here; neither embeds a copy.
 */
function tp_admin_register_screen_routes() {
	register_rest_route(
		'typedpress-admin/v1',
		'/screens/(?P<id>[a-z0-9][a-z0-9-]*)',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => static function ( $request ) {
				$screen = tp_admin_screens()[ $request['id'] ] ?? null;
				if ( null === $screen ) {
					return new WP_Error( 'rest_screen_not_found', __( 'No such screen.', 'typedpress-admin' ), array( 'status' => 404 ) );
				}
				return current_user_can( $screen['capability'] )
					? true
					: new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to open this screen.', 'typedpress-admin' ), array( 'status' => rest_authorization_required_code() ) );
			},
			'callback'            => static function ( $request ) {
				return rest_ensure_response( tp_admin_screen_payload( tp_admin_screens()[ $request['id'] ] ) );
			},
		)
	);
}
add_action( 'rest_api_init', 'tp_admin_register_screen_routes' );

/** The wp-admin page slug of a screen. */
function tp_admin_screen_page( $id ) {
	return 'typedpress-screen-' . $id;
}

/**
 * The classic wp-admin pages: one per screen, under its declared parent.
 */
function tp_admin_register_classic_screens() {
	foreach ( tp_admin_screens() as $id => $screen ) {
		if ( false === $screen['classic'] ) {
			continue;
		}
		$classic  = (array) $screen['classic'];
		$render   = static function () use ( $id ) {
			tp_admin_render_classic_screen( $id );
		};
		// wp-admin prints menu titles as HTML; a title can come from a runtime label.
		$label    = esc_html( $screen['title'] );
		$hook     = empty( $classic['parent'] )
			? add_menu_page( $label, $label, $screen['capability'], tp_admin_screen_page( $id ), $render, $classic['icon'] ?? 'dashicons-admin-generic', $classic['position'] ?? null )
			: add_submenu_page( $classic['parent'], $label, $label, $screen['capability'], tp_admin_screen_page( $id ), $render, $classic['position'] ?? null );
		if ( $hook ) {
			add_action( 'load-' . $hook, static fn () => tp_admin_enqueue_classic_screen( $id ) );
		}
	}
}
add_action( 'admin_menu', 'tp_admin_register_classic_screens' );

/**
 * The scripts a classic screen page needs: WordPress's own React packages, the import map and
 * the `@typedpress-admin/screens` module, which mounts the component into the page.
 *
 * @param string $id Screen id.
 */
function tp_admin_enqueue_classic_screen( $id ) {
	// The build registers its modules for the TypedPress Admin page; a classic page needs the same ones.
	if ( function_exists( 'wp_plugin_build_apps_register_wp_modules' ) ) {
		wp_plugin_build_apps_register_wp_modules();
	}
	wp_enqueue_style( 'wp-components' );
	// WordPress's admin design tokens (7.1: the `wp-theme` handle, `--wpds-*`), which the component
	// reads; TypedPress Admin's own page sets them through its theme provider.
	if ( wp_style_is( 'wp-theme', 'registered' ) ) {
		wp_enqueue_style( 'wp-theme' );
	}
	// Modules can import other modules whose classic WordPress globals must also load first.
	$modules = array();
	foreach ( glob( TP_ADMIN_PLUGIN_DIR . 'build/packages/*/index.asset.php' ) as $file ) {
		$module = include $file;
		$modules[ $module['name'] ] = $module;
	}
	$pending = array( '@typedpress-admin/screens' );
	$seen = array();
	$dependencies = array();
	while ( $pending ) {
		$name = array_pop( $pending );
		if ( isset( $seen[ $name ] ) || ! isset( $modules[ $name ] ) ) {
			continue;
		}
		$seen[ $name ] = true;
		$dependencies = array_merge( $dependencies, $modules[ $name ]['dependencies'] ?? array() );
		$pending = array_merge( $pending, $modules[ $name ]['moduleDependencies'] ?? array() );
	}
	$asset = $modules['@typedpress-admin/screens'] ?? array( 'version' => TP_ADMIN_VERSION );
	// Classic globals the module expects (React, wp.apiFetch with its nonce). The module mounts itself
	// on the page's `#typedpress-admin-screen` element, which names the screen in `data-screen-id` (runtime/admin/classic-screen.js).
	wp_register_script( 'typedpress-admin-screen-prerequisites', '', array_values( array_unique( $dependencies ) ), $asset['version'], true );
	wp_enqueue_script( 'typedpress-admin-screen-prerequisites' );
	wp_register_script_module( 'typedpress-admin-screen-classic', TP_ADMIN_PLUGIN_URL . 'classic-screen.js', array( array( 'id' => '@typedpress-admin/screens', 'import' => 'static' ) ), TP_ADMIN_VERSION );
	wp_enqueue_script_module( 'typedpress-admin-screen-classic' );
	// The modules import `__i18n__`, which TypedPress Admin's import map scopes; print that map in place
	// of core's (same position: before the modules).
	remove_action( 'admin_print_footer_scripts', array( wp_script_modules(), 'print_import_map' ), 9 );
	add_action( 'admin_print_footer_scripts', 'tp_admin_print_import_map', 9 );
}

/**
 * The classic page body: wp-admin's own `.wrap` and title, and the element the component fills.
 *
 * @param string $id Screen id.
 */
function tp_admin_render_classic_screen( $id ) {
	$screen = tp_admin_screens()[ $id ] ?? null;
	if ( null === $screen || ! current_user_can( $screen['capability'] ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'typedpress-admin' ), 403 );
	}
	echo '<div class="wrap typedpress-admin-classic-screen"><h1 class="wp-heading-inline">' . esc_html( $screen['title'] ) . '</h1>';
	if ( '' !== $screen['description'] ) {
		echo '<p class="description">' . esc_html( $screen['description'] ) . '</p>';
	}
	echo '<div id="typedpress-admin-screen" data-screen-id="' . esc_attr( $id ) . '"></div></div>';
}

/**
 * TypedPress Admin: one route for every screen, and a sidebar entry for each one this user may open.
 */
add_action(
	'tp_admin_init',
	static function () {
		foreach ( tp_admin_screens() as $id => $screen ) {
			if ( false === $screen['menu'] || ! current_user_can( $screen['capability'] ) ) {
				continue;
			}
			$menu = (array) $screen['menu'];
			tp_admin_register_menu_item(
				'screen-' . $id,
				array_filter(
					array(
						'to'     => '/screens/' . $id,
						'label'  => $screen['title'],
						'parent' => $menu['parent'] ?? null,
						'icon'   => $menu['icon'] ?? null,
					)
				)
			);
		}
	},
	15
);

/**
 * TypedPress's table kinds, declared as screens: each `table()` gets a list in both admins, through its
 * `<app>/<kind>-list` ability, with delete bound to `<app>/<kind>-delete`. Collections keep
 * wp-admin's own post screens (and TypedPress Admin's collection routes); this gives tables, which have
 * no wp-admin screen of their own, one there. The app's access rules decide what each user sees
 * and may do, in both admins.
 */
add_action(
	'tp_admin_screens',
	static function () {
		$plugin = class_exists( '\\TypedPress\\Wp\\Runtime' ) ? \TypedPress\Wp\Runtime::plugin() : null;
		if ( null === $plugin ) {
			return;
		}
		$app = $plugin->schema->name();
		foreach ( $plugin->schema->collections() as $name => $kind ) {
			$api = $kind['api'] ?? array();
			if ( isset( $kind['wp']['postType'] ) || ! empty( $kind['auth'] ) || false === ( $api['list'] ?? true ) || false === ( $kind['agents'] ?? true ) ) {
				continue;
			}
			$k       = \TypedPress\Wp\Abilities\Register::kebab( $name );
			$fields  = array();
			foreach ( $kind['columns'] as $column => $spec ) {
				if ( ! empty( $spec['hidden'] ) ) {
					continue;
				}
				$type     = $spec['type'] ?? 'text';
				$fields[] = array_filter(
					array(
						'id'    => (string) $column,
						// `.ui({ label })` in the app's config, else the field's name.
						'label' => $spec['label'] ?? $spec['ui']['label'] ?? (string) $column,
						'type'  => 'integer' === $type ? 'integer' : ( 'boolean' === $type ? 'boolean' : ( in_array( $type, array( 'timestamp', 'date' ), true ) ? 'datetime' : null ) ),
					)
				);
			}
			$primary = (string) $kind['primaryKey'];
			$title   = $kind['title'] ?? $primary;
			$actions = array();
			if ( false !== ( $api['delete'] ?? true ) ) {
				$actions[] = array(
					'id'          => 'delete',
					'label'       => __( 'Delete', 'typedpress-admin' ),
					'ability'     => "$app/$k-delete",
					'input'       => array( 'id' => '{item.' . $primary . '}' ),
					'destructive' => true,
					'confirm'     => __( 'Delete this row? This cannot be undone.', 'typedpress-admin' ),
				);
			}
			tp_admin_register_screen(
				// The kebab-case kind name (`stockItems` → `stock-items`), as the ability names use it.
				'table-' . $k,
				array(
					'title'      => $kind['label'] ?? $name,
					'description' => __( 'The 100 most recent rows. All of them are in TypedPress Admin, under Collections.', 'typedpress-admin' ),
					'capability' => 'read',
					'classic'    => array( 'parent' => 'typedpress-' . $app ),
					// TypedPress Admin lists every kind under Collections already.
					'menu'       => false,
					'view'       => array(
						'ability' => "$app/$k-list",
						'input'   => array( 'limit' => 100, 'orderBy' => array( $primary => 'desc' ) ),
						'items'   => 'rows',
						'id'      => $primary,
						'fields'  => $fields,
						'layout'  => array(
							'type'       => 'table',
							'titleField' => $title,
							'fields'     => array_values( array_diff( array_column( $fields, 'id' ), array( $title ) ) ),
						),
					),
					'actions'    => $actions,
				)
			);
		}
	}
);
