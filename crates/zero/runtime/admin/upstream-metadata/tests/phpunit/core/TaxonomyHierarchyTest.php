<?php
/**
 * Tests for taxonomy hierarchy sorting.
 *
 * @package ZeroAdmin
 */

/**
 * Taxonomy hierarchy sorting test case.
 */
class TaxonomyHierarchyTest extends WP_UnitTestCase {

	/**
	 * Helper to create a mock term object.
	 *
	 * @param int $term_id Term ID.
	 * @param int $parent  Parent term ID.
	 * @return object
	 */
	private function make_term( int $term_id, int $parent = 0 ): object {
		return (object) array(
			'term_id' => $term_id,
			'parent'  => $parent,
		);
	}

	public function test_flat_terms_preserve_order() {
		$terms = array(
			$this->make_term( 1 ),
			$this->make_term( 2 ),
			$this->make_term( 3 ),
		);

		$result = zero_admin_sort_terms_hierarchically( $terms );

		$this->assertSame( array( 1, 2, 3 ), $result['term_ids'] );
		$this->assertSame( 0, $result['levels'][1] );
		$this->assertSame( 0, $result['levels'][2] );
		$this->assertSame( 0, $result['levels'][3] );
	}

	public function test_parent_child_ordering() {
		// Child (2) comes before parent (1) in input.
		$terms = array(
			$this->make_term( 2, 1 ),
			$this->make_term( 1 ),
		);

		$result = zero_admin_sort_terms_hierarchically( $terms );

		$this->assertSame( array( 1, 2 ), $result['term_ids'] );
		$this->assertSame( 0, $result['levels'][1] );
		$this->assertSame( 1, $result['levels'][2] );
	}

	public function test_three_level_depth() {
		$terms = array(
			$this->make_term( 3, 2 ),
			$this->make_term( 2, 1 ),
			$this->make_term( 1 ),
		);

		$result = zero_admin_sort_terms_hierarchically( $terms );

		$this->assertSame( array( 1, 2, 3 ), $result['term_ids'] );
		$this->assertSame( 0, $result['levels'][1] );
		$this->assertSame( 1, $result['levels'][2] );
		$this->assertSame( 2, $result['levels'][3] );
	}

	public function test_multiple_children_under_same_parent() {
		$terms = array(
			$this->make_term( 1 ),
			$this->make_term( 2, 1 ),
			$this->make_term( 3, 1 ),
		);

		$result = zero_admin_sort_terms_hierarchically( $terms );

		$this->assertSame( array( 1, 2, 3 ), $result['term_ids'] );
		$this->assertSame( 0, $result['levels'][1] );
		$this->assertSame( 1, $result['levels'][2] );
		$this->assertSame( 1, $result['levels'][3] );
	}

	public function test_siblings_with_children() {
		// Parent 1 -> Child 2, Parent 3 -> Child 4.
		$terms = array(
			$this->make_term( 1 ),
			$this->make_term( 3 ),
			$this->make_term( 2, 1 ),
			$this->make_term( 4, 3 ),
		);

		$result = zero_admin_sort_terms_hierarchically( $terms );

		$this->assertSame( array( 1, 2, 3, 4 ), $result['term_ids'] );
		$this->assertSame( 0, $result['levels'][1] );
		$this->assertSame( 1, $result['levels'][2] );
		$this->assertSame( 0, $result['levels'][3] );
		$this->assertSame( 1, $result['levels'][4] );
	}

	public function test_empty_terms() {
		$result = zero_admin_sort_terms_hierarchically( array() );

		$this->assertSame( array(), $result['term_ids'] );
		$this->assertSame( array(), $result['levels'] );
	}

	public function test_single_term() {
		$terms = array( $this->make_term( 42 ) );

		$result = zero_admin_sort_terms_hierarchically( $terms );

		$this->assertSame( array( 42 ), $result['term_ids'] );
		$this->assertSame( 0, $result['levels'][42] );
	}

	public function test_orphan_with_missing_parent() {
		// Term 5 has parent 99 which is not in the set.
		// The orphan handler walks ancestors via get_term() to compute level.
		$terms = array(
			$this->make_term( 1 ),
			$this->make_term( 5, 99 ),
		);

		$result = zero_admin_sort_terms_hierarchically( $terms );

		// Term 1 is top-level, term 5 is orphan — both should be in result.
		$this->assertContains( 1, $result['term_ids'] );
		$this->assertContains( 5, $result['term_ids'] );
		$this->assertSame( 0, $result['levels'][1] );
		// Orphan level depends on ancestor chain; parent 99 doesn't exist so loop exits at level 1.
		$this->assertSame( 1, $result['levels'][5] );
	}

	public function test_no_duplicate_ids() {
		$terms = array(
			$this->make_term( 1 ),
			$this->make_term( 2, 1 ),
			$this->make_term( 2, 1 ), // Duplicate.
		);

		$result = zero_admin_sort_terms_hierarchically( $terms );

		$this->assertSame( array( 1, 2 ), $result['term_ids'] );
	}
}
