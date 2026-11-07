<?php

namespace Zero\Vendor\WordPress\HttpClient\Middleware;

use Zero\Vendor\WordPress\HttpClient\Request;

interface MiddlewareInterface {

	public function enqueue( Request $request );

	public function await_next_event( $requests_ids ): bool;
}
