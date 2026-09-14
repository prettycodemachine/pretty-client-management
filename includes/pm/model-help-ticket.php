<?php
/**
 * Help Ticket.
 *
 * A support request against a project, raised by a client (from the portal)
 * or logged on their behalf by staff. account_id always follows project_id —
 * a ticket's account is always its project's account, never independently
 * chosen — but contact_id is a real lookup: a project can have several client
 * contacts via project_roles, and which one raised this specific ticket is a
 * fact the project alone cannot answer.
 *
 * Status is a plain four-value picklist rather than a stage set with legality
 * rules — a client moving a ticket backwards ("actually this isn't fixed") is
 * an ordinary action, not an exception, so there is no transition graph here.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_help_tickets() {
	static $pcm_model = null;

	if ( null === $pcm_model ) {
		$pcm_model = new PCM_CRM_Model(
			'help_ticket',
			pcm_crm_pm_ticket_table(),
			array_merge(
				array(
					'project_id'  => array( 'type' => 'id', 'sf' => 'PCM_Project_Id__c', 'label' => 'Project', 'lookup' => 'projects' ),
					// Server-derived from project_id on every save — see
					// pcm_crm_pm_apply_ticket() — so it is readonly here and
					// never independently posted.
					'account_id'  => array( 'type' => 'id', 'sf' => 'PCM_Account_Id__c', 'label' => 'Account', 'lookup' => 'accounts', 'readonly' => true ),
					'contact_id'  => array( 'type' => 'id', 'sf' => 'PCM_Contact_Id__c', 'label' => 'Contact', 'lookup' => 'contacts', 'lookup_filter' => array( 'account_id' => 'account_id' ) ),
					'subject'     => array( 'type' => 'text', 'sf' => 'PCM_Subject__c', 'label' => 'Subject' ),
					'description' => array( 'type' => 'longtext', 'sf' => 'PCM_Description__c', 'label' => 'Description' ),
					'status'      => array( 'type' => 'text', 'sf' => 'PCM_Status__c', 'label' => 'Status', 'options' => 'pcm_crm_pm_ticket_statuses' ),
				),
				PCM_CRM_Model::system_fields()
			),
			array( 'subject', 'description' ),
			array(
				'project' => array( 'column' => 'project_id', 'model' => 'pcm_crm_projects', 'label' => 'Project' ),
				'account' => array( 'column' => 'account_id', 'model' => 'pcm_crm_accounts', 'label' => 'Account' ),
				'contact' => array( 'column' => 'contact_id', 'model' => 'pcm_crm_contacts', 'label' => 'Contact' ),
			)
		);
	}

	return $pcm_model;
}

/**
 * The four statuses a ticket can hold, in the order they read.
 *
 * A plain array, not a struct like the project stage sets: nothing downstream
 * needs to know which one is "closed" the way a project's stages do, so there
 * is nothing a struct would carry beyond the name itself.
 */
function pcm_crm_pm_ticket_statuses() {
	return apply_filters( 'pcm_crm_pm_ticket_statuses', array( 'To Do', 'In Progress', 'Client Testing', 'Client Approved' ) );
}

/**
 * Is this contact a client on this project?
 *
 * The one fact both the ticket validator and the portal's permission layer
 * need — a ticket can never be filed against a project its contact has no
 * client role on, and a client can never be shown a ticket that shouldn't
 * exist under this check in the first place.
 */
function pcm_crm_pm_contact_is_client_on( $pcm_contact_id, $pcm_project_id ) {
	if ( ! $pcm_contact_id || ! $pcm_project_id ) {
		return false;
	}

	$pcm_roles = pcm_crm_project_roles()->find( array(
		'filters'  => array(
			'project_id' => (int) $pcm_project_id,
			'contact_id' => (int) $pcm_contact_id,
			'party_type' => 'client',
		),
		'per_page' => 1,
	) );

	$pcm_items = isset( $pcm_roles['items'] ) ? $pcm_roles['items'] : $pcm_roles;

	return (bool) $pcm_items;
}

/**
 * Keep account_id in step with project_id, and default a new ticket's status.
 *
 * Read from the merged row's existing project, not just what was posted, the
 * same way pcm_crm_pm_apply_stage() falls back to the stored type — an update
 * that only changes the subject must not blank the account out from under it.
 */
function pcm_crm_pm_apply_ticket( $pcm_row, $pcm_object, $pcm_id = 0 ) {
	if ( 'help_ticket' !== $pcm_object ) {
		return $pcm_row;
	}

	if ( isset( $pcm_row['project_id'] ) ) {
		$pcm_project = pcm_crm_projects()->get( $pcm_row['project_id'] );
		$pcm_row['account_id'] = $pcm_project ? (int) $pcm_project['account_id'] : 0;
	} elseif ( ! $pcm_id ) {
		$pcm_row['account_id'] = 0;
	}

	if ( ! $pcm_id && empty( $pcm_row['status'] ) ) {
		$pcm_row['status'] = 'To Do';
	}

	return $pcm_row;
}
add_filter( 'pcm_crm_before_insert', 'pcm_crm_pm_apply_ticket', 10, 2 );
add_filter( 'pcm_crm_before_update', 'pcm_crm_pm_apply_ticket', 10, 3 );

/**
 * Refuse a ticket that cannot be counted or is not coherent.
 */
function pcm_crm_pm_validate_ticket( $pcm_error, $pcm_object, $pcm_row, $pcm_id ) {
	if ( 'help_ticket' !== $pcm_object || is_wp_error( $pcm_error ) ) {
		return $pcm_error;
	}

	$pcm_existing = $pcm_id ? pcm_crm_help_tickets()->get( $pcm_id ) : array();
	$pcm_merged   = array_merge( is_array( $pcm_existing ) ? $pcm_existing : array(), $pcm_row );

	if ( '' === trim( (string) $pcm_merged['subject'] ) ) {
		return new WP_Error( 'pcm_crm_ticket_subject_required', __( 'A ticket needs a subject.', 'pcm-crm' ), array( 'status' => 400 ) );
	}

	if ( ! in_array( (string) $pcm_merged['status'], pcm_crm_pm_ticket_statuses(), true ) ) {
		return new WP_Error( 'pcm_crm_ticket_bad_status', __( 'That is not a status a ticket can hold.', 'pcm-crm' ), array( 'status' => 400 ) );
	}

	if ( empty( $pcm_merged['contact_id'] ) ) {
		return new WP_Error( 'pcm_crm_ticket_no_contact', __( 'A ticket needs the contact who raised it.', 'pcm-crm' ), array( 'status' => 400 ) );
	}

	// The mistake worth catching here: it would save cleanly, and then be
	// invisible to the portal permission check, which reads project_roles as
	// the source of truth for "whose project is this."
	if ( ! pcm_crm_pm_contact_is_client_on( (int) $pcm_merged['contact_id'], (int) $pcm_merged['project_id'] ) ) {
		return new WP_Error( 'pcm_crm_ticket_contact_not_on_project', __( 'That contact is not on this project as a client.', 'pcm-crm' ), array( 'status' => 400 ) );
	}

	return $pcm_error;
}
add_filter( 'pcm_crm_validate', 'pcm_crm_pm_validate_ticket', 10, 4 );

/**
 * Log a ticket's creation and every status change on the project's own
 * Activities timeline — reusing what_type 'project' rather than inventing one
 * nothing else reads, so a ticket touch shows up in the same related list the
 * project's calls and emails already do.
 */
function pcm_crm_pm_log_ticket_activity( $pcm_object, $pcm_id, $pcm_row ) {
	if ( 'help_ticket' !== $pcm_object ) {
		return;
	}

	$pcm_ticket = pcm_crm_help_tickets()->get( $pcm_id );

	if ( ! $pcm_ticket || ! $pcm_ticket['project_id'] ) {
		return;
	}

	pcm_crm_log_activity( array(
		'subject'       => sprintf( '%1$s (%2$s)', $pcm_ticket['subject'], $pcm_ticket['status'] ),
		'activity_type' => 'Task',
		'status'        => 'Completed',
		'who_id'        => $pcm_ticket['contact_id'],
		'what_id'       => $pcm_ticket['project_id'],
		'what_type'     => 'project',
		'description'   => wp_strip_all_tags( (string) $pcm_ticket['description'] ),
	) );
}
add_action( 'pcm_crm_inserted', 'pcm_crm_pm_log_ticket_activity', 10, 3 );

/**
 * Only a status change is worth a second Activity row on update — every other
 * field edit (a typo fixed in the subject) is not the kind of touch the
 * timeline exists to record.
 */
function pcm_crm_pm_log_ticket_status_change( $pcm_object, $pcm_id, $pcm_row ) {
	if ( 'help_ticket' !== $pcm_object || ! array_key_exists( 'status', $pcm_row ) ) {
		return;
	}

	pcm_crm_pm_log_ticket_activity( $pcm_object, $pcm_id, $pcm_row );
}
add_action( 'pcm_crm_updated', 'pcm_crm_pm_log_ticket_status_change', 10, 3 );
