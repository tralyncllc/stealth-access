<?php
/**
 * Plugin-owned template for the auto-created login page.
 *
 * The active WordPress theme is bypassed entirely on this URL so the login
 * portal looks consistent across sites and never inherits theme headers,
 * footers, sidebars, widget areas, or page-title output. Only `wp_head()` and
 * `wp_footer()` are kept so plugin styles / scripts (including this plugin's
 * own login.css and CAPTCHA scripts) still enqueue normally.
 *
 * @package StealthAccess
 */

defined( 'ABSPATH' ) || exit;

nocache_headers();

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<meta name="robots" content="noindex, nofollow" />
<title><?php echo esc_html( wp_get_document_title() ); ?></title>
<?php wp_head(); ?>
</head>
<body <?php body_class( 'tssl-portal-body' ); ?>>
<main class="tssl-portal" role="main">
	<?php
	while ( have_posts() ) {
		the_post();
		// Render only the shortcode output — don't run wpautop / theme content
		// filters that would wrap our markup with stray <p> tags.
		echo do_shortcode( '[' . TSSL_Login_Flow::SHORTCODE . ']' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	?>
</main>
<?php wp_footer(); ?>
</body>
</html>
