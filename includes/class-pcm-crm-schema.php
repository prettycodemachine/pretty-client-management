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
	const VERSION = '1.3.0';

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

		update_option( self::OPTION, self::VERSION );
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
			PRIMARY KEY  (id),
			KEY pcm_account_name_key (name_key),
			KEY pcm_account_owner (owner_id),
			KEY pcm_account_deleted (is_deleted),
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
			PRIMARY KEY  (id),
			KEY pcm_contact_email (email),
			KEY pcm_contact_account (account_id),
			KEY pcm_contact_owner (owner_id),
			KEY pcm_contact_deleted (is_deleted),
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
			PRIMARY KEY  (id),
			KEY pcm_opp_account (account_id),
			KEY pcm_opp_contact (primary_contact_id),
			KEY pcm_opp_stage (stage_name),
			KEY pcm_opp_close (close_date),
			KEY pcm_opp_owner (owner_id),
			KEY pcm_opp_deleted (is_deleted),
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
			PRIMARY KEY  (id),
			KEY pcm_act_who (who_id),
			KEY pcm_act_what (what_type,what_id),
			KEY pcm_act_date (activity_date),
			KEY pcm_act_due (due_date),
			KEY pcm_act_owner (owner_id),
			KEY pcm_act_deleted (is_deleted),
			KEY pcm_act_sf (sf_id)
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

		return $pcm_tables;
	}
}
