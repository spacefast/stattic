<?php

namespace Zero\Vendor\WordPress\Merge\Validate;

use Zero\Vendor\WordPress\Merge\MergeException;

class InvalidMergeException extends MergeException {

	private $merge_result;

	public function __construct( $message, $merge_result = null ) {
		parent::__construct( $message );
		$this->merge_result = $merge_result;
	}

	public function get_merge_result() {
		return $this->merge_result;
	}
}
