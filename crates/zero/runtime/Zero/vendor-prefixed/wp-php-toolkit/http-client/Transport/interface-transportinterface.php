<?php

namespace Zero\Vendor\WordPress\HttpClient\Transport;

interface TransportInterface {

	public function event_loop_tick(): bool;
}
