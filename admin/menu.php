<?php
/**
 * The CRM menu and the shells its screens render into.
 *
 * Every screen is the same handful of elements: a heading, a filter bar and a
 * mount point. The content is rendered by crm.js from REST data, which is what
 * lets a filter change repaint the view instead of reloading the page.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Two menus, not one: the work and the setup.
 *
 * Salesforce draws exactly this line, and for the same reason — the screens
 * you use every day and the screens you touch twice a year do not belong in
 * one list. A menu where Contacts sits three items above Scheduled Reports
 * makes both harder to find.
 */
function pcm_crm_menu() {
	$pcm_cap = pcm_crm_user_can() ? PCM_CRM_CAP : 'manage_options';

	/* The work ---------------------------------------------------------- */
	add_menu_page(
		__( 'CRM', 'pcm-crm' ),
		__( 'CRM', 'pcm-crm' ),
		$pcm_cap,
		'pcm-crm',
		'pcm_crm_render_dashboard',
		'dashicons-chart-area',
		26
	);

	$pcm_records = array(
		'pcm-crm'               => array( __( 'Dashboard', 'pcm-crm' ), 'pcm_crm_render_dashboard' ),
		'pcm-crm-accounts'      => array( __( 'Accounts', 'pcm-crm' ), 'pcm_crm_render_accounts' ),
		'pcm-crm-contacts'      => array( __( 'Contacts', 'pcm-crm' ), 'pcm_crm_render_contacts' ),
		'pcm-crm-opportunities' => array( __( 'Opportunities', 'pcm-crm' ), 'pcm_crm_render_opportunities' ),
		'pcm-crm-pipeline'      => array( __( 'Pipeline', 'pcm-crm' ), 'pcm_crm_render_pipeline' ),
		'pcm-crm-activities'    => array( __( 'Activities', 'pcm-crm' ), 'pcm_crm_render_activities' ),
		'pcm-crm-reports'       => array( __( 'Reports', 'pcm-crm' ), 'pcm_crm_render_reports' ),
	);

	foreach ( $pcm_records as $pcm_slug => $pcm_page ) {
		add_submenu_page( 'pcm-crm', $pcm_page[0], $pcm_page[0], $pcm_cap, $pcm_slug, $pcm_page[1] );
	}

	/* The setup ------------------------------------------------------------
	   A top-level menu of its own, not tucked under WordPress's Settings —
	   employee-portal staff with a Settings permission set but no wp-admin
	   Settings access still need to find it, and "under Settings" only ever
	   made sense while every visitor here was an administrator anyway. 28
	   rather than 27: Projects claims 27 when its module is on, and Setup
	   belongs after the work whether it is or not.
	   Its app-backed pages (Templates, Sequences, Schedules, the bin) keep
	   their own slugs on purpose: a notification email links to
	   page=pcm-crm-recycle-bin and a drill-down to one of the others, and
	   moving a screen must not break a link already sent. They no longer get
	   a submenu entry of their own, though — registered here and immediately
	   hidden, so the URL still works but the sidebar shows only CRM Settings.
	   Each is still one click away from CRM Settings' own nav
	   (pcm_crm_setup_open()'s left-hand groups), which is what "lives within
	   CRM Settings" means for them now.
	   ------------------------------------------------------------------- */
	add_menu_page(
		__( 'CRM Settings', 'pcm-crm' ),
		__( 'CRM Settings', 'pcm-crm' ),
		$pcm_cap,
		PCM_CRM_SETUP_SLUG,
		'pcm_crm_render_settings',
		'dashicons-admin-generic',
		28
	);

	// Without this, WordPress's own default submenu item for a single-page
	// top-level menu repeats the top-level label — "CRM Settings" under "CRM
	// Settings" — where the in-frame nav (pcm_crm_setup_open()) already calls
	// this destination "Home".
	add_submenu_page( PCM_CRM_SETUP_SLUG, __( 'Home', 'pcm-crm' ), __( 'Home', 'pcm-crm' ), $pcm_cap, PCM_CRM_SETUP_SLUG, 'pcm_crm_render_settings' );

	$pcm_renderers = array(
		'pcm-crm-templates'   => 'pcm_crm_render_templates',
		'pcm-crm-sequences'   => 'pcm_crm_render_sequences',
		'pcm-crm-schedules'   => 'pcm_crm_render_schedules',
		'pcm-crm-recycle-bin' => 'pcm_crm_render_recycle_bin',
	);

	foreach ( pcm_crm_setup_pages() as $pcm_page ) {
		if ( isset( $pcm_renderers[ $pcm_page['page'] ] ) ) {
			// Registered, deliberately NOT followed by remove_submenu_page().
			// That call does not just hide a sidebar row — it unsets the
			// entry from $GLOBALS['submenu'], which is also where WordPress's
			// own dispatch (user_can_access_admin_page(), via
			// get_admin_page_parent()) looks up which top-level menu a
			// requested page belongs to. Removing it here left every one of
			// these four pages unable to resolve their own parent at
			// request time — "Sorry, you are not allowed to access this
			// page," WordPress's own message, not this plugin's — the
			// moment CRM Settings became a top-level menu instead of a
			// submenu of Settings (whose parent registration WordPress
			// already keeps stable on its own, independent of this
			// plugin's $submenu entries). Hidden from the sidebar with CSS
			// instead (pcm_crm_hide_setup_submenu_items() below), which
			// touches only what is drawn, not what WordPress can route to.
			add_submenu_page( PCM_CRM_SETUP_SLUG, $pcm_page['label'], $pcm_page['label'], $pcm_cap, $pcm_page['page'], $pcm_renderers[ $pcm_page['page'] ] );
		}
	}
}
add_action( 'admin_menu', 'pcm_crm_menu' );

/**
 * Hide the app-backed Setup pages' sidebar rows without touching WordPress's
 * own $submenu registration — see the comment in pcm_crm_menu() above for
 * why removing the registration entirely broke the pages themselves. Each
 * is still one click away through CRM Settings' own left-hand nav
 * (pcm_crm_setup_open()'s groups), which is what "hidden from the sidebar"
 * means for them now.
 */
function pcm_crm_hide_setup_submenu_items() {
	$pcm_slugs = array( 'pcm-crm-templates', 'pcm-crm-sequences', 'pcm-crm-schedules', 'pcm-crm-recycle-bin' );
	?>
	<style>
		<?php foreach ( $pcm_slugs as $pcm_slug ) : ?>
		#adminmenu li:has(> a[href*="page=<?php echo esc_attr( $pcm_slug ); ?>"]) { display: none; }
		<?php endforeach; ?>
	</style>
	<?php
}
add_action( 'admin_head', 'pcm_crm_hide_setup_submenu_items' );

/**
 * Is the current screen one of ours?
 *
 * Used to keep the CRM's stylesheet and app off every other admin page — the
 * CSS restyles enough that leaking it into the post editor would be obvious.
 */
function pcm_crm_is_crm_screen( $pcm_hook = '' ) {
	if ( ! $pcm_hook ) {
		$pcm_screen = get_current_screen();
		$pcm_hook   = $pcm_screen ? $pcm_screen->id : '';
	}

	return false !== strpos( $pcm_hook, 'pcm-crm' );
}

/**
 * Enqueue the app's own CSS and JS, and localize its config.
 *
 * One definition, called from both hosts (the admin hook below and
 * pcm_crm_front_assets() in public/staff.php), which is what stops the two
 * from drifting the way two independent copies of an enqueue list always do.
 *
 * $pcm_args carries what only the caller can know: 'setup_frame' (draw inside
 * the CRM Settings skin — needs setup.css) and 'no_app' (this page has no
 * crm.js container to mount at all — the bare Settings tabs, a plain
 * WordPress form, as opposed to the app-backed Setup pages like Templates,
 * which have both the skin and the app), and 'record_id' (the front-end
 * router's own path segment — the admin host's #id=N fragment never reaches
 * the server, so only the front end ever has one to pass).
 */
function pcm_crm_enqueue_app( $pcm_host, array $pcm_args = array() ) {
	// Same two faces as the site, so the CRM reads as the same product. The
	// display face is only used for headings, hence the two weights.
	wp_enqueue_style(
		'pcm-crm-fonts',
		'https://fonts.googleapis.com/css2?family=Baloo+2:wght@500;600;700&family=Nunito+Sans:wght@400;600;700&display=swap',
		array(),
		null
	);

	wp_enqueue_style( 'pcm-crm', pcm_crm_asset( 'crm.css' ), array( 'pcm-crm-fonts' ), null );

	if ( ! empty( $pcm_args['setup_frame'] ) ) {
		wp_enqueue_style( 'pcm-crm-setup', pcm_crm_asset( 'setup.css' ), array( 'pcm-crm' ), null );
	}

	// wp-admin's own admin bar is what has always pulled dashicons in for
	// free; the front end carries no admin bar for a visitor without one, so
	// it has to ask for the font explicitly or every dashicons glyph in the
	// app (crm.js, pm.js) renders as an empty box.
	if ( 'front' === $pcm_host ) {
		wp_enqueue_style( 'dashicons' );
	}

	if ( ! empty( $pcm_args['no_app'] ) ) {
		return;
	}

	wp_enqueue_script( 'pcm-crm-charts', pcm_crm_asset( 'charts.js' ), array(), null, true );
	wp_enqueue_script( 'pcm-crm', pcm_crm_asset( 'crm.js' ), array( 'pcm-crm-charts' ), null, true );

	wp_localize_script( 'pcm-crm', 'PCM_CRM', array(
		'root'        => esc_url_raw( rest_url( PCM_CRM_REST::NS ) ),
		'nonce'       => wp_create_nonce( 'wp_rest' ),
		'adminUrl'    => esc_url_raw( admin_url( 'admin.php' ) ),
		'exportUrl'   => esc_url_raw( admin_url( 'admin-post.php' ) ),
		'exportNonce' => wp_create_nonce( 'pcm_crm_export' ),
		'currentUser' => get_current_user_id(),
		// Carried here as well as in /bootstrap because the nav and the app
		// bar are drawn before that request resolves.
		'permissions' => pcm_crm_effective_permissions(),
		'host'        => $pcm_host,
		'frontBase'   => esc_url_raw( pcm_crm_front_base_url() ),
		'screens'     => pcm_crm_front_slug_map(),
		'recordId'    => isset( $pcm_args['record_id'] ) ? (int) $pcm_args['record_id'] : 0,
	) );
}

/**
 * Whether an admin hook is a specific page's own screen.
 *
 * Matched on the slug at the end of the hook suffix, the same idiom
 * pcm_crm_is_setup_screen() (includes/setup.php) already uses and for the
 * same reason: WordPress prefixes a submenu's hook with its parent's
 * sanitised title, so a plain substring test drifts whenever a page's parent
 * changes — which is exactly what silently broke here once CRM Settings
 * became a top-level menu (`b24a67c` then this phase): the app-backed Setup
 * pages (Templates, Sequences, Schedules, the bin) are now submenus of
 * pcm-crm-settings, so a hook-contains-"pcm-crm-settings" test started
 * matching them too and wrongly skipped loading crm.js on every one of them.
 */
function pcm_crm_hook_is_page( $pcm_hook, $pcm_slug ) {
	$pcm_suffix = '_page_' . $pcm_slug;

	return substr( $pcm_hook, -strlen( $pcm_suffix ) ) === $pcm_suffix;
}

function pcm_crm_admin_assets( $pcm_hook ) {
	if ( ! pcm_crm_is_crm_screen( $pcm_hook ) ) {
		return;
	}

	// The bare CRM Settings screen (Pipeline, Fields, Theme, …) is a plain
	// WordPress form with nothing for crm.js to mount against; every other
	// Setup page — the app-backed ones included — has an app underneath and
	// needs it. Both kinds share the Setup skin.
	pcm_crm_enqueue_app( 'admin', array(
		'setup_frame' => pcm_crm_is_setup_screen( $pcm_hook ),
		'no_app'      => pcm_crm_hook_is_page( $pcm_hook, PCM_CRM_SETUP_SLUG ),
	) );
}
add_action( 'admin_enqueue_scripts', 'pcm_crm_admin_assets' );

/**
 * Which permission area a screen belongs to.
 *
 * A screen inside the CRM Settings frame is Settings whatever app it borrows
 * its body from — the recycle bin and the template list are configuration, and
 * granting somebody the CRM should not hand them the bin. Otherwise the answer
 * is the app's own declared area, so a module brings one with it.
 */
function pcm_crm_screen_area( array $pcm_args ) {
	if ( ! empty( $pcm_args['setup'] ) ) { return 'settings'; }

	$pcm_app  = isset( $pcm_args['app'] ) ? $pcm_args['app'] : 'crm';
	$pcm_apps = pcm_crm_apps();

	return isset( $pcm_apps[ $pcm_app ]['area'] ) ? $pcm_apps[ $pcm_app ]['area'] : 'crm';
}

/**
 * Whether this host wants wp-admin's own "wrap" margin class.
 *
 * "wrap" is a wp-admin class, and the theme's own front-end stylesheet
 * defines a class of the same name — a 1120px centred column with 28px side
 * padding, styling meant for a page of body copy, not a data table. Dropping
 * it on the front host is what keeps that collision from ever happening,
 * rather than overriding it back out with a counter-rule once it has landed.
 * Shared by pcm_crm_shell_class() below and pcm_crm_setup_open()
 * (includes/setup.php), which has the identical "wrap" problem on its own
 * outer frame.
 */
function pcm_crm_wants_wrap( $pcm_host ) {
	return 'admin' === $pcm_host;
}

/**
 * The class on the shell's root element.
 *
 * The front host also carries its own pcm-crm-front marker, not just the
 * absence of "wrap" — crm.css needs something to target for the handful of
 * rules that assume an admin bar is above the shell (the sticky filter bar's
 * top offset) and have nothing to assume on the front end instead.
 */
function pcm_crm_shell_class( $pcm_host, $pcm_setup ) {
	if ( $pcm_setup ) { return 'pcm-crm pcm-crm-embedded'; }
	if ( 'front' === $pcm_host ) { return 'pcm-crm pcm-crm-front'; }

	return ( pcm_crm_wants_wrap( $pcm_host ) ? 'wrap ' : '' ) . 'pcm-crm';
}

/**
 * The shell every app screen shares.
 *
 * data-view tells crm.js which screen to build; everything else about the page
 * is the same, so there is one shell rather than eight near-copies.
 *
 * The host is resolved from the request (pcm_crm_is_front_request()), never
 * passed by a caller: every render callback below is the same function the
 * front-end router calls, and threading a host argument through all of them
 * would only mean the sixteenth one added later forgets it.
 */
function pcm_crm_screen( $pcm_view, $pcm_title, $pcm_subtitle = '', array $pcm_args = array() ) {
	// A CRM Settings page that is an app screen underneath — templates, the bin
	// — draws inside the CRM Settings frame, which carries its title and
	// description, so the shell below keeps only the actions row.
	$pcm_setup = isset( $pcm_args['setup'] ) ? $pcm_args['setup'] : '';
	$pcm_host  = isset( $pcm_args['host'] ) ? $pcm_args['host'] : ( pcm_crm_is_front_request() ? 'front' : 'admin' );

	if ( ! pcm_crm_can( pcm_crm_screen_area( $pcm_args ), 'view' ) ) {
		if ( 'front' === $pcm_host ) {
			pcm_crm_front_deny();
			return;
		}

		wp_die( esc_html__( 'You do not have access to this part of the CRM.', 'pcm-crm' ) );
	}

	if ( $pcm_setup ) {
		pcm_crm_setup_open( $pcm_setup );
	}
	?>
	<div class="<?php echo esc_attr( pcm_crm_shell_class( $pcm_host, $pcm_setup ) ); ?>" data-view="<?php echo esc_attr( $pcm_view ); ?>" data-theme="<?php echo esc_attr( pcm_crm_theme() ); ?>" data-host="<?php echo esc_attr( $pcm_host ); ?>">
		<?php if ( ! $pcm_setup ) : ?>
			<?php pcm_crm_app_bar( $pcm_view, isset( $pcm_args['app'] ) ? $pcm_args['app'] : 'crm', $pcm_host ); ?>
		<?php endif; ?>
		<div class="pcm-crm-head<?php echo $pcm_setup ? ' pcm-crm-head-embedded' : ''; ?>">
			<div>
				<?php if ( ! $pcm_setup ) : ?>
					<h1><?php echo esc_html( $pcm_title ); ?></h1>
					<?php if ( $pcm_subtitle ) : ?>
						<p class="pcm-crm-sub"><?php echo esc_html( $pcm_subtitle ); ?></p>
					<?php endif; ?>
				<?php elseif ( $pcm_subtitle ) : ?>
					<p class="pcm-crm-sub"><?php echo esc_html( $pcm_subtitle ); ?></p>
				<?php endif; ?>
			</div>
			<div class="pcm-crm-head-actions" data-role="actions"></div>
		</div>

		<div class="pcm-crm-filters" data-role="filters"></div>
		<div class="pcm-crm-body" data-role="body">
			<p class="pcm-crm-loading"><?php esc_html_e( 'Loading…', 'pcm-crm' ); ?></p>
		</div>
		<div class="pcm-crm-drawer" data-role="drawer" hidden></div>
		<div class="pcm-crm-scrim" data-role="scrim" hidden></div>
	</div>
	<?php
	if ( $pcm_setup ) {
		pcm_crm_setup_close();
	}
}

/**
 * The screens each app offers, in bar order: slug => array( label, view ).
 *
 * A filter rather than a list inside pcm_crm_app_bar(), so the Projects module
 * adds its app without core naming it, and a switched-off module leaves no bar.
 */
function pcm_crm_apps() {
	return apply_filters( 'pcm_crm_apps', array(
		'crm' => array(
			'label' => __( 'CRM', 'pcm-crm' ),
			// The permission area this app's screens answer to. Declared here
			// rather than on each item, because an app is the unit somebody is
			// granted — and the item tuples are read positionally by the app
			// bar, so a third element would have to be threaded through there.
			'area'  => 'crm',
			'items' => array(
				'pcm-crm'               => array( __( 'Dashboard', 'pcm-crm' ), 'dashboard' ),
				'pcm-crm-accounts'      => array( __( 'Accounts', 'pcm-crm' ), 'accounts' ),
				'pcm-crm-contacts'      => array( __( 'Contacts', 'pcm-crm' ), 'contacts' ),
				'pcm-crm-opportunities' => array( __( 'Opportunities', 'pcm-crm' ), 'opportunities' ),
				'pcm-crm-pipeline'      => array( __( 'Pipeline', 'pcm-crm' ), 'pipeline' ),
				'pcm-crm-activities'    => array( __( 'Activities', 'pcm-crm' ), 'activities' ),
				'pcm-crm-reports'       => array( __( 'Reports', 'pcm-crm' ), 'reports' ),
			),
		),
	) );
}

/**
 * The bar across the top of a work screen: which app you are in, its screens,
 * and the door to CRM Settings.
 *
 * It is what makes a record screen read as part of an app rather than a lone
 * WordPress page — and, with CRM Settings drawn in its own frame, what makes
 * the two unmistakable for each other. Unchanged between hosts apart from its
 * links: on the front end it is the row directly under the brand bar
 * (pcm_crm_front_nav(), public/staff.php), reusing the same registries —
 * pcm_crm_apps() — rather than a second nav built by hand, so a screen a
 * module adds appears in both without a second list.
 */
function pcm_crm_app_bar( $pcm_view, $pcm_app, $pcm_host = '' ) {
	$pcm_host = $pcm_host ? $pcm_host : ( pcm_crm_is_front_request() ? 'front' : 'admin' );
	$pcm_apps = pcm_crm_apps();

	if ( ! isset( $pcm_apps[ $pcm_app ] ) ) {
		return;
	}
	?>
	<nav class="pcm-crm-appbar" aria-label="<?php echo esc_attr( $pcm_apps[ $pcm_app ]['label'] ); ?>">
		<span class="pcm-crm-appbar-name"><?php echo esc_html( $pcm_apps[ $pcm_app ]['label'] ); ?></span>
		<?php if ( count( $pcm_apps ) > 1 ) : ?>
			<span class="pcm-crm-appbar-switch">
				<?php foreach ( $pcm_apps as $pcm_key => $pcm_other ) : ?>
					<?php if ( $pcm_key !== $pcm_app ) : ?>
						<a href="<?php echo esc_url( pcm_crm_screen_url( key( $pcm_other['items'] ), array(), $pcm_host ) ); ?>">
							<?php
							/* translators: %s: app name */
							echo esc_html( sprintf( __( 'Go to %s', 'pcm-crm' ), $pcm_other['label'] ) );
							?>
						</a>
					<?php endif; ?>
				<?php endforeach; ?>
			</span>
		<?php endif; ?>
		<ul class="pcm-crm-appbar-items">
			<?php foreach ( $pcm_apps[ $pcm_app ]['items'] as $pcm_slug => $pcm_item ) : ?>
				<li>
					<a href="<?php echo esc_url( pcm_crm_screen_url( $pcm_slug, array(), $pcm_host ) ); ?>"
						class="<?php echo $pcm_item[1] === $pcm_view ? 'is-active' : ''; ?>"
						<?php echo $pcm_item[1] === $pcm_view ? 'aria-current="page"' : ''; ?>>
						<?php echo esc_html( $pcm_item[0] ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<a class="pcm-crm-appbar-setup" href="<?php echo esc_url( pcm_crm_setup_url( 'home' ) ); ?>">
			<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
			<?php esc_html_e( 'CRM Settings', 'pcm-crm' ); ?>
		</a>
	</nav>
	<?php
}

/**
 * Every CRM-app screen's admin slug, mapped to the render callback that
 * draws it — what the front-end router (public/staff-template.php) needs to
 * dispatch a URL to a screen.
 *
 * A second list rather than folding pcm_crm_menu()'s own $pcm_records into
 * this one: that function's menu registration is order- and capability-
 * sensitive in ways worth leaving alone, and the two lists drifting apart is
 * a slug pcm_crm_apps() names but this one does not — caught by
 * tests/permissions.php the same way an unmapped REST route is.
 */
function pcm_crm_screen_callbacks() {
	return apply_filters( 'pcm_crm_screen_callbacks', array(
		'pcm-crm'               => 'pcm_crm_render_dashboard',
		'pcm-crm-accounts'      => 'pcm_crm_render_accounts',
		'pcm-crm-contacts'      => 'pcm_crm_render_contacts',
		'pcm-crm-opportunities' => 'pcm_crm_render_opportunities',
		'pcm-crm-pipeline'      => 'pcm_crm_render_pipeline',
		'pcm-crm-activities'    => 'pcm_crm_render_activities',
		'pcm-crm-reports'       => 'pcm_crm_render_reports',
	) );
}

function pcm_crm_render_dashboard() {
	pcm_crm_screen( 'dashboard', __( 'CRM Dashboard', 'pcm-crm' ) );
}

function pcm_crm_render_accounts() {
	pcm_crm_screen( 'accounts', __( 'Accounts', 'pcm-crm' ) );
}

function pcm_crm_render_contacts() {
	pcm_crm_screen( 'contacts', __( 'Contacts', 'pcm-crm' ) );
}

function pcm_crm_render_opportunities() {
	pcm_crm_screen( 'opportunities', __( 'Opportunities', 'pcm-crm' ) );
}

function pcm_crm_render_pipeline() {
	pcm_crm_screen( 'pipeline', __( 'Pipeline', 'pcm-crm' ), __( 'Drag a deal to move it between stages.', 'pcm-crm' ) );
}

function pcm_crm_render_activities() {
	pcm_crm_screen( 'activities', __( 'Activities', 'pcm-crm' ) );
}

function pcm_crm_render_recycle_bin() {
	pcm_crm_screen( 'recycle', __( 'Recycle Bin', 'pcm-crm' ), '', array( 'setup' => 'recycle' ) );
}

function pcm_crm_render_templates() {
	pcm_crm_screen( 'templates', __( 'Email Templates', 'pcm-crm' ), '', array( 'setup' => 'templates' ) );
}

function pcm_crm_render_sequences() {
	pcm_crm_screen( 'sequences', __( 'Sequences', 'pcm-crm' ), '', array( 'setup' => 'sequences' ) );
}

function pcm_crm_render_schedules() {
	pcm_crm_screen( 'schedules', __( 'Scheduled Reports', 'pcm-crm' ), pcm_crm_cron_note(), array( 'setup' => 'schedules' ) );
}

/**
 * Say plainly how delivery actually happens.
 *
 * WordPress's cron only runs when someone visits the site, so on a quiet
 * install a schedule fires late — which looks like a bug unless it is said
 * out loud where the schedules are managed.
 */
function pcm_crm_cron_note() {
	if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
		return __( 'WordPress cron is disabled here, so delivery depends on a server cron calling wp-cron.php.', 'pcm-crm' );
	}

	return __( 'Delivery runs on WordPress cron, which fires on site visits — a quiet site may send a little late.', 'pcm-crm' );
}

function pcm_crm_render_reports() {
	pcm_crm_screen( 'reports', __( 'Reports', 'pcm-crm' ), __( 'Group and filter any object, then export it.', 'pcm-crm' ) );
}
