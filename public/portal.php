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
 * [pcm_client_portal]
 *
 * A logged-out or wrong-role visitor is sent to wp-login.php with a
 * redirect_to back to this same page — WordPress's own login and lost-password
 * flow, branded in portal/portal-admin.php, rather than a form built here.
 */
function pcm_crm_portal_shortcode() {
	if ( ! is_user_logged_in() || ! in_array( 'pcm_client', (array) wp_get_current_user()->roles, true ) ) {
		// home_url( add_query_arg( null, null ) ) is the current request's full
		// URL — plain get_permalink() would drop any query string a paginated
		// or previewed view carried.
		wp_safe_redirect( add_query_arg( 'redirect_to', rawurlencode( home_url( add_query_arg( null, null ) ) ), wp_login_url() ) );
		exit;
	}

	pcm_crm_portal_assets();

	return '<div id="pcm-portal-root" class="pcm-portal"></div>';
}
add_shortcode( 'pcm_client_portal', 'pcm_crm_portal_shortcode' );
