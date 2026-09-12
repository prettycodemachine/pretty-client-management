<?php
/**
 * Table definitions.
 *
 * The shape follows Salesforce's standard objects — same field names, same
 * relationships, same soft-delete convention — so a future migration is a CSV
 * load rather than a remodelling exercise. That is also why this is custom
 * tables and not custom post types: fields spread across postmeta cannot be
 * filtered, grouped or summed at any useful speed.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class PCM_CRM_Schema {

	/**
	 * Bump on any column change. install() runs whenever the stored version
	 * differs, so an rsync deploy (which never fires the activation hook)
	 * still picks the change up on the next page load.
	 */
	const VERSION = '1.7.0';

	const OPTION = 'pcm_crm_db_version';

	public static function installed_version() {
		return (string) get_option( self::OPTION, '' );
	}

	public static function table( $pcm_name ) {
		global $wpdb;

		return $wpdb->prefix . 'pcm_crm_' . $pcm_name;
	}

	public static function accounts()      { return self::table( 'accounts' ); }
	public static function contacts()      { return self::table( 'contacts' ); }
	public static function opportunities() { return self::table( 'opportunities' ); }
	public static function activities()    { return self::table( 'activities' ); }
	public static function submissions()   { return self::table( 'form_submissions' ); }
	public static function history()       { return self::table( 'opportunity_history' ); }
	public static function schedules()     { return self::table( 'schedules' ); }
	public static function templates()     { return self::table( 'email_templates' ); }
	public static function sequences()     { return self::table( 'sequences' ); }
	public static function enrollments()   { return self::table( 'enrollments' ); }

	/**
	 * Create or alter every table.
	 *
	 * Idempotent by design — dbDelta only issues the differences, and nothing
	 * here drops or truncates, so re-activating the plugin is safe and loses
	 * no data.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$pcm_charset = $wpdb->get_charset_collate();

		foreach ( self::definitions( $pcm_charset ) as $pcm_sql ) {
			dbDelta( $pcm_sql );
		}

		self::backfill_history();
		self::backfill_test_flags();

		// Modules hang their own idempotent backfills here, beside the core
		// ones, rather than editing this class. Fires after every table exists
		// and before the version is stamped, so a listener that throws leaves
		// the version behind and the whole install runs again next load.
		do_action( 'pcm_crm_after_install' );

		update_option( self::OPTION, self::VERSION );
	}

	/**
	 * Give existing opportunities a starting history row.
	 *
	 * Without this every deal that predates the feature reads as zero days in
	 * stage forever, since there is nothing to measure from. Two set-based
	 * statements rather than a loop: this runs inside a page load, and a row
	 * at a time over a real pipeline would be felt.
	 *
	 * Only opportunities with no history at all are touched, so it is safe to
	 * run again — which it will be, on every schema bump.
	 */
	private static function backfill_history() {
		global $wpdb;

		$pcm_opps    = self::opportunities();
		$pcm_history = self::history();

		// phpcs:ignore WordPress.DB.PreparedSQL -- table names are internal
		$wpdb->query(
			"INSERT INTO {$pcm_history}
				(opportunity_id, stage_name, previous_stage, amount, entered_date, exited_date, is_closed, is_won, created_by_id, created_date)
			 SELECT o.id, o.stage_name, '', o.amount, o.created_date, NULL, o.is_closed, o.is_won, o.created_by_id, o.created_date
			 FROM {$pcm_opps} o
			 WHERE NOT EXISTS (SELECT 1 FROM {$pcm_history} h WHERE h.opportunity_id = o.id)"
		);

		// The deal entered its current stage when it was created, as far as
		// anything now knowable goes.
		// phpcs:ignore WordPress.DB.PreparedSQL -- table name is internal
		$wpdb->query( "UPDATE {$pcm_opps} SET stage_entered_date = created_date WHERE stage_entered_date = '0000-00-00 00:00:00'" );

		// A deal already closed has no recorded closing moment either; its
		// forecast close date is the best available stand-in.
		// phpcs:ignore WordPress.DB.PreparedSQL -- table name is internal
		$wpdb->query( "UPDATE {$pcm_opps} SET closed_date = close_date WHERE is_closed = 1 AND closed_date = '0000-00-00 00:00:00' AND close_date IS NOT NULL" );
	}

	/**
	 * Flag whatever the old CLI seeder made.
	 *
	 * That set was tracked in an option rather than on the rows. Without this
	 * it would be indistinguishable from real data the moment the option is
	 * lost, and the new Delete button would leave it behind.
	 */
	private static function backfill_test_flags() {
		global $wpdb;

		$pcm_stored = get_option( 'pcm_crm_seed_ids', array() );

		if ( ! is_array( $pcm_stored ) || ! $pcm_stored ) {
			return;
		}

		$pcm_tables = array(
			'accounts'      => self::accounts(),
			'contacts'      => self::contacts(),
			'opportunities' => self::opportunities(),
			'activities'    => self::activities(),
		);

		foreach ( $pcm_tables as $pcm_key => $pcm_table ) {
			$pcm_ids = isset( $pcm_stored[ $pcm_key ] ) ? array_filter( array_map( 'absint', $pcm_stored[ $pcm_key ] ) ) : array();

			if ( ! $pcm_ids ) {
				continue;
			}

			$pcm_in = implode( ',', $pcm_ids );

			// phpcs:ignore WordPress.DB.PreparedSQL -- ids are cast to int above
			$wpdb->query( "UPDATE {$pcm_table} SET is_test = 1 WHERE id IN ({$pcm_in})" );
		}

		// History hangs off the opportunities rather than being tracked itself.
		$pcm_opp_ids = isset( $pcm_stored['opportunities'] ) ? array_filter( array_map( 'absint', $pcm_stored['opportunities'] ) ) : array();

		if ( $pcm_opp_ids ) {
			$pcm_in      = implode( ',', $pcm_opp_ids );
			$pcm_history = self::history();

			// phpcs:ignore WordPress.DB.PreparedSQL -- ids are cast to int above
			$wpdb->query( "UPDATE {$pcm_history} SET is_test = 1 WHERE opportunity_id IN ({$pcm_in})" );
		}
	}

	/**
	 * The CREATE TABLE statements.
	 *
	 * dbDelta is fussy: two spaces after PRIMARY KEY, one field per line, and
	 * KEY names must be given explicitly or it re-adds the index on every run.
	 *
	 * Relationships are plain unsigned columns rather than real foreign keys.
	 * Shared hosting still hands out MyISAM, and a deleted Account must leave
	 * its Contacts readable-but-unlinked rather than take them with it.
	 */
	private static function definitions( $pcm_charset ) {
		$pcm_accounts      = self::accounts();
		$pcm_contacts      = self::contacts();
		$pcm_opportunities = self::opportunities();
		$pcm_activities    = self::activities();
		$pcm_submissions   = self::submissions();
		$pcm_history       = self::history();
		$pcm_schedules     = self::schedules();
		$pcm_templates     = self::templates();
		$pcm_sequences     = self::sequences();
		$pcm_enrollments   = self::enrollments();

		$pcm_tables = array();

		/* Account ---------------------------------------------------------- */
		$pcm_tables[] = "CREATE TABLE {$pcm_accounts} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			sf_id varchar(18) DEFAULT NULL,
			name varchar(255) NOT NULL DEFAULT '',
			name_key varchar(255) NOT NULL DEFAULT '',
			type varchar(80) NOT NULL DEFAULT '',
			industry varchar(80) NOT NULL DEFAULT '',
			website varchar(255) NOT NULL DEFAULT '',
			phone varchar(40) NOT NULL DEFAULT '',
			billing_street varchar(255) NOT NULL DEFAULT '',
			billing_city varchar(120) NOT NULL DEFAULT '',
			billing_state varchar(80) NOT NULL DEFAULT '',
			billing_postal_code varchar(20) NOT NULL DEFAULT '',
			billing_country varchar(80) NOT NULL DEFAULT '',
			annual_revenue decimal(18,2) DEFAULT NULL,
			number_of_employees int(11) DEFAULT NULL,
			description longtext,
			owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
			last_modified_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			last_modified_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			is_deleted tinyint(1) NOT NULL DEFAULT 0,
			is_test tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY pcm_account_name_key (name_key),
			KEY pcm_account_owner (owner_id),
			KEY pcm_account_deleted (is_deleted),
			KEY pcm_account_test (is_test),
			KEY pcm_account_sf (sf_id)
		) {$pcm_charset};";

		/* Contact ---------------------------------------------------------- */
		$pcm_tables[] = "CREATE TABLE {$pcm_contacts} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			sf_id varchar(18) DEFAULT NULL,
			account_id bigint(20) unsigned NOT NULL DEFAULT 0,
			salutation varchar(20) NOT NULL DEFAULT '',
			first_name varchar(120) NOT NULL DEFAULT '',
			last_name varchar(120) NOT NULL DEFAULT '',
			title varchar(160) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			phone varchar(40) NOT NULL DEFAULT '',
			mobile_phone varchar(40) NOT NULL DEFAULT '',
			mailing_street varchar(255) NOT NULL DEFAULT '',
			mailing_city varchar(120) NOT NULL DEFAULT '',
			mailing_state varchar(80) NOT NULL DEFAULT '',
			mailing_postal_code varchar(20) NOT NULL DEFAULT '',
			mailing_country varchar(80) NOT NULL DEFAULT '',
			lead_source varchar(80) NOT NULL DEFAULT '',
			service_interest varchar(120) NOT NULL DEFAULT '',
			do_not_contact tinyint(1) NOT NULL DEFAULT 0,
			do_not_contact_reason varchar(255) NOT NULL DEFAULT '',
			description longtext,
			owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
			last_modified_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			last_modified_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			is_deleted tinyint(1) NOT NULL DEFAULT 0,
			is_test tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY pcm_contact_email (email),
			KEY pcm_contact_account (account_id),
			KEY pcm_contact_owner (owner_id),
			KEY pcm_contact_deleted (is_deleted),
			KEY pcm_contact_test (is_test),
			KEY pcm_contact_sf (sf_id)
		) {$pcm_charset};";

		/* Opportunity ------------------------------------------------------ */
		$pcm_tables[] = "CREATE TABLE {$pcm_opportunities} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			sf_id varchar(18) DEFAULT NULL,
			account_id bigint(20) unsigned NOT NULL DEFAULT 0,
			primary_contact_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(255) NOT NULL DEFAULT '',
			stage_name varchar(80) NOT NULL DEFAULT '',
			amount decimal(18,2) DEFAULT NULL,
			probability tinyint(3) unsigned NOT NULL DEFAULT 0,
			close_date date DEFAULT NULL,
			type varchar(80) NOT NULL DEFAULT '',
			lead_source varchar(80) NOT NULL DEFAULT '',
			next_step varchar(255) NOT NULL DEFAULT '',
			stage_entered_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			closed_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			service_interest varchar(120) NOT NULL DEFAULT '',
			closed_lost_reason varchar(255) NOT NULL DEFAULT '',
			forecast_category varchar(40) NOT NULL DEFAULT '',
			is_closed tinyint(1) NOT NULL DEFAULT 0,
			is_won tinyint(1) NOT NULL DEFAULT 0,
			description longtext,
			owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
			last_modified_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			last_modified_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			is_deleted tinyint(1) NOT NULL DEFAULT 0,
			is_test tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY pcm_opp_account (account_id),
			KEY pcm_opp_contact (primary_contact_id),
			KEY pcm_opp_stage (stage_name),
			KEY pcm_opp_close (close_date),
			KEY pcm_opp_entered (stage_entered_date),
			KEY pcm_opp_closed (closed_date),
			KEY pcm_opp_owner (owner_id),
			KEY pcm_opp_deleted (is_deleted),
			KEY pcm_opp_test (is_test),
			KEY pcm_opp_sf (sf_id)
		) {$pcm_charset};";

		/* Activity --------------------------------------------------------- */
		// Salesforce splits these into Task and Event; both share the Who/What
		// polymorphic pair, and PCM has no use for the split, so they are one
		// table with an activity_type. what_type carries the object name the
		// way WhatId's prefix does, since a plain id cannot say which table it
		// points at.
		$pcm_tables[] = "CREATE TABLE {$pcm_activities} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			sf_id varchar(18) DEFAULT NULL,
			subject varchar(255) NOT NULL DEFAULT '',
			activity_type varchar(40) NOT NULL DEFAULT '',
			status varchar(40) NOT NULL DEFAULT '',
			priority varchar(20) NOT NULL DEFAULT '',
			activity_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			due_date date DEFAULT NULL,
			who_id bigint(20) unsigned NOT NULL DEFAULT 0,
			what_id bigint(20) unsigned NOT NULL DEFAULT 0,
			what_type varchar(20) NOT NULL DEFAULT '',
			description longtext,
			is_completed tinyint(1) NOT NULL DEFAULT 0,
			owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
			last_modified_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			last_modified_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			is_deleted tinyint(1) NOT NULL DEFAULT 0,
			is_test tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY pcm_act_who (who_id),
			KEY pcm_act_what (what_type,what_id),
			KEY pcm_act_date (activity_date),
			KEY pcm_act_due (due_date),
			KEY pcm_act_owner (owner_id),
			KEY pcm_act_deleted (is_deleted),
			KEY pcm_act_test (is_test),
			KEY pcm_act_sf (sf_id)
		) {$pcm_charset};";

		/* Opportunity stage history ------------------------------------------ */
		// Salesforce's OpportunityHistory: one row per stage a deal entered.
		// It keeps exited_date and days_in_stage as well, which Salesforce
		// derives at report time — storing them means a stalled-deal query is
		// a comparison rather than a scan of every row that came before.
		//
		// The open row is the one with exited_date NULL, and it is also the
		// record of what stage the deal is in as far as history is concerned,
		// which is how a transition is detected without stashing state.
		$pcm_tables[] = "CREATE TABLE {$pcm_history} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			sf_id varchar(18) DEFAULT NULL,
			opportunity_id bigint(20) unsigned NOT NULL DEFAULT 0,
			stage_name varchar(80) NOT NULL DEFAULT '',
			previous_stage varchar(80) NOT NULL DEFAULT '',
			amount decimal(18,2) DEFAULT NULL,
			entered_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			exited_date datetime DEFAULT NULL,
			days_in_stage int(11) DEFAULT NULL,
			is_closed tinyint(1) NOT NULL DEFAULT 0,
			is_won tinyint(1) NOT NULL DEFAULT 0,
			created_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			is_test tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY pcm_hist_opp (opportunity_id),
			KEY pcm_hist_stage (stage_name),
			KEY pcm_hist_entered (entered_date),
			KEY pcm_hist_open (opportunity_id,exited_date),
			KEY pcm_hist_test (is_test)
		) {$pcm_charset};";

		/* Scheduled deliveries ----------------------------------------------- */
		// A saved view plus who receives it and when. The filters are stored as
		// the same JSON the UI puts in the URL, so a schedule and the screen it
		// was created from cannot drift apart.
		$pcm_tables[] = "CREATE TABLE {$pcm_schedules} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL DEFAULT '',
			report_type varchar(20) NOT NULL DEFAULT 'dashboard',
			object varchar(40) NOT NULL DEFAULT '',
			group_by varchar(60) NOT NULL DEFAULT '',
			filters longtext,
			recipients text,
			frequency varchar(20) NOT NULL DEFAULT 'weekly',
			send_time varchar(5) NOT NULL DEFAULT '08:00',
			day_of_week tinyint(1) NOT NULL DEFAULT 1,
			day_of_month tinyint(2) NOT NULL DEFAULT 1,
			attach_csv tinyint(1) NOT NULL DEFAULT 1,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			last_sent datetime DEFAULT NULL,
			last_error text,
			owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			last_modified_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
			last_modified_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			is_deleted tinyint(1) NOT NULL DEFAULT 0,
			is_test tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY pcm_sched_active (is_active,is_deleted),
			KEY pcm_sched_sent (last_sent)
		) {$pcm_charset};";

		/* Email templates ----------------------------------------------------- */
		$pcm_tables[] = "CREATE TABLE {$pcm_templates} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL DEFAULT '',
			subject varchar(255) NOT NULL DEFAULT '',
			body longtext,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			last_modified_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
			last_modified_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			is_deleted tinyint(1) NOT NULL DEFAULT 0,
			is_test tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY pcm_tpl_active (is_active,is_deleted)
		) {$pcm_charset};";

		/* Sequences ------------------------------------------------------------ */
		// Steps live as JSON on the sequence rather than in their own table:
		// they are only ever read as a whole set, and a short sequence edited
		// as one form is a poor fit for rows that have to be diffed on save.
		$pcm_tables[] = "CREATE TABLE {$pcm_sequences} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL DEFAULT '',
			description text,
			steps longtext,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			last_modified_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
			last_modified_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			is_deleted tinyint(1) NOT NULL DEFAULT 0,
			is_test tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY pcm_seq_active (is_active,is_deleted)
		) {$pcm_charset};";

		/* Enrollments ---------------------------------------------------------- */
		// One person's progress through one sequence. next_send_at is indexed
		// because the cron's only question is "what is due", and that must not
		// become a scan of every enrollment ever made.
		$pcm_tables[] = "CREATE TABLE {$pcm_enrollments} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			sequence_id bigint(20) unsigned NOT NULL DEFAULT 0,
			contact_id bigint(20) unsigned NOT NULL DEFAULT 0,
			opportunity_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'active',
			current_step int(11) NOT NULL DEFAULT 0,
			next_send_at datetime DEFAULT NULL,
			last_sent_at datetime DEFAULT NULL,
			stopped_reason varchar(255) NOT NULL DEFAULT '',
			stopped_date datetime DEFAULT NULL,
			last_error text,
			owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			last_modified_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
			last_modified_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			is_deleted tinyint(1) NOT NULL DEFAULT 0,
			is_test tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY pcm_enr_due (status,next_send_at),
			KEY pcm_enr_contact (contact_id,status),
			KEY pcm_enr_sequence (sequence_id)
		) {$pcm_charset};";

		/* Form submissions -------------------------------------------------- */
		// A local audit trail, never migrated. It is written before the intake
		// upsert runs, so a broken upsert still leaves evidence of the inquiry.
		$pcm_tables[] = "CREATE TABLE {$pcm_submissions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			payload longtext,
			ip_address varchar(45) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			is_spam tinyint(1) NOT NULL DEFAULT 0,
			contact_id bigint(20) unsigned NOT NULL DEFAULT 0,
			account_id bigint(20) unsigned NOT NULL DEFAULT 0,
			activity_id bigint(20) unsigned NOT NULL DEFAULT 0,
			notification_sent tinyint(1) NOT NULL DEFAULT 0,
			autoresponder_sent tinyint(1) NOT NULL DEFAULT 0,
			intake_error text,
			created_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY pcm_sub_contact (contact_id),
			KEY pcm_sub_created (created_date)
		) {$pcm_charset};";

		// Modules append their tables here. They are collected whether or not
		// the module is switched on, which is deliberate: install() is keyed on
		// one version option, so a table withheld at install time would never
		// be created by a later switch-on that found the versions already
		// equal. The switch governs behaviour, not storage.
		return apply_filters( 'pcm_crm_table_definitions', $pcm_tables, $pcm_charset );
	}
}
