<?php

class SortByPopularPosts {

	private static $initiated = false;

	private static $days = 7;

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
		
		// update old
		$sql_update = sprintf( 
			"
			UPDATE {$table_sbpp_wppp} AS sbpp
			SET
			 pageviews = (
			  SELECT COALESCE(sum(pps.pageviews), 0)
			   FROM {$table_wppp_pps} AS pps
			   WHERE
			     sbpp.postid = pps.postid
			    AND
			     pps.view_date BETWEEN (CURRENT_DATE - INTERVAL %d day) AND (CURRENT_DATE - INTERVAL 1 day)
			    AND
			     sbpp.days = %d
			   GROUP BY pps.postid
			  );
			", 
			self::$days + 1, 
			self::$days );
		
		// update old zero
		$sql_update_zero = sprintf( 
			"
			UPDATE {$table_sbpp_wppp} AS sbpp
			SET
			 pageviews = 0
			WHERE
			 sbpp.postid NOT IN (
			  SELECT pps.postid
			   FROM {$table_wppp_pps} AS pps
			   WHERE
			     sbpp.postid = pps.postid
			    AND
			     pps.view_date BETWEEN (CURRENT_DATE - INTERVAL %d day) AND (CURRENT_DATE - INTERVAL 1 day)
			    AND
			     sbpp.days = %d
			  );
			", 
			self::$days + 1, 
			self::$days );
		
		// insert new
		$sql_insert = sprintf( 
			"
			INSERT INTO {$table_sbpp_wppp}(postid, days, pageviews)
			 SELECT posts.ID, %d, COALESCE(sum(pps.pageviews), 0)
			  FROM {$table_posts} AS posts, {$table_wppp_pps} AS pps
			  WHERE
			    posts.post_type = 'post'
			   AND
			     (posts.post_status = 'publish'
			    OR posts.post_status = 'private')
			   AND
			    pps.view_date BETWEEN (CURRENT_DATE - INTERVAL %d day) AND (CURRENT_DATE - INTERVAL 1 day)
			   AND
			    posts.ID = pps.postid
			   AND
			    posts.ID NOT IN (SELECT postid FROM {$table_sbpp_wppp})
			  GROUP BY posts.ID;
			", 
			self::$days, 
			self::$days + 1 );
		
		// insert new zero
		$sql_insert_zero = sprintf( 
			"
			INSERT INTO {$table_sbpp_wppp} (postid, days, pageviews)
			 SELECT posts.ID, %d, 0
			 FROM {$table_posts} AS posts
			  WHERE
			    posts.post_type = 'post'
			   AND
			     (posts.post_status = 'publish'
			    OR posts.post_status = 'private')
			   AND
			    posts.ID NOT IN (SELECT postid FROM {$table_sbpp_wppp});
			", 
			self::$days );
		
		// remove deleted
		$sql_delete = sprintf( 
			"
			DELETE sbpp
			 FROM {$table_sbpp_wppp} AS sbpp
			 WHERE
			 sbpp.postid NOT IN (
			  SELECT posts.ID
			   FROM {$table_posts} AS posts
			   WHERE
			     posts.post_type = 'post'
			    AND
			      (posts.post_status = 'publish'
			     OR posts.post_status = 'private')
			  );
			" );
		
		$wpdb->query( $sql_update );
		$wpdb->query( $sql_update_zero );
		$wpdb->query( $sql_insert );
		$wpdb->query( $sql_insert_zero );
		$wpdb->query( $sql_delete );
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
		
		if ( $query->is_category() || $query->is_tag() )
			return $sort_popular;
		
		return false;
	}

	private static function need_posts_order( $query ) {
		if ( is_admin() || ! $query->is_main_query() )
			return false;
		
		$sort_query_string = '';
		$sort_popular = false;
		if ( isset( $query->query_vars[self::$sort_query_name] ) ) {
			$sort_query_string = $query->query_vars[self::$sort_query_name];
		}
		$sort_popular = self::to_sort_popular( $sort_query_string );
		
		if ( $query->is_category() || $query->is_tag() )
			return $sort_popular;
		
		return false;
	}

	public static function get_cron_time() {
		$cron_time = self::$cron_time;
		$cur_time = current_time( 'timestamp' );
		$time_zone_diff = intval( ( $cur_time - time() ) / 1800 ) * 1800;
		
		$time_next_str = date( sprintf( 'Y-m-d %s', $cron_time ), $cur_time );
		
		return strtotime( $time_next_str ) - $time_zone_diff;
	}

	public static function posts_join( $join, $query ) {
		if ( ! ( self::need_pageviews( $query ) || self::need_posts_order( $query ) ) )
			return $join;
		
		global $wpdb;
		$pv_table = $wpdb->prefix . self::PV_TABLE;
		
		$join .= " LEFT JOIN {$pv_table} ON {$wpdb->posts}.ID = {$pv_table}.postid ";
		return $join;
	}

	public static function posts_orderby( $orderby, $query ) {
		if ( ! self::need_posts_order( $query ) )
			return $orderby;
		
		global $wpdb;
		$pv_table = $wpdb->prefix . self::PV_TABLE;
		
		$orderby = "COALESCE(SUM({$pv_table}.pageviews), 0) DESC, {$wpdb->posts}.post_date DESC";
		return $orderby;
	}

	public static function posts_where( $where, $query ) {
		return $where;
	}

	public static function posts_groupby( $groupby, $query ) {
		if ( ! ( self::need_pageviews( $query ) || self::need_posts_order( $query ) ) )
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
		
		if ( empty( $content ) ) {
			return self::get_link( $sort, $url, $escape );
		}
		return sprintf( '<a href="%s">%s</a>', self::get_link( $sort, $url, true ), $content );
	}
}
