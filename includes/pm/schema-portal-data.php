<?php
/**
 * Help Tickets, their comment threads, and a project's document library.
 *
 * These three tables are staff-facing PM data — useful from the CRM admin
 * whether or not any client ever logs in — so they live in the `pm` module's
 * always-loaded storage, kept in their own file rather than growing the
 * already-large schema-pm.php further. The Client Portal module (which
 * requires `pm`) only ever *reads and writes through* these tables; it owns
 * no storage of its own.
 *
 * Same house rules as every other PM table: two spaces after PRIMARY KEY, one
 * field per line, every KEY named explicitly, no real foreign keys, varchar(190)
 * as the ceiling for anything indexed, and the full audit spine plus is_test.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_pm_ticket_table()          { return pcm_crm_pm_table( 'help_tickets' ); }
function pcm_crm_pm_ticket_comments_table() { return pcm_crm_pm_table( 'help_ticket_comments' ); }
function pcm_crm_pm_documents_table()       { return pcm_crm_pm_table( 'project_documents' ); }

function pcm_crm_pm_portal_definitions( array $pcm_tables, $pcm_charset ) {
	$pcm_tickets  = pcm_crm_pm_ticket_table();
	$pcm_comments = pcm_crm_pm_ticket_comments_table();
	$pcm_docs     = pcm_crm_pm_documents_table();

	/* Help ticket -----------------------------------------------------------
	   account_id always follows project_id — see pcm_crm_pm_apply_ticket() in
	   model-help-ticket.php — so it is never independently posted, but it is
	   still a real, indexed column: a report or a related list on the Account
	   should not have to join through Project to find its tickets.
	   ---------------------------------------------------------------------- */
	$pcm_tables[] = "CREATE TABLE {$pcm_tickets} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		sf_id varchar(18) DEFAULT NULL,
		project_id bigint(20) unsigned NOT NULL DEFAULT 0,
		account_id bigint(20) unsigned NOT NULL DEFAULT 0,
		contact_id bigint(20) unsigned NOT NULL DEFAULT 0,
		subject varchar(255) NOT NULL DEFAULT '',
		description longtext,
		status varchar(40) NOT NULL DEFAULT 'To Do',
		owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
		last_modified_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		last_modified_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		is_deleted tinyint(1) NOT NULL DEFAULT 0,
		is_test tinyint(1) NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY pcm_ticket_project (project_id,status),
		KEY pcm_ticket_account (account_id),
		KEY pcm_ticket_contact (contact_id),
		KEY pcm_ticket_deleted (is_deleted),
		KEY pcm_ticket_test (is_test),
		KEY pcm_ticket_sf (sf_id)
	) {$pcm_charset};";

	/* Help ticket comment -----------------------------------------------------
	   parent_id follows the plugin's usual convention for an optional FK — 0
	   rather than NULL — with one level of reply enforced in the validator, not
	   here. created_by_id (from system_fields) already answers "who wrote this"
	   for both a staff reply and a client reply: a client is a real wp_users
	   row under the Client Portal module, so no separate author-type/id pair is
	   needed. is_client_comment is a display flag, stamped server-side, never
	   client-settable — see pcm_crm_pm_stamp_comment().
	   ------------------------------------------------------------------------ */
	$pcm_tables[] = "CREATE TABLE {$pcm_comments} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		ticket_id bigint(20) unsigned NOT NULL DEFAULT 0,
		parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
		body longtext,
		is_client_comment tinyint(1) NOT NULL DEFAULT 0,
		owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
		last_modified_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		last_modified_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		is_deleted tinyint(1) NOT NULL DEFAULT 0,
		is_test tinyint(1) NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY pcm_ticket_comment_ticket (ticket_id,id),
		KEY pcm_ticket_comment_parent (parent_id),
		KEY pcm_ticket_comment_deleted (is_deleted),
		KEY pcm_ticket_comment_test (is_test)
	) {$pcm_charset};";

	/* Project document ----------------------------------------------------
	   One row per file, not the single-option-array convention the email
	   autoresponder's attachment list uses — a document library is an
	   open-ended, individually-labelled, individually-removable related list,
	   which is exactly what a table row is for. attachment_id points at the
	   WordPress Media Library (wp_posts), so it carries no 'lookup' — the
	   browser already has the filename from the wp.media picker that uploaded
	   it, and expand() has no CRM model to resolve it against.
	   ------------------------------------------------------------------- */
	$pcm_tables[] = "CREATE TABLE {$pcm_docs} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		project_id bigint(20) unsigned NOT NULL DEFAULT 0,
		attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
		label varchar(255) NOT NULL DEFAULT '',
		owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
		last_modified_by_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		last_modified_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		is_deleted tinyint(1) NOT NULL DEFAULT 0,
		is_test tinyint(1) NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY pcm_doc_project (project_id),
		KEY pcm_doc_deleted (is_deleted),
		KEY pcm_doc_test (is_test)
	) {$pcm_charset};";

	return $pcm_tables;
}
add_filter( 'pcm_crm_table_definitions', 'pcm_crm_pm_portal_definitions', 10, 2 );
