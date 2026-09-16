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
		'pcm_crm_pm_render_projects',
		'dashicons-clipboard',
		27
	);

	// Only the screens that have a view behind them. Tasks and the RAID log are
	// plain object lists registered in pm.js; the dashboard, the board and the
	// resourcing grid are added as they are built — a menu item whose view does
	// not exist yet renders the app's "Unknown screen" fallback, which is a worse
	// answer than not offering it.
	$pcm_pages = array(
		'pcm-crm-projects' => array( __( 'All Projects', 'pcm-crm' ), 'pcm_crm_pm_render_projects' ),
		'pcm-crm-project-tasks' => array( __( 'Tasks', 'pcm-crm' ), 'pcm_crm_pm_render_tasks' ),
		'pcm-crm-timesheet' => array( __( 'Timesheet', 'pcm-crm' ), 'pcm_crm_pm_render_timesheet' ),
		'pcm-crm-time'     => array( __( 'Time Entries', 'pcm-crm' ), 'pcm_crm_pm_render_time' ),
		'pcm-crm-raid'     => array( __( 'RAID Log', 'pcm-crm' ), 'pcm_crm_pm_render_raid' ),
		'pcm-crm-milestones' => array( __( 'Milestones', 'pcm-crm' ), 'pcm_crm_pm_render_milestones' ),
		'pcm-crm-help-tickets' => array( __( 'Help Tickets', 'pcm-crm' ), 'pcm_crm_pm_render_help_tickets' ),
	);

	foreach ( $pcm_pages as $pcm_slug => $pcm_page ) {
		add_submenu_page( 'pcm-crm-projects', $pcm_page[0], $pcm_page[0], $pcm_cap, $pcm_slug, $pcm_page[1] );
	}
}
add_action( 'admin_menu', 'pcm_crm_pm_menu' );

/* Screens ------------------------------------------------------------------
   Each is the shared shell with its own data-view, the way every CRM screen is.
   -------------------------------------------------------------------------- */

function pcm_crm_pm_render_projects() {
	pcm_crm_screen( 'projects', __( 'Projects', 'pcm-crm' ), __( 'Every project, with the account and opportunity behind it.', 'pcm-crm' ), array( 'app' => 'projects' ) );
}

function pcm_crm_pm_render_tasks() {
	pcm_crm_screen( 'project_tasks', __( 'Tasks', 'pcm-crm' ), __( 'Every task across every project.', 'pcm-crm' ), array( 'app' => 'projects' ) );
}

function pcm_crm_pm_render_timesheet() {
	pcm_crm_screen( 'timesheet', __( 'Timesheet', 'pcm-crm' ), __( 'A week of hours, a row per project. Each row follows its project’s rules for time.', 'pcm-crm' ), array( 'app' => 'projects' ) );
}

function pcm_crm_pm_render_time() {
	pcm_crm_screen( 'time_entries', __( 'Time Entries', 'pcm-crm' ), '', array( 'app' => 'projects' ) );
}

function pcm_crm_pm_render_raid() {
	pcm_crm_screen( 'project_raid', __( 'RAID Log', 'pcm-crm' ), __( 'Risks, assumptions, issues and dependencies, worst first.', 'pcm-crm' ), array( 'app' => 'projects' ) );
}

function pcm_crm_pm_render_milestones() {
	pcm_crm_screen( 'project_milestones', __( 'Milestones', 'pcm-crm' ), __( 'What a client is waiting on next, across every project.', 'pcm-crm' ), array( 'app' => 'projects' ) );
}

function pcm_crm_pm_render_help_tickets() {
	pcm_crm_screen( 'help_tickets', __( 'Help Tickets', 'pcm-crm' ), __( 'Support requests, raised by clients or logged on their behalf.', 'pcm-crm' ), array( 'app' => 'projects' ) );
}

/**
 * The Projects app's bar.
 */
function pcm_crm_pm_app( $pcm_apps ) {
	$pcm_apps['projects'] = array(
		'label' => __( 'Projects', 'pcm-crm' ),
		'area'  => 'pm',
		'items' => array(
			'pcm-crm-projects'      => array( __( 'Projects', 'pcm-crm' ), 'projects' ),
			'pcm-crm-project-tasks' => array( __( 'Tasks', 'pcm-crm' ), 'project_tasks' ),
			'pcm-crm-timesheet'     => array( __( 'Timesheet', 'pcm-crm' ), 'timesheet' ),
			'pcm-crm-time'          => array( __( 'Time Entries', 'pcm-crm' ), 'time_entries' ),
			'pcm-crm-raid'          => array( __( 'RAID Log', 'pcm-crm' ), 'project_raid' ),
			'pcm-crm-milestones'   => array( __( 'Milestones', 'pcm-crm' ), 'project_milestones' ),
			'pcm-crm-help-tickets'  => array( __( 'Help Tickets', 'pcm-crm' ), 'help_tickets' ),
		),
	);

	return $pcm_apps;
}
add_filter( 'pcm_crm_apps', 'pcm_crm_pm_app' );

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
	if ( false !== strpos( $pcm_hook, PCM_CRM_SETUP_SLUG ) ) {
		return;
	}

	// The project record page's Documents tab drives a wp.media picker, which
	// is not loaded on an admin page by default — enqueued here rather than
	// only on the Projects screens, the same reasoning pm.css already uses,
	// since a project can be reached from an Account or Opportunity page too.
	wp_enqueue_media();

	wp_enqueue_script( 'pcm-crm-pm', pcm_crm_asset( 'pm.js' ), array( 'pcm-crm' ), null, true );

	// A separate file, not folded into pm.js: Help Tickets has nothing to do
	// with project-type archetypes, which is what pm.js is mostly organised
	// around. Depends on pcm-crm only — it needs none of pm.js's own helpers.
	wp_enqueue_script( 'pcm-crm-help-tickets', pcm_crm_asset( 'help-tickets.js' ), array( 'pcm-crm' ), null, true );
}
add_action( 'admin_enqueue_scripts', 'pcm_crm_pm_assets' );
