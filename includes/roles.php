<?php
/**
 * The staff role's wp-admin lockout.
 *
 * Generalises pcm_crm_portal_redirect_from_admin() (includes/portal/portal-admin.php)
 * rather than duplicating it — the client role and the staff role both answer
 * the same underlying question, "does this person have any business in
 * wp-admin at all," and the client's answer (AJAX/REST exempt, everything
 * else redirected home) is most of the staff answer too. What staff need on
 * top: endpoints their own front-end forms still post to, and a translated
 * destination rather than a bare redirect to the front-end home, so a link
 * sent before the front end existed keeps working.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * wp-admin endpoints a locked-out staff member still has to reach.
 *
 * These are not screens — they are where the front-end app's own forms post.
 * admin-post.php carries the CSV and NPSP exports, the sample-data actions,
 * the test email and project-type saves; options.php carries every CRM
 * Settings save; async-upload.php/media-upload.php carry wp.media's uploads.
 * Each of these is an ordinary wp-admin request and fires admin_init like any
 * other, so without this exemption the lockout would eat them — and the
 * failure mode is a feature that looks like it does not exist, with nothing
 * on screen to say why.
 */
function pcm_crm_admin_endpoint_allowed() {
	$pcm_file = basename( (string) ( isset( $_SERVER['SCRIPT_FILENAME'] ) ? $_SERVER['SCRIPT_FILENAME'] : '' ) );

	return in_array( $pcm_file, array( 'admin-post.php', 'options.php', 'async-upload.php', 'media-upload.php' ), true );
}

/**
 * Where a staff member should land instead of the wp-admin page they are on.
 *
 * A plain function, separate from the redirect itself, so the destination
 * logic is checkable without going through wp_safe_redirect()'s exit — the
 * same reason the access-settings.php save handlers keep their own refusal
 * logic in functions like pcm_crm_profile_in_use() rather than inline.
 *
 * Every Setup page has a front-end route now — the plain tabs, and (since)
 * the four app-backed ones (Templates, Sequences, Schedules, the bin) — so
 * there is nothing left to exempt here; that used to read the whole family
 * off pcm_crm_setup_slugs() and refuse to redirect any of them. The plain
 * Settings screen (whose own slug IS PCM_CRM_SETUP_SLUG) is still the one
 * special case, because it alone needs a *tab* threaded through
 * pcm_crm_setup_url() rather than just its own slug — $pcm_tab carries the
 * ?tab= a staff member's link may have named, the same way
 * pcm_crm_current_setup_key() reads it, so page=pcm-crm-settings&tab=pipeline
 * lands on /staff/settings/pipeline/, not merely /staff/settings/. Everything
 * else, app-backed Setup pages included, resolves through the same
 * pcm_crm_screen_url() lookup an ordinary CRM screen already does.
 */
function pcm_crm_staff_redirect_target( $pcm_page, $pcm_tab = '' ) {
	if ( PCM_CRM_SETUP_SLUG === $pcm_page ) {
		// An unrecognised or missing tab resolves to Home, the same fallback
		// pcm_crm_current_setup_key() already applies — pcm_crm_setup_url()
		// falls through to the *admin* shape for a $pcm_key it cannot find a
		// registered page for, which here would mean redirecting a staff
		// member back to the very wp-admin URL they are being redirected away
		// from.
		$pcm_tab_page = $pcm_tab ? pcm_crm_setup_page( $pcm_tab ) : null;
		$pcm_tab      = ( $pcm_tab_page && PCM_CRM_SETUP_SLUG === $pcm_tab_page['page'] ) ? $pcm_tab : 'home';

		return pcm_crm_setup_url( $pcm_tab, 'front' );
	}

	// A link sent before the front end existed still names a screen; send
	// them to its front-end equivalent rather than only the front-end home,
	// so a bookmarked or emailed link keeps working. A #id=N fragment the
	// browser is carrying never reaches the server to be read here, but it
	// survives the redirect on its own — a fragment rides along across a 302
	// whenever the Location header does not specify one of its own — and
	// readHash() (assets/crm.js) already accepts that shorthand.
	return $pcm_page && pcm_crm_front_slug( $pcm_page )
		? pcm_crm_screen_url( $pcm_page, array(), 'front' )
		: pcm_crm_front_base_url();
}

/**
 * Send a staff member out of wp-admin, to wherever pcm_crm_staff_redirect_target()
 * says they belong.
 */
function pcm_crm_redirect_staff_from_admin() {
	if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}

	if ( ! is_user_logged_in() ) {
		return;
	}

	$pcm_user = wp_get_current_user();

	if ( ! in_array( PCM_CRM_STAFF_ROLE, (array) $pcm_user->roles, true ) ) {
		return;
	}

	// An administrator is never locked out, whatever other roles they hold.
	if ( current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( pcm_crm_admin_endpoint_allowed() ) {
		return;
	}

	global $plugin_page;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only, decides a redirect destination
	$pcm_page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : (string) $plugin_page;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only, decides a redirect destination
	$pcm_tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
	$pcm_target = pcm_crm_staff_redirect_target( $pcm_page, $pcm_tab );

	if ( '' === $pcm_target ) {
		return;
	}

	wp_safe_redirect( $pcm_target );
	exit;
}
add_action( 'admin_init', 'pcm_crm_redirect_staff_from_admin' );

/**
 * Trim the admin bar for anyone who is not an administrator.
 *
 * The bar stays — it carries the account menu's own logout link, and it is
 * where dashicons already load from, which is why the front end enqueues them
 * separately rather than depending on this. A staff member has no use for New
 * Post or the comment count, and each one is also a live link back into a
 * part of wp-admin they are otherwise being kept out of.
 */
function pcm_crm_prune_admin_bar( $pcm_bar ) {
	if ( current_user_can( 'manage_options' ) || ! current_user_can( PCM_CRM_CAP ) ) {
		return;
	}

	foreach ( array( 'dashboard', 'new-content', 'comments', 'edit', 'updates' ) as $pcm_node ) {
		$pcm_bar->remove_node( $pcm_node );
	}

	$pcm_bar->add_node( array(
		'id'    => 'pcm-crm-front',
		'title' => __( 'Employee Portal', 'pcm-crm' ),
		'href'  => pcm_crm_front_base_url(),
	) );
}
add_action( 'admin_bar_menu', 'pcm_crm_prune_admin_bar', 999 );

/**
 * Brand wp-login.php for a staff invite's set-password link, the way
 * pcm_crm_portal_login_style() already does for the Client Portal's — the
 * paint job is shared (pcm_crm_branded_login_style(), public/email.php),
 * only the destination this checks against differs. Kept in this file rather
 * than beside the portal's own copy because the portal's is only loaded when
 * the portal module is on (includes/modules.php); staff exist whatever the
 * module switches say, so their invite link has to render branded
 * regardless.
 */
function pcm_crm_staff_is_login_visit() {
	return pcm_crm_is_branded_login_visit( pcm_crm_front_base_url() );
}

function pcm_crm_staff_login_logo_url( $pcm_url ) {
	return pcm_crm_staff_is_login_visit() ? home_url( '/' ) : $pcm_url;
}
add_filter( 'login_headerurl', 'pcm_crm_staff_login_logo_url' );

function pcm_crm_staff_login_logo_text( $pcm_text ) {
	return pcm_crm_staff_is_login_visit() ? get_bloginfo( 'name' ) : $pcm_text;
}
add_filter( 'login_headertext', 'pcm_crm_staff_login_logo_text' );

function pcm_crm_staff_login_style() {
	if ( ! pcm_crm_staff_is_login_visit() ) {
		return;
	}

	pcm_crm_branded_login_style();
}
add_action( 'login_enqueue_scripts', 'pcm_crm_staff_login_style' );
