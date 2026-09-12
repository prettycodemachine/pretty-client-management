<?php
/**
 * The Projects menu, and the module's assets.
 *
 * A third top-level menu rather than submenus under CRM, because the split this
 * plugin draws is between the work and the setup — and project delivery is its
 * own body of work, not a CRM record alongside Accounts and Contacts. It sits
 * between the two so setup stays last, which is the point of that split.
 *
 * Screen slugs keep the pcm-crm prefix even though the menu does not, because
 * pcm_crm_is_crm_screen() matches on it and that is what enqueues the app. A
 * module screen named anything else would silently load no CSS.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_pm_menu() {
	$pcm_cap = pcm_crm_user_can() ? PCM_CRM_CAP : 'manage_options';

	add_menu_page(
		__( 'Projects', 'pcm-crm' ),
		__( 'Projects', 'pcm-crm' ),
		$pcm_cap,
		'pcm-crm-projects',
		'pcm_crm_pm_render_dashboard',
		'dashicons-clipboard',
		27
	);

	$pcm_pages = array(
		'pcm-crm-projects'      => array( __( 'Dashboard', 'pcm-crm' ), 'pcm_crm_pm_render_dashboard' ),
		'pcm-crm-project-list'  => array( __( 'All Projects', 'pcm-crm' ), 'pcm_crm_pm_render_projects' ),
		'pcm-crm-project-board' => array( __( 'Project Board', 'pcm-crm' ), 'pcm_crm_pm_render_board' ),
		'pcm-crm-resourcing'    => array( __( 'Resourcing', 'pcm-crm' ), 'pcm_crm_pm_render_resourcing' ),
		'pcm-crm-time'          => array( __( 'Time', 'pcm-crm' ), 'pcm_crm_pm_render_time' ),
	);

	foreach ( $pcm_pages as $pcm_slug => $pcm_page ) {
		add_submenu_page( 'pcm-crm-projects', $pcm_page[0], $pcm_page[0], $pcm_cap, $pcm_slug, $pcm_page[1] );
	}
}
add_action( 'admin_menu', 'pcm_crm_pm_menu' );

/* Screens ------------------------------------------------------------------
   Each is the shared shell with its own data-view, the way every CRM screen is.
   -------------------------------------------------------------------------- */

function pcm_crm_pm_render_dashboard() {
	pcm_crm_screen( 'pm-dashboard', __( 'Projects', 'pcm-crm' ), __( 'Budget, schedule and hours across every live project.', 'pcm-crm' ) );
}

function pcm_crm_pm_render_projects() {
	pcm_crm_screen( 'projects', __( 'All Projects', 'pcm-crm' ) );
}

function pcm_crm_pm_render_board() {
	pcm_crm_screen( 'pm-board', __( 'Project Board', 'pcm-crm' ), __( 'Drag a project to move it through its stages.', 'pcm-crm' ) );
}

function pcm_crm_pm_render_resourcing() {
	pcm_crm_screen( 'pm-resourcing', __( 'Resourcing', 'pcm-crm' ), __( 'Who is booked on what, week by week.', 'pcm-crm' ) );
}

function pcm_crm_pm_render_time() {
	pcm_crm_screen( 'time_entries', __( 'Time', 'pcm-crm' ) );
}

/**
 * The module's own script and stylesheet.
 *
 * Enqueued on every CRM screen rather than only the PM ones, because a project's
 * meter appears inside an Account's related list — a core screen. Declaring
 * pcm-crm as a dependency is what guarantees pm.js runs after the app has
 * defined window.PCM_CRM_App but before its DOMContentLoaded listener fires, so
 * registration is synchronous and needs no coordination.
 */
function pcm_crm_pm_assets( $pcm_hook ) {
	if ( ! pcm_crm_is_crm_screen( $pcm_hook ) ) {
		return;
	}

	wp_enqueue_style( 'pcm-crm-pm', pcm_crm_asset( 'pm.css' ), array( 'pcm-crm' ), null );

	// The settings screen is a plain WordPress form with no app mounted, so it
	// takes the skin and not the script — same reasoning as the core enqueue.
	if ( false !== strpos( $pcm_hook, 'pcm-crm-settings' ) ) {
		return;
	}

	wp_enqueue_script( 'pcm-crm-pm', pcm_crm_asset( 'pm.js' ), array( 'pcm-crm' ), null, true );
}
add_action( 'admin_enqueue_scripts', 'pcm_crm_pm_assets' );
