<?php
/**
 * The Client Portal's document: its own doctype, no get_header()/get_footer().
 *
 * The same shape as the employee portal's (public/staff-template.php): the
 * theme never renders here, so a client sees the portal and nothing of the
 * marketing site around it — whatever theme the site runs. wp_head(),
 * wp_body_open() and wp_footer() still run for anything hooked to them.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Every portal page is specific to the client viewing it, down to the REST
// nonce in its config; it must never be served from a page cache.
nocache_headers();
if ( ! defined( 'DONOTCACHEPAGE' ) ) {
	define( 'DONOTCACHEPAGE', true );
}

// Before wp_head(), so the portal's stylesheet is in the <head> rather than
// printed late from inside the content.
pcm_crm_portal_assets();

$pcm_user = wp_get_current_user();
$pcm_logo = pcm_crm_portal_logo_url();

if ( ! $pcm_logo && has_custom_logo() ) {
	$pcm_logo = (string) wp_get_attachment_image_url( get_theme_mod( 'custom_logo' ), 'medium' );
}
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php // A theme with title-tag support prints the title from wp_head(); pcm_crm_portal_document_title() words it. ?>
<?php if ( ! current_theme_supports( 'title-tag' ) ) : ?>
<title><?php echo esc_html( pcm_crm_portal_document_title() ); ?></title>
<?php endif; ?>
<?php wp_head(); ?>
</head>
<body <?php body_class( 'pcm-portal-page' ); ?>>
<?php wp_body_open(); ?>
<header class="pcm-portal-topbar">
	<div class="pcm-portal-topbar-inner">
		<?php // Never a link: a client inside their portal should not be one click from leaving it. ?>
		<span class="pcm-portal-brand">
			<?php if ( $pcm_logo ) : ?>
				<img src="<?php echo esc_url( $pcm_logo ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
			<?php else : ?>
				<?php echo esc_html( get_bloginfo( 'name' ) ); ?>
			<?php endif; ?>
		</span>
		<nav class="pcm-portal-account" aria-label="<?php esc_attr_e( 'Account', 'pcm-crm' ); ?>">
			<span class="pcm-portal-account-name"><?php echo esc_html( pcm_crm_user_label( $pcm_user ) ); ?></span>
			<a href="<?php echo esc_url( wp_logout_url( pcm_crm_portal_url() ) ); ?>"><?php esc_html_e( 'Log out', 'pcm-crm' ); ?></a>
		</nav>
	</div>
</header>
<main class="pcm-portal-main">
<?php
while ( have_posts() ) {
	the_post();
	the_content();
}
?>
</main>
<?php wp_footer(); ?>
</body>
</html>
