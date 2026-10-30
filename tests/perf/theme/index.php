<?php
/**
 * Benchmark theme: one template that renders like a plain blog (lists, single posts, term and author archives).
 *
 * @package Stillpress
 */

?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( get_stylesheet_uri() ); ?>">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<header><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php bloginfo( 'name' ); ?></a></header>
<main>
<?php
while ( have_posts() ) {
	the_post();
	echo '<article><h2><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></h2>';
	if ( is_singular() ) {
		the_content();
	} else {
		the_excerpt();
	}
	echo '<p>';
	the_category( ', ' );
	the_tags( ' | ' );
	echo ' | ';
	the_author_posts_link();
	echo '</p></article>';
}
if ( is_singular() ) {
	the_post_navigation();
} else {
	the_posts_pagination();
}
?>
</main>
<?php wp_footer(); ?>
</body>
</html>
