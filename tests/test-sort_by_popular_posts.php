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

	function test_get_link() {
		$url = '';
		$sort_popular = 'sort=popular';
		$sort_default = 'sort=default';
		$this->assertEquals( '?' . $sort_popular, SortByPopularPosts::get_link( 'popular' ) );
		$this->assertEquals( $sort_popular, SortByPopularPosts::get_link( 'popular', $sort_popular, false ) );
		$this->assertEquals( $sort_popular, SortByPopularPosts::get_link( 'popular', $sort_default, false ) );
		$this->assertEquals( 
			'cat=3&' . $sort_popular, 
			SortByPopularPosts::get_link( 'popular', 'cat=3&' . $sort_default, false ) );
		$this->assertEquals( 
			'?cat=3&' . $sort_popular, 
			SortByPopularPosts::get_link( 'popular', '?cat=3&' . $sort_default, false ) );
		
		$url = 'http://example.com/';
		$this->assertEquals( $url . '?' . $sort_popular, SortByPopularPosts::get_link( 'popular', $url ) );
		$this->assertEquals( 
			$url . '?' . $sort_popular, 
			SortByPopularPosts::get_link( 'popular', $url . '?' . $sort_popular ) );
		$this->assertEquals( 
			$url . '?' . $sort_popular, 
			SortByPopularPosts::get_link( 'popular', $url . '?' . $sort_default ) );
		
		$url = 'http://example.com/?cat=3';
		$this->assertEquals( $url . '&#038;' . $sort_popular, SortByPopularPosts::get_link( 'popular', $url ) );
		$this->assertEquals( 
			$url . '&#038;' . $sort_popular, 
			SortByPopularPosts::get_link( 'popular', $url . '&' . $sort_popular ) );
		$this->assertEquals( 
			$url . '&#038;' . $sort_popular, 
			SortByPopularPosts::get_link( 'popular', $url . '&' . $sort_default ) );
		
		$this->assertEquals( $url, SortByPopularPosts::get_link( null, $url . '&' . $sort_popular ) );
		
		$this->assertEquals( $url . '&' . $sort_popular, SortByPopularPosts::get_link( 'popular', $url, false ) );
		$this->assertEquals( 
			$url . '&' . $sort_popular, 
			SortByPopularPosts::get_link( 'popular', $url . '&' . $sort_popular, false ) );
		$this->assertEquals( 
			$url . '&' . $sort_popular, 
			SortByPopularPosts::get_link( 'popular', $url . '&' . $sort_default, false ) );
		
		$url = '';
		$this->assertEquals( '?' . $sort_default, SortByPopularPosts::get_link( 'default' ) );
		$this->assertEquals( 
			'?' . $sort_default, 
			SortByPopularPosts::get_link( 'default', $url . '?' . $sort_popular ) );
		$this->assertEquals( 
			'?' . $sort_default, 
			SortByPopularPosts::get_link( 'default', $url . '?' . $sort_default ) );
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
