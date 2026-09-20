<?php
/**
 * The employee portal's document: its own doctype, no get_header()/get_footer().
 *
 * Skipping the theme's own template parts is what keeps the brochure chrome
 * off this page at the source, rather than hiding it after the fact with
 * CSS — the theme genuinely never renders here. wp_head()/wp_body_open()/
 * wp_footer() still run, so anything hooked to them (analytics, the SEO
 * plugin's meta, admin-bar assets for a logged-in visitor) behaves the same
 * as on any other front-end page.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

pcm_crm_front_gate();

// A staff screen must never be the thing SiteGround's dynamic cache hands to
// the next visitor — every one of these pages is specific to who is logged
// in, down to the REST nonce baked into its own localized config.
nocache_headers();
if ( ! defined( 'DONOTCACHEPAGE' ) ) {
	define( 'DONOTCACHEPAGE', true );
}

$pcm_screen = get_query_var( 'pcm_crm_screen', '' );
$pcm_id     = (int) get_query_var( 'pcm_crm_id', 0 );
$pcm_tab    = get_query_var( 'pcm_crm_tab', '' );

// Guarded rather than assumed-once: WordPress's own template loader includes
// this file with require (not require_once), and this file is the resolved
// value of a filter rather than something this plugin controls the loading
// of — cheap insurance against a fatal if anything ever resolves it twice.
if ( ! function_exists( 'pcm_crm_front_route' ) ) :
/**
 * Draw the screen this request's path names.
 *
 * 'home', 'settings' and 'profile' are handled directly; anything else is
 * looked up through pcm_crm_slug_for_front() (includes/urls.php) — the
 * reverse of the same map that built the URL in the first place — so a
 * screen this map does not know about 404s rather than guessing.
 */
function pcm_crm_front_route( $pcm_screen, $pcm_id, $pcm_tab ) {
	if ( 'home' === $pcm_screen ) {
		pcm_crm_render_dashboard();
		return;
	}

	if ( in_array( $pcm_screen, array( 'settings', 'profile' ), true ) ) {
		// submit_button(), settings_errors() and add_settings_error() live in
		// wp-admin/includes/template.php, which WordPress only auto-loads for
		// an actual wp-admin request — every PCM Settings tab, and the My
		// Profile form below, calls at least one of them, so this host needs
		// the file pulled in by hand, the same well-worn technique any
		// front-end use of these admin form helpers requires. Guarded because
		// a later pcm_crm_screen() call this same request must not redeclare it.
		if ( ! function_exists( 'submit_button' ) ) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
		}
	}

	if ( 'settings' === $pcm_screen ) {
		// pcm_crm_render_settings() (includes/setup.php) is already host-aware:
		// it reads pcm_crm_tab via pcm_crm_current_setup_key()'s front branch,
		// and denies with pcm_crm_front_deny() rather than wp_die() when the
		// visitor lacks Settings access. An app-backed Setup page (Templates,
		// Sequences, Schedules, the bin) still has no front-end route — a
		// screen naming one of those slugs never reaches this branch, since
		// pcm_crm_slug_for_front() only maps the plain Settings tabs.
		pcm_crm_render_settings();
		return;
	}

	if ( 'profile' === $pcm_screen ) {
		// No permission gate here beyond pcm_crm_front_gate() (already run
		// for every /staff/ request) — editing your own name and password is
		// not a CRM permission, and a Sales-only staff member with no
		// Settings access at all still needs to reach this (public/staff-profile.php).
		pcm_crm_render_my_profile();
		return;
	}

	$pcm_admin_slug = pcm_crm_slug_for_front( $pcm_screen );
	$pcm_callbacks  = pcm_crm_screen_callbacks();

	if ( '' === $pcm_admin_slug || ! isset( $pcm_callbacks[ $pcm_admin_slug ] ) || ! is_callable( $pcm_callbacks[ $pcm_admin_slug ] ) ) {
		status_header( 404 );
		pcm_crm_front_deny( __( 'Nothing is here.', 'pcm-crm' ) );
		return;
	}

	call_user_func( $pcm_callbacks[ $pcm_admin_slug ] );
}
endif;

?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( sprintf( __( '%s — Employee Portal', 'pcm-crm' ), get_bloginfo( 'name' ) ) ); ?></title>
<?php wp_head(); ?>
</head>
<body <?php body_class( 'pcm-crm-front' ); ?>>
<?php wp_body_open(); ?>
<?php pcm_crm_front_nav(); ?>
<main class="pcm-crm-front-main">
<?php pcm_crm_front_route( $pcm_screen, $pcm_id, $pcm_tab ); ?>
</main>
<?php wp_footer(); ?>
</body>
</html>
