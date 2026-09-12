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

	/* The setup --------------------------------------------------------- */
	add_menu_page(
		__( 'CRM Setup', 'pcm-crm' ),
		__( 'CRM Setup', 'pcm-crm' ),
		$pcm_cap,
		'pcm-crm-settings',
		'pcm_crm_render_settings',
		'dashicons-admin-generic',
		27
	);

	// Page slugs are unchanged on purpose: a notification email links to
	// page=pcm-crm-contacts and a drill-down to page=pcm-crm-reports, and
	// moving a screen between menus must not break a link already sent.
	$pcm_setup = array(
		'pcm-crm-settings'    => array( __( 'Settings', 'pcm-crm' ), 'pcm_crm_render_settings' ),
		'pcm-crm-templates'   => array( __( 'Email Templates', 'pcm-crm' ), 'pcm_crm_render_templates' ),
		'pcm-crm-sequences'   => array( __( 'Sequences', 'pcm-crm' ), 'pcm_crm_render_sequences' ),
		'pcm-crm-schedules'   => array( __( 'Scheduled Reports', 'pcm-crm' ), 'pcm_crm_render_schedules' ),
		'pcm-crm-recycle-bin' => array( __( 'Recycle Bin', 'pcm-crm' ), 'pcm_crm_render_recycle_bin' ),
	);

	foreach ( $pcm_setup as $pcm_slug => $pcm_page ) {
		add_submenu_page( 'pcm-crm-settings', $pcm_page[0], $pcm_page[0], $pcm_cap, $pcm_slug, $pcm_page[1] );
	}
}
add_action( 'admin_menu', 'pcm_crm_menu' );

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

function pcm_crm_admin_assets( $pcm_hook ) {
	if ( ! pcm_crm_is_crm_screen( $pcm_hook ) ) {
		return;
	}

	// Same two faces as the site, so the CRM reads as the same product. The
	// display face is only used for headings, hence the two weights.
	wp_enqueue_style(
		'pcm-crm-fonts',
		'https://fonts.googleapis.com/css2?family=Baloo+2:wght@500;600;700&family=Nunito+Sans:wght@400;600;700&display=swap',
		array(),
		null
	);

	wp_enqueue_style( 'pcm-crm', pcm_crm_asset( 'crm.css' ), array( 'pcm-crm-fonts' ), null );

	// The settings screen is a plain WordPress form; it needs the skin but not
	// the app, and loading the app there would mount it against no container.
	if ( false !== strpos( $pcm_hook, 'pcm-crm-settings' ) ) {
		return;
	}

	wp_enqueue_script( 'pcm-crm-charts', pcm_crm_asset( 'charts.js' ), array(), null, true );
	wp_enqueue_script( 'pcm-crm', pcm_crm_asset( 'crm.js' ), array( 'pcm-crm-charts' ), null, true );

	wp_localize_script( 'pcm-crm', 'PCM_CRM', array(
		'root'     => esc_url_raw( rest_url( PCM_CRM_REST::NS ) ),
		'nonce'    => wp_create_nonce( 'wp_rest' ),
		'adminUrl' => esc_url_raw( admin_url( 'admin.php' ) ),
		'exportUrl' => esc_url_raw( admin_url( 'admin-post.php' ) ),
		'exportNonce' => wp_create_nonce( 'pcm_crm_export' ),
		'currentUser' => get_current_user_id(),
	) );
}
add_action( 'admin_enqueue_scripts', 'pcm_crm_admin_assets' );

/**
 * The shell every app screen shares.
 *
 * data-view tells crm.js which screen to build; everything else about the page
 * is the same, so there is one shell rather than eight near-copies.
 */
function pcm_crm_screen( $pcm_view, $pcm_title, $pcm_subtitle = '' ) {
	if ( ! pcm_crm_user_can() ) {
		wp_die( esc_html__( 'You do not have access to the CRM.', 'pcm-crm' ) );
	}
	?>
	<div class="wrap pcm-crm" data-view="<?php echo esc_attr( $pcm_view ); ?>" data-theme="<?php echo esc_attr( pcm_crm_theme() ); ?>">
		<div class="pcm-crm-head">
			<div>
				<h1><?php echo esc_html( $pcm_title ); ?></h1>
				<?php if ( $pcm_subtitle ) : ?>
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
	pcm_crm_screen(
		'recycle',
		__( 'Recycle Bin', 'pcm-crm' ),
		__( 'Deleting a record in the CRM marks it deleted rather than removing it. This is where those go.', 'pcm-crm' )
	);
}

function pcm_crm_render_templates() {
	pcm_crm_screen( 'templates', __( 'Email Templates', 'pcm-crm' ), __( 'Reusable emails, with contact, account and opportunity variables.', 'pcm-crm' ) );
}

function pcm_crm_render_sequences() {
	pcm_crm_screen( 'sequences', __( 'Sequences', 'pcm-crm' ), __( 'A short run of templates, spaced out. Any reply logged against the contact stops it.', 'pcm-crm' ) );
}

function pcm_crm_render_schedules() {
	pcm_crm_screen( 'schedules', __( 'Scheduled Reports', 'pcm-crm' ), pcm_crm_cron_note() );
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
