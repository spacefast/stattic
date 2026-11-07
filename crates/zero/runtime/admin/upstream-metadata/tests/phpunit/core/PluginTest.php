<?php
/**
 * Tests for core plugin functionality.
 *
 * @package ZeroAdmin
 */

/**
 * Core plugin functionality test case.
 */
class PluginTest extends WP_UnitTestCase {

	public function test_plugin_constants_defined() {
		$this->assertTrue( defined( 'ZERO_ADMIN_PLUGIN_REDIRECT_URL' ) );
	}

	public function test_zero_admin_url_generation() {
		$url = zero_admin_url();
		$this->assertStringContainsString( '/wp-admin/admin.php', $url );
		$this->assertStringContainsString( 'page=zero-admin', $url );
	}

}

