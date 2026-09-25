<?php
/**
 * The client portal: markup shell, and the shortcode that gates it.
 *
 * Follows public/form.php's shape — assets enqueued from inside the shortcode
 * callback itself, since that runs during content rendering, before wp_footer()
 * prints queued scripts. Everything the portal actually shows comes from
 * assets/portal.js against the /portal REST routes; this file only decides
 * whether someone gets to see the mount point at all.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_portal_assets() {
	wp_enqueue_style( 'pcm-crm-portal', pcm_crm_asset( 'portal.css' ), array(), null );
	wp_enqueue_script( 'pcm-crm-portal', pcm_crm_asset( 'portal.js' ), array(), null, true );

	wp_localize_script( 'pcm-crm-portal', 'PCM_CRM_PORTAL', array(
		'root'         => esc_url_raw( rest_url( PCM_CRM_REST::NS ) ),
		'nonce'        => wp_create_nonce( 'wp_rest' ),
		'statuses'     => pcm_crm_pm_ticket_statuses(),
		'raidStatuses' => pcm_crm_pm_raid_statuses(),
		'raidLevels'   => pcm_crm_pm_raid_levels(),
	) );
}

/**
 * Is the current user a portal client?
 */
function pcm_crm_portal_is_client() {
	return is_user_logged_in() && in_array( 'pcm_client', (array) wp_get_current_user()->roles, true );
}

/**
 * Send a logged-out or wrong-role visitor to wp-login.php, with a redirect_to
 * back to this same page — WordPress's own login and lost-password flow,
 * branded in portal/portal-admin.php, rather than a form built here.
 *
 * This runs on template_redirect, not inside the shortcode. A shortcode is
 * also rendered by the block editor's save (the REST response carries the
 * rendered content), by SEO plugins building descriptions, and by excerpts —
 * a redirect and exit from there replaced the editor's JSON with a 302, which
 * is what "Publishing failed. The response is not a valid JSON response" was.
 */
function pcm_crm_portal_gate() {
	if ( ! is_singular() || pcm_crm_portal_is_client() ) {
		return;
	}

	$pcm_post = get_queried_object();

	if ( ! $pcm_post instanceof WP_Post || ! has_shortcode( $pcm_post->post_content, 'pcm_client_portal' ) ) {
		return;
	}

	// home_url( add_query_arg( null, null ) ) is the current request's full
	// URL — plain get_permalink() would drop any query string a paginated
	// or previewed view carried.
	wp_safe_redirect( add_query_arg( 'redirect_to', rawurlencode( home_url( add_query_arg( null, null ) ) ), wp_login_url() ) );
	exit;
}
add_action( 'template_redirect', 'pcm_crm_portal_gate' );

/**
 * [pcm_client_portal]
 *
 * Renders nothing for anyone but a client. The redirect lives in
 * pcm_crm_portal_gate(); by the time a non-client reaches this, it is the
 * editor, a feed or an excerpt asking, and none of those may be redirected.
 */
function pcm_crm_portal_shortcode() {
	if ( ! pcm_crm_portal_is_client() ) {
		return '';
	}

	pcm_crm_portal_assets();

	return '<div id="pcm-portal-root" class="pcm-portal"></div>';
}
add_shortcode( 'pcm_client_portal', 'pcm_crm_portal_shortcode' );

/**
 * Is this request the portal page?
 */
function pcm_crm_is_portal_page() {
	if ( ! is_singular() ) {
		return false;
	}

	$pcm_post = get_queried_object();

	return $pcm_post instanceof WP_Post && has_shortcode( $pcm_post->post_content, 'pcm_client_portal' );
}

/**
 * The portal draws in the plugin's own document, not the theme's.
 *
 * It is a client's app, not a page of the marketing site: the site's header,
 * navigation and footer are all ways out of it. The employee portal does the
 * same (public/staff-template.php). Only a client ever reaches this —
 * pcm_crm_portal_gate() has already sent anyone else to log in.
 */
function pcm_crm_portal_template( $pcm_template ) {
	if ( pcm_crm_is_portal_page() && pcm_crm_portal_is_client() ) {
		return PCM_CRM_DIR . 'public/portal-template.php';
	}

	return $pcm_template;
}
add_filter( 'template_include', 'pcm_crm_portal_template', 99 );

function pcm_crm_portal_document_title( $pcm_title = '' ) {
	return sprintf( __( '%s — Client Portal', 'pcm-crm' ), get_bloginfo( 'name' ) );
}

function pcm_crm_portal_title_filter( $pcm_title ) {
	return pcm_crm_is_portal_page() && pcm_crm_portal_is_client() ? pcm_crm_portal_document_title() : $pcm_title;
}
add_filter( 'pre_get_document_title', 'pcm_crm_portal_title_filter', 99 );

/**
 * Drop the theme's own styles and scripts from the portal page.
 *
 * The template never calls get_header(), but wp_head() still prints whatever
 * the theme enqueued, and a theme's stylesheet restyles headings, links and
 * layout wholesale. Anything served from the theme's own directory goes,
 * along with the block theme's global styles; plugins' assets stay.
 */
function pcm_crm_portal_dequeue_theme_assets() {
	if ( ! pcm_crm_is_portal_page() || ! pcm_crm_portal_is_client() ) {
		return;
	}

	pcm_crm_dequeue_theme_assets();
}
add_action( 'wp_enqueue_scripts', 'pcm_crm_portal_dequeue_theme_assets', 999 );
