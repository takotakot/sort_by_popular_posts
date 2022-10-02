<?php

class SortByPopularPosts {

	private static $initiated = false;

	private static $days = 30;

	private static $cron_time = '4:40:00';

	/**
	 * Query string for sort
	 * @var string
	 */
	public static $sort_query_name = 'sort';

	/**
	 * Set default sort ('popular' or 'default')
	 * @var string
	 */
	public static $sort_default = 'default';

	const PV_TABLE = 'sbpp_wppp';

	const PPS_TABLE = 'popularpostssummary';

	public static function init() {
		if ( ! self::$initiated ) {
			self::init_hooks();
		}
	}

	/**
	 * Initializes WordPress hooks
	 */
	private static function init_hooks() {
		self::$initiated = true;
		add_filter( 'query_vars', array( 'SortByPopularPosts', 'query_vars' ) );
		add_shortcode( 'sbpp-link', array( 'SortByPopularPosts', 'shortcode_link' ) );
		add_action( 'parse_request', array( 'SortByPopularPosts', 'add_noindex_action_if_sort_popular' ) );
	}

	/**
	 * Run when this plugin is activated
	 */
	public static function activation() {
		self::create_sbpp_wppp_table();
		self::update_sbpp_wppp();
	}

	/**
	 * Create table
	 */
	public static function create_sbpp_wppp_table() {
		global $wpdb;
		$table_sbpp_wppp = $wpdb->prefix . self::PV_TABLE;
		$charset_collate = $wpdb->get_charset_collate();
		
		require_once ( ABSPATH . '/wp-admin/includes/upgrade.php' );
		$sql = "
			CREATE TABLE `{$table_sbpp_wppp}` (
			 `id` bigint(20) NOT NULL AUTO_INCREMENT,
			 `postid` bigint(20) NOT NULL,
			 `days` int(11) NOT NULL,
			 `pageviews` bigint(20) NOT NULL,
			 PRIMARY KEY (`id`),
			 UNIQUE KEY `postid_days` (`postid`,`days`),
			 KEY `days_pageviews` (`days`,`pageviews`)
			) ENGINE=InnoDB {$charset_collate};";
		dbDelta( $sql );
	}

	/**
	 * Update table
	 */
	public static function update_sbpp_wppp() {
		global $wpdb;
		
		// set table name
		$prefix = $wpdb->prefix;
		
		$table_sbpp_wppp = $prefix . self::PV_TABLE;
		$table_wppp_pps = $prefix . self::PPS_TABLE;
		$table_posts = $prefix . 'posts';

		$post_types = array_merge( array( 'post' ), self::get_post_types() );
		$quoted_post_type_string = self::get_quoted_csv( $post_types );

		// remove deleted
		// TODO: Use prepare
		$sql_delete = sprintf( 
			"
			DELETE sbpp
			 FROM {$table_sbpp_wppp} AS sbpp
			 WHERE
			  sbpp.postid NOT IN (
			   SELECT posts.ID
			    FROM {$table_posts} AS posts
			    WHERE
			      posts.post_type IN({$quoted_post_type_string})
			     AND
			      posts.post_status IN('publish', 'private')
			  );
			" );

		// insert new using zero
		$sql_insert_zero = sprintf( 
			"
			INSERT INTO {$table_sbpp_wppp} (postid, days, pageviews)
			 SELECT posts.ID, %d, 0
			  FROM {$table_posts} AS posts
			  WHERE
			    posts.post_type IN({$quoted_post_type_string})
			   AND
			    posts.post_status IN('publish', 'private')
			   AND
			    posts.ID NOT IN (SELECT postid FROM {$table_sbpp_wppp} WHERE days = %d);
			", 
			self::$days,
			self::$days );

		// update
		$sql_update = sprintf( 
			"
			UPDATE {$table_sbpp_wppp} AS sbpp
			 LEFT JOIN (
			  SELECT sum(pps.pageviews) AS pageviews, postid
			   FROM {$table_wppp_pps} AS pps
			   WHERE
			    pps.view_date BETWEEN (CURRENT_DATE - INTERVAL %d day) AND (CURRENT_DATE - INTERVAL 1 day)
			   GROUP BY pps.postid
			 ) v
			 ON sbpp.postid = v.postid
			SET
			 sbpp.pageviews = COALESCE(v.pageviews, 0)
			 WHERE
			  sbpp.days = %d
			;
			", 
			self::$days + 1, 
			self::$days );

		$wpdb->query( $sql_delete );
		$wpdb->query( $sql_insert_zero );
		$wpdb->query( $sql_update );
	}

	/**
	 * Get a list of registered post types. Builtin post types are excluded except 'post'.
	 *
	 * @return array[string] post types
	 */
	private static function get_post_types() {
		$args = array(
			'_builtin' => false,
		);
		return get_post_types( $args, 'names', 'and' );
	}

	/**
	 * Quote a given string.
	 *
	 * @param string $str Input string to be quoted.
	 * @return string Quoted string.
	 */
	public static function quote_str( $str ) {
		return sprintf( "'%s'", $str );
	}

	private static function get_quoted_csv( $post_types ) {
		return implode( ', ', array_map( 'self::quote_str', $post_types ) );
	}

	public static function add_noindex_action_if_sort_popular( $query ) {
		if ( isset( $query->query_vars[self::$sort_query_name] ) && $query->query_vars[self::$sort_query_name] === 'popular' ) {
			add_action( 'wp_head', 'wp_no_robots', 30 );
		}
	}

	private static function need_pageviews( $query ) {
		if ( is_admin() || ! $query->is_main_query() )
			return false;
		
		$sort_query_string = '';
		$sort_popular = false;
		if ( isset( $query->query_vars[self::$sort_query_name] ) ) {
			$sort_query_string = $query->query_vars[self::$sort_query_name];
		}
		$sort_popular = self::to_sort_popular( $sort_query_string );
		
		if ( $query->is_archive() || $query->is_search() )
			return $sort_popular;
		
		return false;
	}

	/**
	 * Return 0 for 'do not change', 1 for 'sort popular', 2 for 'sort date'.
	 * 
	 * @param WP_Query $query
	 * @return int
	 */
	public static function type_posts_order( $query ) {
		if ( is_admin() || ! $query->is_main_query() )
			return 0;
		
		$sort_query_string = '';
		$sort_popular = 0;
		if ( isset( $query->query_vars[self::$sort_query_name] ) ) {
			$sort_query_string = $query->query_vars[self::$sort_query_name];
		}
		$sort_popular = self::to_sort_popular( $sort_query_string );
		
		if( $query->is_search() ) {
			if( $sort_popular ){
				return 1;
			}else{
				// Force sorting by date ignoring post_title
				return 2;
			}
		}elseif ( $query->is_archive() ){
			if( $sort_popular ){
				return 1;
			}else{
				return 0;
			}
		}
		
		return 0;
	}

	public static function get_cron_time() {
		$cron_time = self::$cron_time;
		$cur_time = current_time( 'timestamp' );
		$time_zone_diff = intval( ( $cur_time - time() ) / 300 ) * 300;

		$time_next_str = date( sprintf( 'Y-m-d %s', $cron_time ), $cur_time );
		
		return strtotime( $time_next_str ) - $time_zone_diff;
	}

	public static function posts_join( $join, $query ) {
		if ( ! ( self::need_pageviews( $query ) || self::type_posts_order( $query ) ) )
			return $join;
		
		global $wpdb;
		$pv_table = $wpdb->prefix . self::PV_TABLE;
		
		$join .= " LEFT JOIN {$pv_table} ON {$wpdb->posts}.ID = {$pv_table}.postid ";
		return $join;
	}

	public static function posts_orderby( $orderby, $query ) {
		$type_posts_order = self::type_posts_order( $query );
		if ( ! $type_posts_order )
			return $orderby;
		
		global $wpdb;
		$pv_table = $wpdb->prefix . self::PV_TABLE;
		
		if( $type_posts_order == 1 ) {
			$orderby = "COALESCE(SUM({$pv_table}.pageviews), 0) DESC, {$wpdb->posts}.post_date DESC";
		}elseif( $type_posts_order == 2 ){
			// Force sorting by date ignoring post_title
			$orderby = "{$wpdb->posts}.post_date DESC";
		}
		return $orderby;
	}

	public static function posts_where( $where, $query ) {
		return $where;
	}

	public static function posts_groupby( $groupby, $query ) {
		if ( ! ( self::need_pageviews( $query ) || self::type_posts_order( $query ) ) )
			return $groupby;
		
		global $wpdb;
		$mygroupby = "{$wpdb->posts}.ID";
		
		if ( preg_match( "/$mygroupby/", $groupby ) ) {
			return $groupby;
		}
		if ( ! strlen( trim( $groupby ) ) ) {
			return $mygroupby;
		}
		
		$groupby = $groupby . ', ' . $mygroupby;
		return $groupby;
	}

	public static function posts_fields( $fields, $query ) {
		if ( ! self::need_pageviews( $query ) )
			return $fields;
		
		global $wpdb;
		$pv_table = $wpdb->prefix . self::PV_TABLE;
		
		$fields .= ", sum({$pv_table}.pageviews) AS pageviews";
		return $fields;
	}

	public static function query_vars( $query_vars ) {
		$query_vars[] = self::$sort_query_name;
		return $query_vars;
	}

	private static function to_sort_popular( $sort_query_string ) {
		if ( empty( $sort_query_string ) ) {
			$sort_query_string = self::$sort_default;
		}
		if ( $sort_query_string == 'popular' ) {
			return true;
		}
		return false;
	}

	public static function get_link( $sort = null, $url = '', $escape = true ) {
		if ( empty( $url ) )
			$url = false;
		$url = add_query_arg( self::$sort_query_name, $sort, $url );
		if ( $escape )
			$url = esc_url( $url );
		return $url;
	}

	public static function get_popular_link( $url = null, $escape = true ) {
		return self::get_link( 'popular', $url, $escape );
	}

	public static function get_default_link( $url = null, $escape = true ) {
		return self::get_link( 'default', $url, $escape );
	}

	public static function shortcode_link( $atts = null, $content = null ) {
		$url = isset( $atts['url'] ) ? $atts['url'] : null;
		$escape = ( isset( $atts['escape'] ) && $atts['escape'] == '0' ) ? false : true;
		$sort = ( isset( $atts['sort'] ) ) ? $atts['sort'] : null;
		$class = ( is_null($sort) ) ? 'default' : $sort;
		
		if ( empty( $content ) ) {
			return self::get_link( $sort, $url, $escape );
		}
		return sprintf( '<a href="%s" class="sbpp sbpp_%s">%s</a>', self::get_link( $sort, $url, true ), $class, $content );
	}
}
