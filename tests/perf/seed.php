<?php
/**
 * Fills the wp-env tests site with a large blog for the performance benchmark. ERASES the site's content.
 *
 * Run with: npm run perf -- seed [posts]   (default 80000 posts, which make about 100,000 addresses)
 *
 * Posts are inserted with multi-row SQL, not wp_insert_post(): the point is to measure builds, not inserts, and
 * 80,000 wp_insert_post() calls take most of an hour.
 *
 * @package Stillpress
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Bulk benchmark fixture on a disposable site.

require __DIR__ . '/guard.php';

/**
 * Seeds the site.
 *
 * @param int $posts Number of posts.
 * @return void
 */
function stillpress_perf_seed( int $posts ): void {
	global $wpdb;
	$categories = 50;
	$tags       = 500;
	$authors    = 5;

	$started = microtime( true );

	// Empty the site.
	foreach ( array( $wpdb->posts, $wpdb->postmeta, $wpdb->comments, $wpdb->commentmeta, $wpdb->term_relationships ) as $table ) {
		$wpdb->query( "TRUNCATE TABLE $table" );
	}
	$wpdb->query( "DELETE FROM {$wpdb->term_taxonomy} WHERE term_id <> 1" );
	$wpdb->query( "DELETE FROM {$wpdb->terms} WHERE term_id <> 1" );
	$wpdb->query( "DELETE FROM {$wpdb->termmeta}" );
	$wpdb->query( "DELETE FROM {$wpdb->users} WHERE ID <> 1" );
	$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE user_id <> 1" );
	wp_cache_flush();

	// Theme: copy it in so the web container sees it wherever the plugin is mounted.
	$theme_dir = get_theme_root() . '/stillpress-perf';
	wp_mkdir_p( $theme_dir );
	foreach ( array( 'style.css', 'index.php' ) as $file ) {
		copy( __DIR__ . '/theme/' . $file, $theme_dir . '/' . $file );
	}
	switch_theme( 'stillpress-perf' );

	update_option( 'active_plugins', array( 'stillpress/stillpress.php' ) );
	update_option( 'blogname', 'Stillpress Benchmark' );
	update_option( 'show_on_front', 'posts' );
	update_option( 'posts_per_page', 10 );
	update_option( 'wp_attachment_pages_enabled', 0 );
	update_option( 'sticky_posts', array() );
	$GLOBALS['wp_rewrite']->set_permalink_structure( '/%postname%/' );
	$GLOBALS['wp_rewrite']->set_category_base( '' );
	$GLOBALS['wp_rewrite']->set_tag_base( '' );
	flush_rewrite_rules();

	// Authors and terms.
	$author_ids = array( 1 );
	for ( $i = 2; $i <= $authors; $i++ ) {
		$author_ids[] = wp_insert_user(
			array(
				'user_login' => 'author' . $i,
				'user_pass'  => wp_generate_password(),
				'role'       => 'author',
			)
		);
	}
	$category_tt = array();
	for ( $i = 1; $i <= $categories; $i++ ) {
		$term          = wp_insert_term( 'Category ' . $i, 'category' );
		$category_tt[] = (int) $term['term_taxonomy_id'];
	}
	$tag_tt = array();
	for ( $i = 1; $i <= $tags; $i++ ) {
		$term     = wp_insert_term( 'Tag ' . $i, 'post_tag' );
		$tag_tt[] = (int) $term['term_taxonomy_id'];
	}

	// Posts, a thousand rows per statement.
	$words   = explode( ' ', 'lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod tempor incididunt ut labore et dolore magna aliqua enim ad minim veniam quis nostrud exercitation ullamco laboris nisi aliquip ex ea commodo consequat' );
	$base    = strtotime( '2020-01-01 00:00:00' );
	$columns = '(post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type)';
	mt_srand( 42 );
	for ( $start = 1; $start <= $posts; $start += 1000 ) {
		$rows   = array();
		$values = array();
		$end    = min( $posts + 1, $start + 1000 );
		for ( $n = $start; $n < $end; $n++ ) {
			$date    = gmdate( 'Y-m-d H:i:s', $base + $n * 1800 );
			$content = '';
			for ( $p = 0; $p < 4; $p++ ) {
				$sentence = array();
				for ( $w = 0; $w < 60; $w++ ) {
					$sentence[] = $words[ mt_rand( 0, count( $words ) - 1 ) ];
				}
				$content .= '<!-- wp:paragraph --><p>' . implode( ' ', $sentence ) . ".</p><!-- /wp:paragraph -->\n";
			}
			$rows[] = "(%d, %s, %s, %s, %s, '', 'publish', 'closed', 'closed', %s, '', '', %s, %s, '', 0, %s, 0, 'post')";
			array_push( $values, $author_ids[ $n % count( $author_ids ) ], $date, $date, $content, 'Benchmark post ' . $n, 'benchmark-post-' . $n, $date, $date, home_url( '/?p=' . $n ) );
		}
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->posts} $columns VALUES " . implode( ', ', $rows ), $values ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are in $rows.
	}

	// One category and three tags per post.
	for ( $start = 1; $start <= $posts; $start += 2000 ) {
		$rows = array();
		$end  = min( $posts + 1, $start + 2000 );
		for ( $id = $start; $id < $end; $id++ ) {
			$rows[] = sprintf( '(%d, %d)', $id, $category_tt[ $id % $categories ] );
			foreach ( array( $id, $id * 7, $id * 13 ) as $k ) {
				$rows[] = sprintf( '(%d, %d)', $id, $tag_tt[ $k % $tags ] );
			}
		}
		$wpdb->query( "INSERT IGNORE INTO {$wpdb->term_relationships} (object_id, term_taxonomy_id) VALUES " . implode( ', ', $rows ) );
	}
	$wpdb->query( "UPDATE {$wpdb->term_taxonomy} tt SET count = (SELECT COUNT(*) FROM {$wpdb->term_relationships} tr WHERE tr.term_taxonomy_id = tt.term_taxonomy_id)" );
	wp_cache_flush();

	WP_CLI::success( sprintf( 'Seeded %d posts, %d categories, %d tags, %d authors in %.1f s.', $posts, $categories, $tags, $authors, microtime( true ) - $started ) );
}

stillpress_perf_seed( max( 1, (int) ( $args[0] ?? 80000 ) ) );
