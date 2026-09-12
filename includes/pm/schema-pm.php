<?php
/**
 * The project management tables.
 *
 * Appended on pcm_crm_table_definitions rather than declared inside
 * PCM_CRM_Schema, so the module owns its own storage — and collected whether or
 * not the module is switched on, for the reason set out in includes/modules.php.
 *
 * Same house rules as the core tables: two spaces after PRIMARY KEY, one field
 * per line, every KEY named explicitly or dbDelta re-adds it on every run, no
 * real foreign keys, varchar(190) as the ceiling for anything indexed, and the
 * full audit spine plus is_test so the seeder can take its own rows back out.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * A PM table's full name.
 *
 * Wraps the core helper rather than adding eight accessors to PCM_CRM_Schema: a
 * module reaching into that class for naming is the coupling the module gate
 * exists to prevent, and table() is generic precisely so it does not need them.
 */
function pcm_crm_pm_table( $pcm_name ) {
	return PCM_CRM_Schema::table( $pcm_name );
}

function pcm_crm_pm_projects_table()     { return pcm_crm_pm_table( 'projects' ); }
function pcm_crm_pm_tasks_table()        { return pcm_crm_pm_table( 'project_tasks' ); }
function pcm_crm_pm_raid_table()         { return pcm_crm_pm_table( 'project_raid' ); }
function pcm_crm_pm_roles_table()        { return pcm_crm_pm_table( 'project_roles' ); }
function pcm_crm_pm_time_table()         { return pcm_crm_pm_table( 'time_entries' ); }
function pcm_crm_pm_periods_table()      { return pcm_crm_pm_table( 'retainer_periods' ); }
function pcm_crm_pm_allocations_table()  { return pcm_crm_pm_table( 'allocations' ); }
function pcm_crm_pm_reports_table()      { return pcm_crm_pm_table( 'status_reports' ); }

/**
 * Every PM table's name, for the seeder and for the tests.
 */
function pcm_crm_pm_tables() {
	return array(
		'projects'         => pcm_crm_pm_projects_table(),
		'project_tasks'    => pcm_crm_pm_tasks_table(),
		'project_raid'     => pcm_crm_pm_raid_table(),
		'project_roles'    => pcm_crm_pm_roles_table(),
		'time_entries'     => pcm_crm_pm_time_table(),
		'retainer_periods' => pcm_crm_pm_periods_table(),
		'allocations'      => pcm_crm_pm_allocations_table(),
		'status_reports'   => pcm_crm_pm_reports_table(),
	);
}

function pcm_crm_pm_definitions( array $pcm_tables, $pcm_charset ) {
	$pcm_projects    = pcm_crm_pm_projects_table();
	$pcm_tasks       = pcm_crm_pm_tasks_table();
	$pcm_raid        = pcm_crm_pm_raid_table();
	$pcm_roles       = pcm_crm_pm_roles_table();
	$pcm_time        = pcm_crm_pm_time_table();
	$pcm_periods     = pcm_crm_pm_periods_table();
	$pcm_allocations = pcm_crm_pm_allocations_table();
	$pcm_reports     = pcm_crm_pm_reports_table();

	/* Project -------------------------------------------------------------
	   stage_entered_date and closed_date are denormalised onto the row the way
	   an opportunity's are, so "stalled" is a date comparison rather than a
	   join. Unlike an opportunity there is no history table behind it: a
	   project changes stage about ten times in its life, and nothing reports on
	   project stage velocity.
	   ------------------------------------------------------------------- */
	$pcm_tables[] = "CREATE TABLE {$pcm_projects} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		sf_id varchar(18) DEFAULT NULL,
		account_id bigint(20) unsigned NOT NULL DEFAULT 0,
		opportunity_id bigint(20) unsigned NOT NULL DEFAULT 0,
		name varchar(255) NOT NULL DEFAULT '',
		project_code varchar(40) NOT NULL DEFAULT '',
		project_type varchar(80) NOT NULL DEFAULT '',
		stage_name varchar(80) NOT NULL DEFAULT '',
		health varchar(20) NOT NULL DEFAULT '',
		health_note varchar(255) NOT NULL DEFAULT '',
		start_date date DEFAULT NULL,
		end_date date DEFAULT NULL,
		actual_end_date date DEFAULT NULL,
		budget_amount decimal(18,2) DEFAULT NULL,
		budget_hours decimal(10,2) DEFAULT NULL,
		retainer_hours decimal(10,2) DEFAULT NULL,
		retainer_period varchar(20) NOT NULL DEFAULT '',
		retainer_rollover tinyint(1) NOT NULL DEFAULT 0,
		retainer_rollover_cap decimal(10,2) DEFAULT NULL,
		retainer_start_date date DEFAULT NULL,
		default_bill_rate decimal(18,2) DEFAULT NULL,
		default_cost_rate decimal(18,2) DEFAULT NULL,
		stage_entered_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		closed_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		is_closed tinyint(1) NOT NULL DEFAULT 0,
		description longtext,
		owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
		last_modified_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		last_modified_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		is_deleted tinyint(1) NOT NULL DEFAULT 0,
		is_test tinyint(1) NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY pcm_proj_account (account_id),
		KEY pcm_proj_opp (opportunity_id),
		KEY pcm_proj_stage (stage_name),
		KEY pcm_proj_type (project_type),
		KEY pcm_proj_window (start_date,end_date),
		KEY pcm_proj_owner (owner_id),
		KEY pcm_proj_deleted (is_deleted),
		KEY pcm_proj_test (is_test),
		KEY pcm_proj_sf (sf_id)
	) {$pcm_charset};";

	/* Project task --------------------------------------------------------
	   One table with an is_milestone flag rather than two. A milestone is a task
	   with no duration; time is logged against tasks; both appear in the same
	   related list and on the same timeline. Two tables would double the lookup
	   on the time-entry form for one boolean's worth of difference.
	   ------------------------------------------------------------------- */
	$pcm_tables[] = "CREATE TABLE {$pcm_tasks} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		project_id bigint(20) unsigned NOT NULL DEFAULT 0,
		parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
		name varchar(255) NOT NULL DEFAULT '',
		status varchar(40) NOT NULL DEFAULT '',
		is_milestone tinyint(1) NOT NULL DEFAULT 0,
		assignee_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		start_date date DEFAULT NULL,
		due_date date DEFAULT NULL,
		completed_date date DEFAULT NULL,
		estimated_hours decimal(10,2) DEFAULT NULL,
		sort_order int(11) NOT NULL DEFAULT 0,
		description longtext,
		owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
		last_modified_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		last_modified_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		is_deleted tinyint(1) NOT NULL DEFAULT 0,
		is_test tinyint(1) NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY pcm_task_project (project_id,status),
		KEY pcm_task_milestone (project_id,is_milestone,due_date),
		KEY pcm_task_assignee (assignee_user_id,status),
		KEY pcm_task_due (due_date),
		KEY pcm_task_deleted (is_deleted),
		KEY pcm_task_test (is_test)
	) {$pcm_charset};";

	/* RAID ----------------------------------------------------------------
	   Risks, Assumptions, Issues and Dependencies are one record with one
	   lifecycle and four vocabularies, so they are one table with a
	   discriminator — the way Activities are one table for Tasks and Events.

	   severity is stored as well as derived: it is a pure function of
	   probability and impact, both set in the same write, so it cannot go stale
	   between writes the way a day count can — and it is the sort key, which an
	   expression cannot be without a filesort.
	   ------------------------------------------------------------------- */
	$pcm_tables[] = "CREATE TABLE {$pcm_raid} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		project_id bigint(20) unsigned NOT NULL DEFAULT 0,
		raid_type varchar(20) NOT NULL DEFAULT '',
		title varchar(255) NOT NULL DEFAULT '',
		status varchar(40) NOT NULL DEFAULT '',
		probability varchar(20) NOT NULL DEFAULT '',
		impact varchar(20) NOT NULL DEFAULT '',
		severity tinyint(3) unsigned NOT NULL DEFAULT 0,
		raised_date date DEFAULT NULL,
		due_date date DEFAULT NULL,
		resolved_date date DEFAULT NULL,
		owner_contact_id bigint(20) unsigned NOT NULL DEFAULT 0,
		mitigation longtext,
		resolution longtext,
		description longtext,
		owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
		last_modified_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		last_modified_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		is_deleted tinyint(1) NOT NULL DEFAULT 0,
		is_test tinyint(1) NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY pcm_raid_project (project_id,raid_type,status),
		KEY pcm_raid_severity (project_id,severity),
		KEY pcm_raid_due (due_date),
		KEY pcm_raid_deleted (is_deleted),
		KEY pcm_raid_test (is_test)
	) {$pcm_charset};";

	/* Project contact role ------------------------------------------------
	   Internal people are WordPress users, client contacts are CRM contact
	   rows, and a partner is a person at a partner account. One table, because
	   every reader asks the same question — who is on this project and in what
	   capacity — and three tables would make that a UNION and three related
	   lists where one belongs. party_type says which id column is filled.

	   No unique key: one person legitimately holds two roles on a project, and
	   a constraint would make that an error rather than a fact.
	   ------------------------------------------------------------------- */
	$pcm_tables[] = "CREATE TABLE {$pcm_roles} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		project_id bigint(20) unsigned NOT NULL DEFAULT 0,
		party_type varchar(20) NOT NULL DEFAULT '',
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		contact_id bigint(20) unsigned NOT NULL DEFAULT 0,
		partner_account_id bigint(20) unsigned NOT NULL DEFAULT 0,
		role varchar(80) NOT NULL DEFAULT '',
		is_primary tinyint(1) NOT NULL DEFAULT 0,
		bill_rate decimal(18,2) DEFAULT NULL,
		cost_rate decimal(18,2) DEFAULT NULL,
		start_date date DEFAULT NULL,
		end_date date DEFAULT NULL,
		description longtext,
		owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
		last_modified_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		last_modified_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		is_deleted tinyint(1) NOT NULL DEFAULT 0,
		is_test tinyint(1) NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY pcm_role_project (project_id,party_type),
		KEY pcm_role_user (user_id,project_id),
		KEY pcm_role_contact (contact_id),
		KEY pcm_role_partner (partner_account_id),
		KEY pcm_role_deleted (is_deleted),
		KEY pcm_role_test (is_test)
	) {$pcm_charset};";

	/* Time entry ----------------------------------------------------------
	   The only one of these that gets big, so its indexes are the ones that
	   matter. The rates are snapshotted onto the row at write time; the
	   products are deliberately not stored, because SUM(hours * bill_rate) is
	   one expression over an indexed range and a stored product is a second
	   source of truth that a hand-edit of hours desynchronises.
	   ------------------------------------------------------------------- */
	$pcm_tables[] = "CREATE TABLE {$pcm_time} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		project_id bigint(20) unsigned NOT NULL DEFAULT 0,
		task_id bigint(20) unsigned NOT NULL DEFAULT 0,
		retainer_period_id bigint(20) unsigned NOT NULL DEFAULT 0,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		entry_date date DEFAULT NULL,
		hours decimal(9,2) NOT NULL DEFAULT 0.00,
		is_billable tinyint(1) NOT NULL DEFAULT 1,
		bill_rate decimal(18,2) DEFAULT NULL,
		cost_rate decimal(18,2) DEFAULT NULL,
		rate_source varchar(20) NOT NULL DEFAULT '',
		invoice_ref varchar(190) NOT NULL DEFAULT '',
		description longtext,
		owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
		last_modified_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		last_modified_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		is_deleted tinyint(1) NOT NULL DEFAULT 0,
		is_test tinyint(1) NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY pcm_time_project (project_id,entry_date),
		KEY pcm_time_user (user_id,entry_date),
		KEY pcm_time_task (task_id),
		KEY pcm_time_period (retainer_period_id),
		KEY pcm_time_billable (project_id,is_billable),
		KEY pcm_time_invoice (invoice_ref),
		KEY pcm_time_deleted (is_deleted),
		KEY pcm_time_test (is_test)
	) {$pcm_charset};";

	/* Retainer period -----------------------------------------------------
	   A table rather than periods computed from a start date and a monthly
	   figure, because rollover is path-dependent — this period's carry-in is
	   last period's closing balance — and an allotment gets renegotiated
	   mid-contract. Recomputing from a rule would rewrite what last quarter's
	   burn-down said at the time.

	   The unique key is load-bearing rather than tidy: the opener runs from the
	   time-entry writer and from the record screen, and without it a race
	   creates two periods for one month and halves the burn-down. It ships in
	   the first definition because dbDelta cannot add a unique index to a table
	   that already holds duplicates.
	   ------------------------------------------------------------------- */
	$pcm_tables[] = "CREATE TABLE {$pcm_periods} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		project_id bigint(20) unsigned NOT NULL DEFAULT 0,
		period_start date DEFAULT NULL,
		period_end date DEFAULT NULL,
		allotted_hours decimal(10,2) NOT NULL DEFAULT 0.00,
		carried_in_hours decimal(10,2) NOT NULL DEFAULT 0.00,
		rollover_cap_hours decimal(10,2) DEFAULT NULL,
		is_closed tinyint(1) NOT NULL DEFAULT 0,
		closed_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		description longtext,
		owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
		last_modified_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		last_modified_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		is_deleted tinyint(1) NOT NULL DEFAULT 0,
		is_test tinyint(1) NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		UNIQUE KEY pcm_period_unique (project_id,period_start),
		KEY pcm_period_open (project_id,is_closed),
		KEY pcm_period_deleted (is_deleted),
		KEY pcm_period_test (is_test)
	) {$pcm_charset};";

	/* Resource allocation -------------------------------------------------
	   One row per person per project per week, not an arbitrary date range.
	   Over-allocation is a per-week question and the resourcing board buckets
	   by week, so weekly rows make both a grouped SUM rather than interval
	   arithmetic on every read. A date-range form still exists; it writes N
	   rows through one week-normalising helper.

	   The unique key stops a re-posted edit becoming a duplicate that reads as
	   over-allocation, which is the likeliest data bug in this table.
	   ------------------------------------------------------------------- */
	$pcm_tables[] = "CREATE TABLE {$pcm_allocations} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		project_id bigint(20) unsigned NOT NULL DEFAULT 0,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		week_start date DEFAULT NULL,
		planned_hours decimal(9,2) NOT NULL DEFAULT 0.00,
		role varchar(80) NOT NULL DEFAULT '',
		description longtext,
		owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
		last_modified_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		last_modified_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		is_deleted tinyint(1) NOT NULL DEFAULT 0,
		is_test tinyint(1) NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		UNIQUE KEY pcm_alloc_unique (project_id,user_id,week_start),
		KEY pcm_alloc_person_week (user_id,week_start),
		KEY pcm_alloc_project_week (project_id,week_start),
		KEY pcm_alloc_deleted (is_deleted),
		KEY pcm_alloc_test (is_test)
	) {$pcm_charset};";

	/* Status report -------------------------------------------------------
	   The rendered body is stored rather than regenerated. A status report is a
	   statement made to a client on a date; rebuilding it from today's numbers
	   would change what you told them. The figures are stored beside it for the
	   same reason.
	   ------------------------------------------------------------------- */
	$pcm_tables[] = "CREATE TABLE {$pcm_reports} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		project_id bigint(20) unsigned NOT NULL DEFAULT 0,
		template_id bigint(20) unsigned NOT NULL DEFAULT 0,
		subject varchar(255) NOT NULL DEFAULT '',
		body longtext,
		recipients text,
		period_start date DEFAULT NULL,
		period_end date DEFAULT NULL,
		health varchar(20) NOT NULL DEFAULT '',
		hours_used decimal(10,2) DEFAULT NULL,
		hours_allotted decimal(10,2) DEFAULT NULL,
		budget_used decimal(18,2) DEFAULT NULL,
		sent_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		last_error text,
		owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
		last_modified_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		last_modified_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		is_deleted tinyint(1) NOT NULL DEFAULT 0,
		is_test tinyint(1) NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY pcm_statrep_project (project_id,sent_date),
		KEY pcm_statrep_deleted (is_deleted),
		KEY pcm_statrep_test (is_test)
	) {$pcm_charset};";

	return $pcm_tables;
}
add_filter( 'pcm_crm_table_definitions', 'pcm_crm_pm_definitions', 10, 2 );

/**
 * Idempotent backfills, beside the core ones.
 *
 * Guarded the way the core backfills are — by "only rows that have none" — so
 * running again on every schema bump is safe and cheap.
 */
function pcm_crm_pm_backfill() {
	global $wpdb;

	$pcm_projects = pcm_crm_pm_projects_table();

	// The same statement core runs for opportunities: a project that predates
	// the column reads as zero days in stage forever without a starting point.
	// phpcs:ignore WordPress.DB.PreparedSQL -- table name is internal
	$wpdb->query( "UPDATE {$pcm_projects} SET stage_entered_date = created_date WHERE stage_entered_date = '0000-00-00 00:00:00'" );
}
add_action( 'pcm_crm_after_install', 'pcm_crm_pm_backfill' );
