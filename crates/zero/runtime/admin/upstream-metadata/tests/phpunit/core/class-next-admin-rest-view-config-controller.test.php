<?php
/**
 * Test suite for the View Config Controller.
 *
 * @package ZeroAdmin
 */

/**
 * Test class for Zero_Admin_REST_View_Config_Controller.
 */
class Zero_Admin_REST_View_Config_Controller_Test extends WP_Test_REST_TestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * Set up test data.
	 */
	public function set_up() {
		parent::set_up();

		// Initialize REST server and trigger rest_api_init to register routes.
		global $wp_rest_server;
		$wp_rest_server = rest_get_server();
		do_action( 'rest_api_init' );

		// Create admin user.
		$this->admin_user_id = self::factory()->user->create(
			array(
				'role' => 'administrator',
			)
		);
	}

	/**
	 * Clean up after test.
	 */
	public function tear_down() {
		// Remove any filters added during tests.
		remove_all_filters( 'zero_admin_entity_view_config_postType_post' );
		remove_all_filters( 'zero_admin_entity_view_config_postType_post_test-variant' );
		remove_all_filters( 'rest_prepare_view_config' );

		parent::tear_down();
	}

	/**
	 * Test that routes are registered correctly.
	 */
	public function test_routes_are_registered() {
		global $wp_rest_server;
		$routes = $wp_rest_server->get_routes();

		$this->assertArrayHasKey( '/wp/v2/view-config', $routes );
	}

	/**
	 * Test GET endpoint returns config with required params.
	 */
	public function test_get_items_returns_config() {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'GET', '/wp/v2/view-config' );
		$request->set_param( 'kind', 'postType' );
		$request->set_param( 'name', 'post' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();

		// Check response structure.
		$this->assertArrayHasKey( 'id', $data );
		$this->assertArrayHasKey( 'kind', $data );
		$this->assertArrayHasKey( 'name', $data );
		$this->assertArrayHasKey( 'default_view', $data );
		$this->assertArrayHasKey( 'default_layouts', $data );
		$this->assertArrayHasKey( 'quick_edit_form', $data );
		$this->assertArrayHasKey( 'add_form', $data );
		$this->assertArrayHasKey( 'edit_form', $data );
		$this->assertArrayHasKey( 'tabs', $data );

		$this->assertEquals( 'postType-post', $data['id'] );
		$this->assertEquals( 'postType', $data['kind'] );
		$this->assertEquals( 'post', $data['name'] );
	}

	/**
	 * Test GET endpoint returns config with variant param.
	 */
	public function test_get_items_returns_config_with_variant() {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'GET', '/wp/v2/view-config' );
		$request->set_param( 'kind', 'postType' );
		$request->set_param( 'name', 'post' );
		$request->set_param( 'variant', 'test-variant' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertEquals( 'postType-post-test-variant', $data['id'] );
		$this->assertEquals( 'postType', $data['kind'] );
		$this->assertEquals( 'post', $data['name'] );
		$this->assertEquals( 'test-variant', $data['variant'] );
	}

	/**
	 * Test GET endpoint returns default config structure.
	 */
	public function test_get_items_returns_default_config_structure() {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'GET', '/wp/v2/view-config' );
		$request->set_param( 'kind', 'postType' );
		$request->set_param( 'name', 'post' );
		$response = rest_get_server()->dispatch( $request );

		$data = $response->get_data();

		// Verify all expected fields are present.
		$this->assertArrayHasKey( 'id', $data );
		$this->assertArrayHasKey( 'kind', $data );
		$this->assertArrayHasKey( 'name', $data );
		$this->assertArrayHasKey( 'default_view', $data );
		$this->assertArrayHasKey( 'default_layouts', $data );
		$this->assertArrayHasKey( 'quick_edit_form', $data );
		$this->assertArrayHasKey( 'add_form', $data );
		$this->assertArrayHasKey( 'edit_form', $data );
		$this->assertArrayHasKey( 'tabs', $data );

		// Verify types.
		$this->assertIsString( $data['id'] );
		$this->assertIsString( $data['kind'] );
		$this->assertIsString( $data['name'] );

		// Verify default values.
		$this->assertNull( $data['default_view'] );
		$this->assertNull( $data['default_layouts'] );
		$this->assertNull( $data['quick_edit_form'] );
		$this->assertNull( $data['add_form'] );
		$this->assertNull( $data['edit_form'] );
		$this->assertIsArray( $data['tabs'] );
		$this->assertEmpty( $data['tabs'] );
	}

	/**
	 * Test GET endpoint constructs id correctly.
	 */
	public function test_get_items_constructs_id_correctly() {
		wp_set_current_user( $this->admin_user_id );

		// Test without variant.
		$request = new WP_REST_Request( 'GET', '/wp/v2/view-config' );
		$request->set_param( 'kind', 'postType' );
		$request->set_param( 'name', 'post' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 'postType-post', $data['id'] );

		// Test with variant.
		$request = new WP_REST_Request( 'GET', '/wp/v2/view-config' );
		$request->set_param( 'kind', 'taxonomy' );
		$request->set_param( 'name', 'category' );
		$request->set_param( 'variant', 'custom-view' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 'taxonomy-category-custom-view', $data['id'] );
	}

	/**
	 * Test GET endpoint requires kind param.
	 */
	public function test_get_items_requires_kind_param() {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'GET', '/wp/v2/view-config' );
		$request->set_param( 'name', 'post' );
		// kind param is missing.
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'rest_missing_callback_param', $data['code'] );
	}

	/**
	 * Test GET endpoint requires name param.
	 */
	public function test_get_items_requires_name_param() {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'GET', '/wp/v2/view-config' );
		$request->set_param( 'kind', 'postType' );
		// name param is missing.
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'rest_missing_callback_param', $data['code'] );
	}

	/**
	 * Test GET endpoint includes variant when provided.
	 */
	public function test_get_items_includes_variant_when_provided() {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'GET', '/wp/v2/view-config' );
		$request->set_param( 'kind', 'postType' );
		$request->set_param( 'name', 'post' );
		$request->set_param( 'variant', 'test-variant' );
		$response = rest_get_server()->dispatch( $request );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'variant', $data );
		$this->assertEquals( 'test-variant', $data['variant'] );
	}

	/**
	 * Test GET endpoint excludes variant when not provided.
	 */
	public function test_get_items_excludes_variant_when_not_provided() {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'GET', '/wp/v2/view-config' );
		$request->set_param( 'kind', 'postType' );
		$request->set_param( 'name', 'post' );
		// variant param is not set.
		$response = rest_get_server()->dispatch( $request );

		$data = $response->get_data();
		$this->assertArrayNotHasKey( 'variant', $data );
	}

	/**
	 * Test GET endpoint applies entity filter.
	 */
	public function test_get_items_applies_entity_filter() {
		wp_set_current_user( $this->admin_user_id );

		// Add filter to modify config.
		add_filter(
			'zero_admin_entity_view_config_postType_post',
			function( $config, $kind, $name ) {
				$config['default_view'] = array( 'test' => 'value' );
				return $config;
			},
			10,
			3
		);

		$request = new WP_REST_Request( 'GET', '/wp/v2/view-config' );
		$request->set_param( 'kind', 'postType' );
		$request->set_param( 'name', 'post' );
		$response = rest_get_server()->dispatch( $request );

		$data = $response->get_data();
		$this->assertIsArray( $data['default_view'] );
		$this->assertEquals( array( 'test' => 'value' ), $data['default_view'] );
	}

	/**
	 * Test GET endpoint applies variant filter.
	 */
	public function test_get_items_applies_variant_filter() {
		wp_set_current_user( $this->admin_user_id );

		// Add entity filter.
		add_filter(
			'zero_admin_entity_view_config_postType_post',
			function( $config, $kind, $name ) {
				$config['default_view'] = array( 'entity' => 'value' );
				return $config;
			},
			10,
			3
		);

		// Add variant filter that should override entity filter.
		add_filter(
			'zero_admin_entity_view_config_postType_post_test-variant',
			function( $config, $kind, $name, $variant ) {
				$config['default_view'] = array( 'variant' => 'value' );
				return $config;
			},
			10,
			4
		);

		$request = new WP_REST_Request( 'GET', '/wp/v2/view-config' );
		$request->set_param( 'kind', 'postType' );
		$request->set_param( 'name', 'post' );
		$request->set_param( 'variant', 'test-variant' );
		$response = rest_get_server()->dispatch( $request );

		$data = $response->get_data();
		// Variant filter should override entity filter.
		$this->assertEquals( array( 'variant' => 'value' ), $data['default_view'] );
	}

	/**
	 * Test GET endpoint applies rest_prepare filter.
	 */
	public function test_get_items_applies_rest_prepare_filter() {
		wp_set_current_user( $this->admin_user_id );

		// Add filter to modify response.
		add_filter(
			'rest_prepare_view_config',
			function( $data, $config, $kind, $name, $variant, $request ) {
				$data['custom_field'] = 'custom_value';
				return $data;
			},
			10,
			6
		);

		$request = new WP_REST_Request( 'GET', '/wp/v2/view-config' );
		$request->set_param( 'kind', 'postType' );
		$request->set_param( 'name', 'post' );
		$response = rest_get_server()->dispatch( $request );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'custom_field', $data );
		$this->assertEquals( 'custom_value', $data['custom_field'] );
	}

	/**
	 * Test GET endpoint sanitizes variant.
	 */
	public function test_get_items_prevents_invalid_variant() {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'GET', '/wp/v2/view-config' );
		$request->set_param( 'kind', 'postType' );
		$request->set_param( 'name', 'post' );
		$request->set_param( 'variant', 'test@variant#123' );

		$response = rest_get_server()->dispatch( $request );

		$data = $response->get_data();
		$this->assertArrayNotHasKey( 'variant', $data );
	}

	/**
	 * Test GET endpoint returns allowed_fields when set via filter.
	 */
	public function test_get_items_returns_allowed_fields() {
		wp_set_current_user( $this->admin_user_id );

		$allowed = array( 'name', 'cost', 'duration' );

		add_filter(
			'zero_admin_entity_view_config_postType_post',
			function( $config ) use ( $allowed ) {
				$config['allowed_fields'] = $allowed;
				return $config;
			}
		);

		$request = new WP_REST_Request( 'GET', '/wp/v2/view-config' );
		$request->set_param( 'kind', 'postType' );
		$request->set_param( 'name', 'post' );
		$response = rest_get_server()->dispatch( $request );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'allowed_fields', $data );
		$this->assertEquals( $allowed, $data['allowed_fields'] );
	}

	/**
	 * Test GET endpoint omits allowed_fields when not set.
	 */
	public function test_get_items_omits_allowed_fields_when_not_set() {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'GET', '/wp/v2/view-config' );
		$request->set_param( 'kind', 'postType' );
		$request->set_param( 'name', 'post' );
		$response = rest_get_server()->dispatch( $request );

		$data = $response->get_data();
		$this->assertArrayNotHasKey( 'allowed_fields', $data );
	}

	/**
	 * Test GET endpoint handles empty variant.
	 */
	public function test_get_items_handles_empty_variant() {
		wp_set_current_user( $this->admin_user_id );

		// Test with empty string variant.
		$request = new WP_REST_Request( 'GET', '/wp/v2/view-config' );
		$request->set_param( 'kind', 'postType' );
		$request->set_param( 'name', 'post' );
		$request->set_param( 'variant', '' );
		$response = rest_get_server()->dispatch( $request );

		$data = $response->get_data();
		// Empty variant should be normalized to null, so it shouldn't appear in response.
		$this->assertArrayNotHasKey( 'variant', $data );
	}
}

