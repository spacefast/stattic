<?php

namespace Zero\Vendor\WordPress\Merge;

use Zero\Vendor\WordPress\Merge\Diff\Differ;
use Zero\Vendor\WordPress\Merge\Merge\MergeConflict;
use Zero\Vendor\WordPress\Merge\Merge\Merger;
use Zero\Vendor\WordPress\Merge\Merge\MergeResult;
use Zero\Vendor\WordPress\Merge\Validate\InvalidMergeException;
use Zero\Vendor\WordPress\Merge\Validate\MergeValidator;

class MergeStrategy {
	private $differ;
	private $merger;
	private $validator;

	public function __construct( Differ $differ, Merger $merger, ?MergeValidator $validator = null ) {
		$this->differ    = $differ;
		$this->merger    = $merger;
		$this->validator = $validator;
	}

	/**
	 * Performs a three-way merge between a common base and two branches.
	 *
	 * @param  string $base  The common base version
	 * @param  string $branch_a  First branch version
	 * @param  string $branch_b  Second branch version
	 *
	 * @return MergeResult The merged result
	 */
	public function merge( string $base, string $branch_a, string $branch_b ): MergeResult {
		// Compute diffs between base and each branch.
		$diff_ab = $this->differ->diff( $base, $branch_a );
		$diff_ac = $this->differ->diff( $base, $branch_b );

		// Perform the merge using the configured merge strategy.
		$merge_result = $this->merger->merge( $diff_ab, $diff_ac );

		if ( ! $this->validator ) {
			return $merge_result;
		}

		try {
			$this->validator->validate( $merge_result->get_merged_content() );
		} catch ( InvalidMergeException $e ) {
			return new MergeResult(
				array(
					new MergeConflict(
						$branch_a,
						$branch_b,
						array(
							'message' => $e->getMessage(),
						)
					),
				)
			);
		}

		return $merge_result;
	}
}
