<?php

/**
 * Class SortByPopularPostsTest
 *
 * @package SortByPopularPosts
 */
class SortByPopularPostsTest extends WP_UnitTestCase {

	/**
	 *
	 * @var SortByPopularPosts
	 */
	protected $object;

	public function setUp() {
		// $this->object = new SortByPopularPosts;
		$this->object = unprotector::on( new SortByPopularPosts() );
	}
}

class unprotector {
	// http://qiita.com/kumazo@github/items/45d956b0e66cd0b5e0bd
	private $target;

	private function __construct( $target ) {
		$this->target = $target;
	}

	public static function on( $target ) {
		return new self( $target );
	}

	public function __call( $name, $args ) {
		$method = new ReflectionMethod( $this->target, $name );
		$method->setAccessible( true );
		return $method->invokeArgs( $this->target, $args );
	}
}
