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
 * Reflush whenever the rule shape or the base itself changes — or when the
 * live rules simply do not contain ours any more, whatever the stamp says.
 *
 * Rewrite rules live in an option, and an rsync deploy fires no activation
 * hook — the same reason PCM_CRM_Schema::install() runs on a version check
 * rather than only on activation. Keyed on the base too, so changing the
 * Employee Portal address (includes/urls.php) reflushes on its own without a
 * version bump.
 *
 * The stamp alone is not proof the rule survived, though: a new client site
 * stood up by cloning an existing install's database carries
 * pcm_crm_rewrite_stamp forward as an ordinary option, but 'rewrite_rules' is
 * exactly the kind of thing a migration or backup tool treats as disposable
 * cache and drops, or never regenerates for the new host — leaving this
 * function convinced there is nothing to do while /staff/ 404s outright, with
 * Employee Portal Settings still showing the base as configured. Checking
 * that our own pattern is actually present, not only that the stamp matches,
 * is what makes a cloned or restored site self-heal on its very next page
 * load the same way a fresh install already does — costing nothing extra on
 * the front end, where WordPress's own request routing already fetches this
 * same option for every request regardless.
 */
function pcm_crm_maybe_flush_front_rewrites() {
	$pcm_base  = pcm_crm_front_base();
	$pcm_stamp = PCM_CRM_REWRITE_VERSION . ':' . $pcm_base;
	$pcm_rules = get_option( 'rewrite_rules' );
	$pcm_ours  = is_array( $pcm_rules ) && array_key_exists( '^' . $pcm_base . '/?$', $pcm_rules );

	if ( $pcm_ours && get_option( 'pcm_crm_rewrite_stamp' ) === $pcm_stamp ) {
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
/**
 * Whether a front-end screen word draws inside the Setup frame — the plain
 * 'settings' screen itself, or one of the four app-backed Setup pages
 * (Templates, Sequences, Schedules, the bin), which share that frame but are
 * reached through the ordinary screen_callbacks dispatch rather than
 * 'settings' own tab switch. A plain function so pcm_crm_front_assets()'s
 * 'setup_frame' decision is checkable directly rather than only by its side
 * effects (which styles got enqueued).
 *
 * pcm_crm_is_setup_screen() (includes/setup.php) answers the equivalent
 * question on the admin host, but it matches a *hook suffix*
 * ("...settings_page_pcm-crm-templates"), not a bare admin slug, so it is not
 * reusable here as-is — pcm_crm_setup_slugs() is the flat list it is itself
 * built from, and a plain membership check against this screen's resolved
 * admin slug is what that list is for.
 */
function pcm_crm_front_screen_wants_setup_frame( $pcm_screen ) {
	return 'settings' === $pcm_screen
		|| in_array( pcm_crm_slug_for_front( $pcm_screen ), pcm_crm_setup_slugs(), true );
}

function pcm_crm_front_assets() {
	$pcm_screen = get_query_var( 'pcm_crm_screen', '' );

	if ( '' === $pcm_screen ) {
		return;
	}

	pcm_crm_enqueue_app( 'front', array(
		'setup_frame' => pcm_crm_front_screen_wants_setup_frame( $pcm_screen ),
		// My Profile (public/staff-profile.php) is a plain page like Settings,
		// but it never draws inside the Setup frame — see that file for why —
		// so it takes 'no_app' without 'setup_frame'.
		'no_app'      => in_array( $pcm_screen, array( 'settings', 'profile' ), true ),
		'record_id'   => get_query_var( 'pcm_crm_id', 0 ),
	) );
}
add_action( 'wp_enqueue_scripts', 'pcm_crm_front_assets' );

/**
 * Define window.ajaxurl on the front-end host, before anything in wp_footer
 * can need it.
 *
 * wp-admin defines this global for free (core's 'common' script, printed on
 * every admin page); the front end never does. wp.media's own Backbone
 * models — querying, deleting, and editing an attachment's details, not the
 * Plupload upload itself, which carries its own localized URL — read
 * window.ajaxurl directly to reach admin-ajax.php, and every CRM screen that
 * can open the media picker (PCM Settings' Contact Form logo/attachments,
 * the Projects module's Documents tab) is host-agnostic code that has always
 * assumed this global exists. Printed in wp_head rather than attached as an
 * inline script on some other handle, so it does not depend on getting the
 * dependency order of wp.media's own script chain right — it is simply
 * defined before wp_footer, where every enqueued script actually runs.
 */
function pcm_crm_front_ajaxurl() {
	if ( '' === get_query_var( 'pcm_crm_screen', '' ) ) {
		return;
	}

	printf( "<script>window.ajaxurl = %s;</script>\n", wp_json_encode( admin_url( 'admin-ajax.php' ) ) );
}
add_action( 'wp_head', 'pcm_crm_front_ajaxurl' );

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
 * No wp-admin bar on a CRM screen. pcm_crm_prune_admin_bar() (includes/roles.php)
 * still trims it everywhere else a staff member goes on the site — its own
 * account menu is the logout affordance there — but pcm_crm_front_nav() (below)
 * already carries My Profile and Log out on every screen this reaches, which
 * makes the bar pure duplication here, not a second way out.
 */
function pcm_crm_front_hide_admin_bar( $pcm_show ) {
	if ( '' !== get_query_var( 'pcm_crm_screen', '' ) ) {
		return false;
	}

	return $pcm_show;
}
add_filter( 'show_admin_bar', 'pcm_crm_front_hide_admin_bar' );

/**
 * "Not available", for a request the front-end host cannot answer — a
 * screen area this visitor lacks (pcm_crm_screen(), admin/menu.php) or a
 * route naming no screen at all (public/staff-template.php).
 *
 * $pcm_message is trusted markup, not escaped here — every caller builds it
 * from a translated string plus its own esc_url()/esc_html__() pieces (the
 * "PCM Settings isn't here yet, open it in wp-admin" link needs an actual
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
			<span class="pcm-crm-front-brand">
				<?php // pcm_crm_front_logo_url() (includes/urls.php, PCM Settings › Platform
				// › Employee Portal Settings) is the admin's own choice for this nav;
				// unset, it falls back to the site's own logo the same way the Client
				// Portal header does (theme header.php) — never wrapped in a link,
				// since staff are already home and a click here has nowhere to go. ?>
				<?php $pcm_front_logo = function_exists( 'pcm_crm_front_logo_url' ) ? pcm_crm_front_logo_url() : ''; ?>
				<?php if ( $pcm_front_logo ) : ?>
					<img class="pcm-crm-front-brand-img" src="<?php echo esc_url( $pcm_front_logo ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
				<?php elseif ( function_exists( 'has_custom_logo' ) && has_custom_logo() ) : ?>
					<?php echo wp_get_attachment_image( get_theme_mod( 'custom_logo' ), 'full', false, array( 'class' => 'pcm-crm-front-brand-img' ) ); ?>
				<?php elseif ( function_exists( 'pcm_asset' ) ) : ?>
					<img class="pcm-crm-front-brand-img" src="<?php echo esc_url( pcm_asset( 'images/logo.png' ) ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
				<?php else : ?>
					<?php echo esc_html( get_bloginfo( 'name' ) ); ?>
				<?php endif; ?>
			</span>
			<div class="pcm-crm-front-account">
				<a class="pcm-crm-front-account-name" href="<?php echo esc_url( pcm_crm_front_base_url() . 'profile/' ); ?>">
					<?php echo esc_html( pcm_crm_user_label( $pcm_user ) ); ?>
				</a>
				<a class="pcm-crm-front-logout" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">
					<?php esc_html_e( 'Log out', 'pcm-crm' ); ?>
				</a>
			</div>
		</div>
	</header>
	<?php
}
