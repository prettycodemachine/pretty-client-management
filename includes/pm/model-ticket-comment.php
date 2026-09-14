<?php
/**
 * Help ticket comment.
 *
 * A growing detail list only ever viewed inside its parent ticket — nobody
 * browses a global list of comments, filters by body text, or exports them in
 * their own right — so unlike Help Ticket itself this is a plain model with no
 * pcm_crm_register_object() call. It is reachable only through the sub-routes
 * in pm-tickets-rest.php (staff) and portal/portal-rest.php (client), the same
 * shape enrollments has to contacts/sequences.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_ticket_comments() {
	static $pcm_model = null;

	if ( null === $pcm_model ) {
		$pcm_model = new PCM_CRM_Model(
			'help_ticket_comment',
			pcm_crm_pm_ticket_comments_table(),
			array_merge(
				array(
					'ticket_id'         => array( 'type' => 'id', 'sf' => 'PCM_Ticket_Id__c', 'label' => 'Ticket', 'lookup' => 'help_tickets' ),
					// No lookup: resolved by hand against the same thread's own
					// rows when the thread is rendered, not through expand().
					'parent_id'         => array( 'type' => 'id', 'label' => 'In Reply To' ),
					'body'              => array( 'type' => 'longtext', 'sf' => 'PCM_Body__c', 'label' => 'Comment' ),
					// Stamped in pcm_crm_pm_stamp_comment() from the acting
					// user's role — readonly so a posted value is dropped before
					// that filter even runs, belt-and-suspenders the way
					// is_closed is protected on Opportunity.
					'is_client_comment' => array( 'type' => 'bool', 'sf' => 'PCM_Is_Client__c', 'label' => 'From Client', 'readonly' => true ),
				),
				PCM_CRM_Model::system_fields()
			),
			array( 'body' ),
			array(
				'ticket' => array( 'column' => 'ticket_id', 'model' => 'pcm_crm_help_tickets', 'label' => 'Ticket' ),
			)
		);
	}

	return $pcm_model;
}

/**
 * Who wrote it: from the acting WP user's role, never from the request.
 *
 * created_by_id (a system field) already answers "which user" for both sides —
 * staff and a portal client are both real wp_users rows under this design —
 * so this stamp only decides how the thread is badged and who else is copied
 * on the notification, not identity itself.
 */
function pcm_crm_pm_stamp_comment( $pcm_row, $pcm_object ) {
	if ( 'help_ticket_comment' !== $pcm_object ) {
		return $pcm_row;
	}

	$pcm_row['is_client_comment'] = in_array( 'pcm_client', (array) wp_get_current_user()->roles, true ) ? 1 : 0;

	return $pcm_row;
}
add_filter( 'pcm_crm_before_insert', 'pcm_crm_pm_stamp_comment', 10, 2 );

/**
 * Refuse a comment that cannot be filed, or that would nest a second level of
 * reply — the portal and the CRM admin both render one level of indentation,
 * so a deeper thread would have nowhere to draw itself.
 */
function pcm_crm_pm_validate_comment( $pcm_error, $pcm_object, $pcm_row, $pcm_id ) {
	if ( 'help_ticket_comment' !== $pcm_object || is_wp_error( $pcm_error ) ) {
		return $pcm_error;
	}

	$pcm_existing = $pcm_id ? pcm_crm_ticket_comments()->get( $pcm_id ) : array();
	$pcm_merged   = array_merge( is_array( $pcm_existing ) ? $pcm_existing : array(), $pcm_row );

	if ( empty( $pcm_merged['ticket_id'] ) ) {
		return new WP_Error( 'pcm_crm_comment_no_ticket', __( 'A comment has to belong to a ticket.', 'pcm-crm' ), array( 'status' => 400 ) );
	}

	if ( '' === trim( wp_strip_all_tags( (string) $pcm_merged['body'] ) ) ) {
		return new WP_Error( 'pcm_crm_comment_empty', __( 'Say something before posting.', 'pcm-crm' ), array( 'status' => 400 ) );
	}

	if ( ! empty( $pcm_merged['parent_id'] ) ) {
		$pcm_parent = pcm_crm_ticket_comments()->get( $pcm_merged['parent_id'] );

		if ( ! $pcm_parent || (int) $pcm_parent['ticket_id'] !== (int) $pcm_merged['ticket_id'] ) {
			return new WP_Error( 'pcm_crm_comment_bad_parent', __( 'That reply does not belong to this ticket.', 'pcm-crm' ), array( 'status' => 400 ) );
		}

		if ( ! empty( $pcm_parent['parent_id'] ) ) {
			return new WP_Error( 'pcm_crm_comment_too_deep', __( 'A reply can only go one level deep — reply to the original comment instead.', 'pcm-crm' ), array( 'status' => 400 ) );
		}
	}

	return $pcm_error;
}
add_filter( 'pcm_crm_validate', 'pcm_crm_pm_validate_comment', 10, 4 );

/**
 * Email the ticket's Contact every time the thread grows, whichever side
 * wrote it — hung on the after-insert action, not a before_ filter, because
 * the notification needs the comment's final id and the row as actually
 * written (including the server-stamped is_client_comment), which only exist
 * once insert() has returned. Mirrors pcm_crm_track_stage()'s attachment to
 * the same action in model-history.php.
 */
function pcm_crm_pm_notify_ticket_comment( $pcm_object, $pcm_id, $pcm_row ) {
	if ( 'help_ticket_comment' !== $pcm_object ) {
		return;
	}

	pcm_crm_pm_send_ticket_comment_email( $pcm_id );
}
add_action( 'pcm_crm_inserted', 'pcm_crm_pm_notify_ticket_comment', 10, 3 );

/**
 * Send one comment's notification.
 *
 * Modeled on pcm_crm_send_contact_email() (includes/email-sequences.php) for
 * its mechanics — pcm_crm_email_wrapper()/pcm_crm_format_body(), the
 * wp_mail_content_type filter dance — but deliberately not reused wholesale:
 * that function's Do Not Contact refusal and marketing-token filling are the
 * wrong gate for a support reply a client is actively expecting after writing
 * on their own ticket.
 *
 * The recipient is always the ticket's Contact, whoever wrote the comment. A
 * client-authored comment is also copied to staff's notification address, so
 * it does not sit unseen; a staff-authored comment is not copied back to
 * staff.
 */
function pcm_crm_pm_send_ticket_comment_email( $pcm_comment_id ) {
	$pcm_comment = pcm_crm_ticket_comments()->get( $pcm_comment_id );

	if ( ! $pcm_comment ) {
		return false;
	}

	$pcm_ticket = pcm_crm_help_tickets()->get( $pcm_comment['ticket_id'] );

	if ( ! $pcm_ticket || ! $pcm_ticket['contact_id'] ) {
		return false;
	}

	$pcm_contact = pcm_crm_contacts()->get( $pcm_ticket['contact_id'] );

	if ( ! $pcm_contact || ! is_email( $pcm_contact['email'] ) ) {
		return false;
	}

	/* translators: %s: the ticket's subject */
	$pcm_subject = sprintf( __( 'Re: %s', 'pcm-crm' ), $pcm_ticket['subject'] );
	$pcm_wrapped = pcm_crm_email_wrapper( pcm_crm_format_body( wp_kses_post( $pcm_comment['body'] ) ) );
	$pcm_from    = pcm_crm_contact_recipient();

	add_filter( 'wp_mail_content_type', 'pcm_crm_html_content_type' );
	$pcm_sent = wp_mail( $pcm_contact['email'], $pcm_subject, $pcm_wrapped, array(
		'From: Pretty Code Machine <' . $pcm_from . '>',
		'Reply-To: ' . $pcm_from,
	) );
	remove_filter( 'wp_mail_content_type', 'pcm_crm_html_content_type' );

	if ( ! empty( $pcm_comment['is_client_comment'] ) ) {
		add_filter( 'wp_mail_content_type', 'pcm_crm_html_content_type' );
		/* translators: %s: the ticket's subject */
		wp_mail( $pcm_from, sprintf( __( '[Ticket] %s', 'pcm-crm' ), $pcm_ticket['subject'] ), $pcm_wrapped );
		remove_filter( 'wp_mail_content_type', 'pcm_crm_html_content_type' );
	}

	if ( $pcm_sent ) {
		pcm_crm_log_activity( array(
			'subject'       => sprintf( '%1$s — comment', $pcm_ticket['subject'] ),
			'activity_type' => 'Email',
			'status'        => 'Completed',
			'who_id'        => $pcm_ticket['contact_id'],
			'what_id'       => $pcm_ticket['project_id'],
			'what_type'     => 'project',
			'description'   => wp_strip_all_tags( (string) $pcm_comment['body'] ),
		) );
	}

	return (bool) $pcm_sent;
}

/**
 * A ticket's full comment thread, oldest first — the order a conversation
 * reads in.
 */
function pcm_crm_pm_ticket_thread( $pcm_ticket_id ) {
	return pcm_crm_ticket_comments()->find( array(
		'filters'  => array( 'ticket_id' => (int) $pcm_ticket_id ),
		'orderby'  => 'created_date',
		'order'    => 'ASC',
		'per_page' => 200,
	) );
}
