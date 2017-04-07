<?php
/**
 * @package SortByPopularPosts
 */
/*
 * Plugin Name: SortByPopularPosts
 * Plugin URI: https://github.com/takotakot/sort_by_popular_posts
 * Description: Display posts sort by popular posts.
 * Version: 0.0.6
 * Author: takotakot
 * Author URI: http://example.com/
 * License: MIT/X
 * Text Domain: sortbypopularposts
 */

// Make sure we don't expose any info if called directly
if ( ! function_exists( 'add_action' ) ) {
	echo 'Hi there!  I\'m just a plugin, not much I can do when called directly.';
	exit();
}

define( 'SORTBYPOPULARPOSTS_VERSION', '0.0.6' );
define( 'SORTBYPOPULARPOSTS__PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SORTBYPOPULARPOSTS__PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once ( SORTBYPOPULARPOSTS__PLUGIN_DIR . 'class.sort_by_popular_posts.php' );

// $sbpp_instance = new SortByPopularPosts();

add_filter('posts_join', array( 'SortByPopularPosts', 'posts_join' ), 10, 2);
add_filter('posts_orderby', array( 'SortByPopularPosts', 'posts_orderby' ), 10, 2);
add_filter('posts_where', array( 'SortByPopularPosts', 'posts_where' ), 10, 2);
add_filter('posts_groupby', array( 'SortByPopularPosts', 'posts_groupby' ), 10, 2);
add_filter('posts_fields', array( 'SortByPopularPosts', 'posts_fields' ), 10, 2);

add_action( 'init', array( 'SortByPopularPosts', 'init' ) );
add_action( 'daily_sort_by_popular_posts_update', 'daily_sort_by_popular_posts_update_action' );

// activation
if ( function_exists( 'register_activation_hook' ) ) {
	register_activation_hook( __FILE__, 'register_sort_by_popular_posts_activation' );
	register_activation_hook( __FILE__, 'register_sort_by_popular_posts_cron' );
}

function register_sort_by_popular_posts_activation() {
	\SortByPopularPosts::activation();
}

function register_sort_by_popular_posts_cron() {
	if ( ! wp_next_scheduled( 'daily_sort_by_popular_posts_update' ) ) {
		// TODO: justify time
		wp_schedule_event( \SortByPopularPosts::get_cron_time(), 'daily', 'daily_sort_by_popular_posts_update' );
	}
}

function remove_sort_by_popular_posts_cron() {
	if ( wp_next_scheduled( 'daily_sort_by_popular_posts_update' ) ) {
		wp_clear_scheduled_hook( 'daily_sort_by_popular_posts_update' );
	}
}

function daily_sort_by_popular_posts_update_action() {
	\SortByPopularPosts::update_sbpp_wppp();
}

// deactivation
if ( function_exists( 'register_deactivation_hook' ) ) {
	register_deactivation_hook( __FILE__, 'remove_sort_by_popular_posts_cron' );
}
