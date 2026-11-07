<?php
/**
 * Creates the visual-diff fixture content on the tests site. Run with:
 *   wp eval-file tests/e2e/fixtures/content.php <theme>
 *
 * Idempotent: deletes earlier fixture content first.
 *
 * @package Glaciate
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- WP-CLI eval-file script.

$glaciate_theme = $args[0] ?? 'twentytwentyfive';

foreach ( get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $glaciate_id ) {
	wp_delete_post( $glaciate_id, true );
}
foreach ( get_posts( array( 'post_type' => array( 'attachment', 'wp_navigation', 'wp_template', 'wp_template_part', 'wp_global_styles', 'nav_menu_item' ), 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $glaciate_id ) {
	wp_delete_post( $glaciate_id, true );
}
foreach ( array( 'category', 'post_tag', 'nav_menu' ) as $glaciate_tax ) {
	foreach ( get_terms( array( 'taxonomy' => $glaciate_tax, 'hide_empty' => false, 'fields' => 'ids' ) ) as $glaciate_term ) {
		if ( 1 !== (int) $glaciate_term ) {
			wp_delete_term( $glaciate_term, $glaciate_tax );
		}
	}
}

update_option( 'blogname', 'Glaciate Visual Test' );
update_option( 'blogdescription', 'Static output must look like this' );
update_option( 'show_on_front', 'posts' );
update_option( 'posts_per_page', 4 );
update_option( 'timezone_string', 'UTC' );
// Through WP_Rewrite, not update_option(): the flush below builds rules from $wp_rewrite, which read the old
// structure when this request started. A fresh site (date-based permalinks) would keep rules that 404 every post.
$GLOBALS['wp_rewrite']->set_permalink_structure( '/%postname%/' );
flush_rewrite_rules();

/**
 * Generates a JPEG with GD and attaches it.
 *
 * @param string $name  File name.
 * @param int[]  $color RGB.
 * @return int Attachment ID.
 */
function glaciate_fixture_image( string $name, array $color ): int {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$img = imagecreatetruecolor( 1600, 900 );
	imagefill( $img, 0, 0, imagecolorallocate( $img, $color[0], $color[1], $color[2] ) );
	$white = imagecolorallocate( $img, 255, 255, 255 );
	for ( $i = 0; $i < 12; $i++ ) {
		imagefilledellipse( $img, 120 + $i * 120, 450 + (int) ( 200 * sin( $i ) ), 90, 90, $white );
	}
	ob_start();
	imagejpeg( $img, null, 85 );
	$bytes  = (string) ob_get_clean();
	$upload = wp_upload_bits( $name . '.jpg', null, $bytes );
	$id     = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => ucfirst( $name ),
			'post_status'    => 'inherit',
		),
		$upload['file']
	);
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
	return (int) $id;
}

$glaciate_images = array(
	glaciate_fixture_image( 'harbor', array( 30, 90, 140 ) ),
	glaciate_fixture_image( 'meadow', array( 60, 130, 60 ) ),
	glaciate_fixture_image( 'sunset', array( 200, 90, 40 ) ),
);

$glaciate_cats = array();
foreach ( array( 'News', 'Guides' ) as $glaciate_name ) {
	$glaciate_cats[] = (int) wp_insert_term( $glaciate_name, 'category' )['term_id'];
}

$glaciate_body = '<!-- wp:paragraph --><p>Static sites are fast, cheap to host and hard to break. This post checks that headings, lists, quotes, images and links survive the trip.</p><!-- /wp:paragraph -->'
	. '<!-- wp:heading --><h2 class="wp-block-heading">A section</h2><!-- /wp:heading -->'
	. '<!-- wp:list --><ul class="wp-block-list"><li>First point</li><li>Second point with <a href="' . home_url( '/about/' ) . '">a link</a></li></ul><!-- /wp:list -->'
	. '<!-- wp:quote --><blockquote class="wp-block-quote"><p>Simplicity is prerequisite for reliability.</p></blockquote><!-- /wp:quote -->'
	. '<!-- wp:image {"id":%1$d,"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="%2$s" alt="" class="wp-image-%1$d"/></figure><!-- /wp:image -->';

$glaciate_posts = array();
for ( $glaciate_n = 1; $glaciate_n <= 6; $glaciate_n++ ) {
	$glaciate_img = $glaciate_images[ $glaciate_n % 3 ];
	$glaciate_id  = wp_insert_post(
		array(
			'post_title'    => "Fixture story {$glaciate_n}",
			'post_name'     => "fixture-story-{$glaciate_n}",
			'post_content'  => sprintf( $glaciate_body, $glaciate_img, wp_get_attachment_image_url( $glaciate_img, 'large' ) ),
			'post_excerpt'  => "Excerpt of story {$glaciate_n}.",
			'post_status'   => 'publish',
			'post_author'   => 1,
			'post_date'     => sprintf( '2026-0%d-1%d 10:00:00', ( $glaciate_n % 9 ) + 1, $glaciate_n ),
			'post_category' => array( $glaciate_cats[ $glaciate_n % 2 ] ),
			'tags_input'    => array( 'static', 0 === $glaciate_n % 2 ? 'even' : 'odd' ),
		)
	);
	set_post_thumbnail( $glaciate_id, $glaciate_img );
	$glaciate_posts[] = $glaciate_id;
}

$glaciate_about = wp_insert_post(
	array(
		'post_title'   => 'About',
		'post_name'    => 'about',
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_content' => '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>We publish WordPress as static files.</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . home_url( '/contact/' ) . '">Get in touch</a></div><!-- /wp:button --></div><!-- /wp:buttons --></div><!-- /wp:column --></div><!-- /wp:columns -->',
	)
);
$glaciate_contact = wp_insert_post(
	array(
		'post_title'   => 'Contact',
		'post_name'    => 'contact',
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_content' => '<!-- wp:paragraph --><p>Write to us at hello@example.org.</p><!-- /wp:paragraph -->',
	)
);

// Classic menu for classic themes.
$glaciate_menu = wp_create_nav_menu( 'Main' );
foreach ( array( $glaciate_about, $glaciate_contact ) as $glaciate_page ) {
	wp_update_nav_menu_item(
		$glaciate_menu,
		0,
		array(
			'menu-item-object-id' => $glaciate_page,
			'menu-item-object'    => 'page',
			'menu-item-type'      => 'post_type',
			'menu-item-status'    => 'publish',
		)
	);
}
$glaciate_locations = array();
foreach ( array_keys( get_registered_nav_menus() ) as $glaciate_location ) {
	$glaciate_locations[ $glaciate_location ] = $glaciate_menu;
}
set_theme_mod( 'nav_menu_locations', $glaciate_locations );

// A page built with Elementor, when Elementor is active.
if ( defined( 'ELEMENTOR_VERSION' ) ) {
	$glaciate_data = array(
		array(
			'id'       => 'a1b2c3d',
			'elType'   => 'container',
			'settings' => array(),
			'elements' => array(
				array(
					'id'         => 'e1',
					'elType'     => 'widget',
					'widgetType' => 'heading',
					'settings'   => array(
						'title'       => 'Built with a page builder',
						'header_size' => 'h1',
					),
				),
				array(
					'id'         => 'e2',
					'elType'     => 'widget',
					'widgetType' => 'text-editor',
					'settings'   => array( 'editor' => '<p>Page builders write their own CSS files; the static copy must include them.</p>' ),
				),
				array(
					'id'         => 'e3',
					'elType'     => 'widget',
					'widgetType' => 'image',
					'settings'   => array(
						'image' => array(
							'id'  => $glaciate_images[0],
							'url' => wp_get_attachment_url( $glaciate_images[0] ),
						),
					),
				),
				array(
					'id'         => 'e4',
					'elType'     => 'widget',
					'widgetType' => 'button',
					'settings'   => array(
						'text' => 'Read the stories',
						'link' => array( 'url' => home_url( '/' ) ),
					),
				),
			),
		),
	);
	$glaciate_builder = wp_insert_post(
		array(
			'post_title'  => 'Builder page',
			'post_name'   => 'builder-page',
			'post_type'   => 'page',
			'post_status' => 'publish',
		)
	);
	update_post_meta( $glaciate_builder, '_elementor_edit_mode', 'builder' );
	update_post_meta( $glaciate_builder, '_elementor_template_type', 'wp-page' );
	update_post_meta( $glaciate_builder, '_elementor_version', ELEMENTOR_VERSION );
	update_post_meta( $glaciate_builder, '_elementor_data', wp_slash( wp_json_encode( $glaciate_data ) ) );
	update_post_meta( $glaciate_builder, '_wp_page_template', 'elementor_canvas' === $glaciate_theme ? 'elementor_canvas' : 'default' );
	if ( class_exists( '\Elementor\Plugin' ) ) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
	}
}

WP_CLI::success( sprintf( 'Fixture content created for %s: %d posts.', $glaciate_theme, count( $glaciate_posts ) ) );
