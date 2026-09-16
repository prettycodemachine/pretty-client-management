<?php
/**
 * The employee portal: rewrite rules, the router, and the nav around it.
 *
 * Loaded unconditionally — it has to answer example.com/staff/… whatever the
 * module switches say, the same reason includes/permissions.php declares
 * every area whether or not its module is on. The actual document is
 * public/staff-template.php, brought in through template_include rather than
 * a theme template, because the theme should not have to know the CRM
 * exists — and that is the whole answer to the theme having no chrome-free
 * template of its own: the plugin brings one, so a theme swap does not take
 * the employee portal down with it.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

const PCM_CRM_REWRITE_VERSION = 1;

/**
 * The five rules a base turns into.
 *
 * Settings' own rules are registered first, and rule order is the only
 * tie-break WordPress applies — without that ordering, the generic
 * ^base/([^/]+)/?$ rule would swallow ^base/settings/?$ first and "settings"
 * would arrive as an ordinary screen slug rather than routing to the Setup
 * frame. Query vars are pcm_crm_screen / pcm_crm_id / pcm_crm_tab,
 * deliberately not "page" — "page" is a query var WordPress itself already
 * assigns meaning to (pagination on a paginated post), and colliding with it
 * invites a canonical redirect that quietly rewrites the URL out from under
 * the request.
 */
function pcm_crm_add_front_rewrites() {
	$pcm_base = pcm_crm_front_base();

	add_rewrite_rule( '^' . $pcm_base . '/settings/([^/]+)/?$', 'index.php?pcm_crm_screen=settings&pcm_crm_tab=$matches[1]', 'top' );
	add_rewrite_rule( '^' . $pcm_base . '/settings/?$', 'index.php?pcm_crm_screen=settings', 'top' );
	add_rewrite_rule( '^' . $pcm_base . '/?$', 'index.php?pcm_crm_screen=home', 'top' );
	add_rewrite_rule( '^' . $pcm_base . '/([^/]+)/([0-9]+)/?$', 'index.php?pcm_crm_screen=$matches[1]&pcm_crm_id=$matches[2]', 'top' );
	add_rewrite_rule( '^' . $pcm_base . '/([^/]+)/?$', 'index.php?pcm_crm_screen=$matches[1]', 'top' );
}
add_action( 'init', 'pcm_crm_add_front_rewrites' );

function pcm_crm_front_query_vars( $pcm_vars ) {
	$pcm_vars[] = 'pcm_crm_screen';
	$pcm_vars[] = 'pcm_crm_id';
	$pcm_vars[] = 'pcm_crm_tab';

	return $pcm_vars;
}
add_filter( 'query_vars', 'pcm_crm_front_query_vars' );

/**
 * Reflush whenever the rule shape or the base itself changes.
 *
 * Rewrite rules live in an option, and an rsync deploy fires no activation
 * hook — the same reason PCM_CRM_Schema::install() runs on a version check
 * rather than only on activation. Keyed on the base too, so changing the
 * Employee Portal address (includes/urls.php) reflushes on its own without a
 * version bump.
 */
function pcm_crm_maybe_flush_front_rewrites() {
	$pcm_stamp = PCM_CRM_REWRITE_VERSION . ':' . pcm_crm_front_base();

	if ( get_option( 'pcm_crm_rewrite_stamp' ) === $pcm_stamp ) {
		return;
	}

	flush_rewrite_rules();
	update_option( 'pcm_crm_rewrite_stamp', $pcm_stamp );
}
add_action( 'init', 'pcm_crm_maybe_flush_front_rewrites', 20 );

/**
 * Hand any request carrying pcm_crm_screen to the plugin's own template.
 *
 * A request with no such query var — every ordinary front-end page — is left
 * completely alone.
 */
function pcm_crm_front_template( $pcm_template ) {
	if ( '' === get_query_var( 'pcm_crm_screen', '' ) ) {
		return $pcm_template;
	}

	return PCM_CRM_DIR . 'public/staff-template.php';
}
add_filter( 'template_include', 'pcm_crm_front_template' );

/**
 * Whether the current visitor may be on this host at all — staff, or an
 * administrator previewing it. Everyone else is turned away before the
 * template renders anything.
 */
function pcm_crm_front_visitor_allowed() {
	if ( ! is_user_logged_in() ) { return false; }

	$pcm_user = wp_get_current_user();

	return in_array( PCM_CRM_STAFF_ROLE, (array) $pcm_user->roles, true ) || current_user_can( 'manage_options' );
}

/**
 * Send away anyone who should not be looking at this host — logged out
 * visitors to wp-login.php the same way the client portal does
 * (public/portal.php), everyone else logged in to the site's own front page.
 */
function pcm_crm_front_gate() {
	if ( pcm_crm_front_visitor_allowed() ) {
		return;
	}

	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( add_query_arg( 'redirect_to', rawurlencode( home_url( add_query_arg( null, null ) ) ), wp_login_url() ) );
		exit;
	}

	wp_safe_redirect( home_url( '/' ) );
	exit;
}

/**
 * Enqueue the app for the front-end host.
 *
 * Hooked to wp_enqueue_scripts rather than run from inside the template the
 * way the client portal enqueues from inside its shortcode callback
 * (public/portal.php) — the portal has to do that because a shortcode
 * callback is the only code it controls that runs early enough. This
 * template is plugin-owned, so there is no such ordering problem: it can
 * enqueue on the normal hook like any other page.
 */
function pcm_crm_front_assets() {
	$pcm_screen = get_query_var( 'pcm_crm_screen', '' );

	if ( '' === $pcm_screen ) {
		return;
	}

	pcm_crm_enqueue_app( 'front', array(
		'setup_frame' => 'settings' === $pcm_screen,
		'no_app'      => 'settings' === $pcm_screen,
		'record_id'   => get_query_var( 'pcm_crm_id', 0 ),
	) );
}
add_action( 'wp_enqueue_scripts', 'pcm_crm_front_assets' );

/**
 * Take the theme's own front-end assets back off a CRM screen.
 *
 * Priority 20, after pcm_crm_front_assets() has already enqueued the app's
 * own styles, so there is no ordering race over which one "wins" — dequeuing
 * never fails just because the queue is briefly out of order.
 *
 * pcm-style carries style.css, whose bare `p { max-width: 640px }` and
 * `.wrap { max-width: 1120px }` rules would otherwise reflow every form
 * field and table cell the app draws — crm.css has no counter-rule for
 * either, on the assumption that nothing outside wp-admin's own forms.css
 * would ever be present to fight with. pcm-fonts is redundant with
 * pcm-crm-fonts, which the app enqueues itself. pcm-nav drives the brochure
 * hero pan and the homepage typewriter effect, neither of which this page
 * has anything for it to find.
 */
function pcm_crm_front_dequeue_theme() {
	if ( '' === get_query_var( 'pcm_crm_screen', '' ) ) {
		return;
	}

	wp_dequeue_style( 'pcm-style' );
	wp_dequeue_style( 'pcm-fonts' );
	wp_dequeue_script( 'pcm-nav' );
}
add_action( 'wp_enqueue_scripts', 'pcm_crm_front_dequeue_theme', 20 );

/**
 * "Not available", for a request the front-end host cannot answer — a
 * screen area this visitor lacks (pcm_crm_screen(), admin/menu.php) or a
 * route naming no screen at all (public/staff-template.php).
 *
 * $pcm_message is trusted markup, not escaped here — every caller builds it
 * from a translated string plus its own esc_url()/esc_html__() pieces (the
 * "CRM Settings isn't here yet, open it in wp-admin" link needs an actual
 * <a>), never from anything a visitor supplied.
 */
function pcm_crm_front_deny( $pcm_message = '' ) {
	status_header( 403 );
	?>
	<div class="pcm-crm-front-deny">
		<h1><?php esc_html_e( 'Not available', 'pcm-crm' ); ?></h1>
		<p><?php echo wp_kses_post( $pcm_message ? $pcm_message : __( 'You do not have access to this part of the CRM.', 'pcm-crm' ) ); ?></p>
		<p><a href="<?php echo esc_url( pcm_crm_front_base_url() ); ?>">&larr; <?php esc_html_e( 'Back to the CRM', 'pcm-crm' ); ?></a></p>
	</div>
	<?php
}

/**
 * The brand bar: logo, the app switcher, and the account menu — row one of
 * the front-end nav. Row two is pcm_crm_app_bar() itself (admin/menu.php),
 * called unchanged from the routed screen below; both read pcm_crm_apps() so
 * a screen a module adds appears in each without a second list to maintain.
 */
function pcm_crm_front_nav() {
	$pcm_user = wp_get_current_user();
	?>
	<header class="pcm-crm-front-nav">
		<div class="pcm-crm-front-nav-inner">
			<a class="pcm-crm-front-brand" href="<?php echo esc_url( pcm_crm_front_base_url() ); ?>">
				<?php echo esc_html( get_bloginfo( 'name' ) ); ?>
			</a>
			<div class="pcm-crm-front-account">
				<span class="pcm-crm-front-account-name"><?php echo esc_html( pcm_crm_user_label( $pcm_user ) ); ?></span>
				<a class="pcm-crm-front-logout" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">
					<?php esc_html_e( 'Log out', 'pcm-crm' ); ?>
				</a>
			</div>
		</div>
	</header>
	<?php
}
