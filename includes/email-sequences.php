<?php
/**
 * Email templates, merge variables, sending, and short outreach sequences.
 *
 * On stopping when someone replies: this plugin cannot read a mailbox, and
 * pretending otherwise would be the worst kind of feature. What it can see is
 * anything that reaches the CRM — a form submission from that person, a call
 * or meeting logged against them, a note someone wrote after a reply landed —
 * and any of those stops the sequence. A one-click "They replied" is there for
 * the rest, and pcm_crm_stop_sequences_for_contact() is the seam an inbound
 * mail integration would hook into later.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------------------------------------------------------------------------
   Models
   --------------------------------------------------------------------------- */

function pcm_crm_templates() {
	static $pcm_model = null;

	if ( null === $pcm_model ) {
		$pcm_model = new PCM_CRM_Model(
			'template',
			PCM_CRM_Schema::templates(),
			array_merge(
				array(
					'name'      => array( 'type' => 'text', 'label' => 'Name' ),
					'subject'   => array( 'type' => 'text', 'label' => 'Subject' ),
					'body'      => array( 'type' => 'raw', 'label' => 'Body' ),
					'is_active' => array( 'type' => 'bool', 'label' => 'Active' ),
				),
				PCM_CRM_Model::system_fields()
			),
			array( 'name', 'subject', 'body' )
		);
	}

	return $pcm_model;
}

function pcm_crm_sequences() {
	static $pcm_model = null;

	if ( null === $pcm_model ) {
		$pcm_model = new PCM_CRM_Model(
			'sequence',
			PCM_CRM_Schema::sequences(),
			array_merge(
				array(
					'name'        => array( 'type' => 'text', 'label' => 'Name' ),
					'description' => array( 'type' => 'text', 'label' => 'Description' ),
					'steps'       => array( 'type' => 'raw', 'internal' => true ),
					'is_active'   => array( 'type' => 'bool', 'label' => 'Active' ),
				),
				PCM_CRM_Model::system_fields()
			),
			array( 'name', 'description' )
		);
	}

	return $pcm_model;
}

function pcm_crm_enrollments() {
	static $pcm_model = null;

	if ( null === $pcm_model ) {
		$pcm_model = new PCM_CRM_Model(
			'enrollment',
			PCM_CRM_Schema::enrollments(),
			array_merge(
				array(
					'sequence_id'    => array( 'type' => 'id', 'label' => 'Sequence' ),
					'contact_id'     => array( 'type' => 'id', 'label' => 'Contact' ),
					'opportunity_id' => array( 'type' => 'id', 'label' => 'Opportunity' ),
					'status'         => array( 'type' => 'text', 'label' => 'Status', 'options' => 'pcm_crm_enrollment_statuses' ),
					'current_step'   => array( 'type' => 'int', 'label' => 'Step' ),
					'next_send_at'   => array( 'type' => 'datetime', 'label' => 'Next Send' ),
					'last_sent_at'   => array( 'type' => 'datetime', 'label' => 'Last Sent', 'readonly' => true ),
					'stopped_reason' => array( 'type' => 'text', 'label' => 'Stopped Because', 'readonly' => true ),
					'stopped_date'   => array( 'type' => 'datetime', 'label' => 'Stopped', 'readonly' => true ),
					'last_error'     => array( 'type' => 'text', 'label' => 'Last Error', 'readonly' => true ),
				),
				PCM_CRM_Model::system_fields()
			),
			array( 'stopped_reason' )
		);
	}

	return $pcm_model;
}

function pcm_crm_enrollment_statuses() {
	return array( 'active', 'completed', 'stopped' );
}

/**
 * A template needs a name and a subject; a sequence needs a name.
 *
 * Refused on save rather than allowed through as a blank row. The screen that
 * produced one of those had rendered no fields at all, and a record made of
 * nothing is a worse symptom than an error message — it looks like it worked.
 */
function pcm_crm_validate_outreach( $pcm_error, $pcm_object, $pcm_row, $pcm_id ) {
	if ( is_wp_error( $pcm_error ) || ! in_array( $pcm_object, array( 'template', 'sequence' ), true ) ) {
		return $pcm_error;
	}

	$pcm_model    = 'template' === $pcm_object ? pcm_crm_templates() : pcm_crm_sequences();
	$pcm_existing = $pcm_id ? $pcm_model->get( $pcm_id ) : array();
	$pcm_merged   = array_merge( (array) $pcm_existing, $pcm_row );

	if ( '' === trim( (string) ( isset( $pcm_merged['name'] ) ? $pcm_merged['name'] : '' ) ) ) {
		return new WP_Error( 'pcm_crm_name_required', __( 'Give it a name.', 'pcm-crm' ), array( 'status' => 400 ) );
	}

	if ( 'template' === $pcm_object && '' === trim( (string) ( isset( $pcm_merged['subject'] ) ? $pcm_merged['subject'] : '' ) ) ) {
		return new WP_Error( 'pcm_crm_subject_required', __( 'A template needs a subject line.', 'pcm-crm' ), array( 'status' => 400 ) );
	}

	return $pcm_error;
}
add_filter( 'pcm_crm_validate', 'pcm_crm_validate_outreach', 10, 4 );

/* ---------------------------------------------------------------------------
   Merge variables
   --------------------------------------------------------------------------- */

/**
 * Every variable a template can use, grouped by the record it comes from.
 *
 * Built from the models' field maps, so a custom field is available the moment
 * it is created and nothing here has to be told about it.
 *
 * Contact and account only. A contact has many opportunities, and nothing in
 * either send path — the button on the record, or a sequence step — picks one,
 * so an opportunity variable had no way to resolve to anything. Offering it
 * meant a template could look correct and go out with a hole in it.
 */
function pcm_crm_email_variables() {
	$pcm_groups = array();

	foreach ( array( 'contact' => 'contacts', 'account' => 'accounts' ) as $pcm_prefix => $pcm_object ) {
		$pcm_model = PCM_CRM_REST::model( $pcm_object );

		if ( ! $pcm_model ) {
			continue;
		}

		$pcm_fields = array();

		foreach ( $pcm_model->fields() as $pcm_name => $pcm_def ) {
			// Ids and flags make poor sentences; a template wants the things a
			// person would actually write into one.
			if ( empty( $pcm_def['label'] ) || ! empty( $pcm_def['internal'] ) || 'id' === $pcm_def['type'] || 'bool' === $pcm_def['type'] ) {
				continue;
			}

			$pcm_fields[] = array(
				'token' => '{{' . $pcm_prefix . '.' . $pcm_name . '}}',
				'label' => $pcm_def['label'],
			);
		}

		$pcm_groups[] = array(
			'prefix' => $pcm_prefix,
			'label'  => ucfirst( $pcm_prefix ),
			'fields' => $pcm_fields,
		);
	}

	// Two composed ones, because writing {{contact.first_name}} twice to get a
	// full name is the sort of thing people give up on.
	$pcm_groups[] = array(
		'prefix' => 'other',
		'label'  => __( 'Other', 'pcm-crm' ),
		'fields' => array(
			array( 'token' => '{{contact.full_name}}', 'label' => __( 'Contact full name', 'pcm-crm' ) ),
			array( 'token' => '{{sender.name}}', 'label' => __( 'Your name', 'pcm-crm' ) ),
		),
	);

	return $pcm_groups;
}

/**
 * Substitute the variables for one contact.
 *
 * A token with nothing behind it resolves to an empty string rather than being
 * left in place: "Hi {{contact.first_name}}," reaching someone's inbox is
 * worse than "Hi ,".
 */
function pcm_crm_fill_variables( $pcm_text, array $pcm_context ) {
	$pcm_values = array();

	foreach ( array( 'contact', 'account' ) as $pcm_prefix ) {
		$pcm_record = isset( $pcm_context[ $pcm_prefix ] ) && is_array( $pcm_context[ $pcm_prefix ] ) ? $pcm_context[ $pcm_prefix ] : array();

		foreach ( $pcm_record as $pcm_field => $pcm_value ) {
			if ( is_scalar( $pcm_value ) ) {
				$pcm_values[ '{{' . $pcm_prefix . '.' . $pcm_field . '}}' ] = (string) $pcm_value;
			}
		}
	}

	if ( ! empty( $pcm_context['contact'] ) ) {
		$pcm_values['{{contact.full_name}}'] = pcm_crm_contact_name( $pcm_context['contact'] );
	}

	$pcm_values['{{sender.name}}'] = isset( $pcm_context['sender'] )
		? $pcm_context['sender']
		: pcm_crm_user_name( get_current_user_id() );

	$pcm_filled = strtr( $pcm_text, array_map( 'esc_html', $pcm_values ) );

	// Anything still unresolved was a token for a field this record has no
	// value for — or an {{opportunity.*}} token from a template written while
	// those were offered. Blanking it is the least bad outcome either way.
	return preg_replace( '/\{\{[a-z_]+\.[a-z_]+\}\}/i', '', $pcm_filled );
}

/**
 * The records a template is filled from.
 *
 * The person and their organization. An opportunity id may still be passed to
 * a send, but only to hang the logged activity off the right record — it is
 * not a source of merge values, because there is no one opportunity a contact
 * belongs to.
 */
function pcm_crm_email_context( $pcm_contact_id ) {
	$pcm_contact = pcm_crm_contacts()->get( $pcm_contact_id );

	if ( ! $pcm_contact ) {
		return null;
	}

	$pcm_account = $pcm_contact['account_id'] ? pcm_crm_accounts()->get( $pcm_contact['account_id'] ) : array();

	return array(
		'contact' => $pcm_contact,
		'account' => $pcm_account ? $pcm_account : array(),
	);
}

/* ---------------------------------------------------------------------------
   Sending
   --------------------------------------------------------------------------- */

/**
 * True while a sequence is sending its own mail.
 *
 * The activity a send writes would otherwise look exactly like a human logging
 * an email against the contact, and stop the sequence that just sent it.
 */
function pcm_crm_sending_sequence( $pcm_set = null ) {
	static $pcm_sending = false;

	if ( null !== $pcm_set ) {
		$pcm_sending = (bool) $pcm_set;
	}

	return $pcm_sending;
}

/**
 * Send one email to a contact and log it.
 *
 * Refuses a contact marked Do Not Contact. That flag is the one piece of this
 * CRM with a consequence outside it, and a sequence quietly mailing someone
 * who asked not to be contacted is precisely the failure it exists to prevent.
 */
function pcm_crm_send_contact_email( $pcm_contact_id, $pcm_subject, $pcm_body, array $pcm_args = array() ) {
	$pcm_args = wp_parse_args( $pcm_args, array(
		'opportunity_id' => 0,
		'enrollment_id'  => 0,
		'sequence_name'  => '',
		'sender'         => '',
	) );

	$pcm_context = pcm_crm_email_context( $pcm_contact_id );

	if ( ! $pcm_context ) {
		return new WP_Error( 'pcm_crm_no_contact', __( 'That contact no longer exists.', 'pcm-crm' ) );
	}

	$pcm_contact = $pcm_context['contact'];

	if ( ! empty( $pcm_contact['do_not_contact'] ) ) {
		return new WP_Error(
			'pcm_crm_do_not_contact',
			sprintf(
				/* translators: %s: the recorded reason */
				__( 'This contact is marked Do Not Contact — %s', 'pcm-crm' ),
				$pcm_contact['do_not_contact_reason'] ? $pcm_contact['do_not_contact_reason'] : __( 'no reason recorded', 'pcm-crm' )
			)
		);
	}

	if ( ! is_email( $pcm_contact['email'] ) ) {
		return new WP_Error( 'pcm_crm_no_email', __( 'That contact has no email address.', 'pcm-crm' ) );
	}

	if ( $pcm_args['sender'] ) {
		$pcm_context['sender'] = $pcm_args['sender'];
	}

	$pcm_filled_subject = pcm_crm_fill_variables( $pcm_subject, $pcm_context );
	$pcm_filled_body    = pcm_crm_fill_variables( $pcm_body, $pcm_context );

	$pcm_from    = pcm_crm_contact_recipient();
	$pcm_headers = array(
		'From: Pretty Code Machine <' . $pcm_from . '>',
		'Reply-To: ' . $pcm_from,
	);

	add_filter( 'wp_mail_content_type', 'pcm_crm_html_content_type' );
	$pcm_sent = wp_mail(
		$pcm_contact['email'],
		$pcm_filled_subject,
		pcm_crm_email_wrapper( pcm_crm_format_body( $pcm_filled_body ) ),
		$pcm_headers
	);
	remove_filter( 'wp_mail_content_type', 'pcm_crm_html_content_type' );

	if ( ! $pcm_sent ) {
		return new WP_Error( 'pcm_crm_mail_failed', __( 'The server would not send the email.', 'pcm-crm' ) );
	}

	// Logged whether or not it came from a sequence: an email that left the
	// building and is not on the record is the thing a CRM exists to prevent.
	$pcm_activity_id = pcm_crm_log_activity( array(
		'subject'       => $pcm_args['sequence_name']
			? sprintf( '%s — %s', $pcm_args['sequence_name'], $pcm_filled_subject )
			: $pcm_filled_subject,
		'activity_type' => 'Email',
		'status'        => 'Completed',
		'priority'      => 'Normal',
		'activity_date' => current_time( 'mysql' ),
		'who_id'        => $pcm_contact_id,
		'what_id'       => $pcm_args['opportunity_id'] ? $pcm_args['opportunity_id'] : $pcm_contact['account_id'],
		'what_type'     => $pcm_args['opportunity_id'] ? 'opportunity' : ( $pcm_contact['account_id'] ? 'account' : '' ),
		'description'   => wp_strip_all_tags( $pcm_filled_body ),
	) );

	do_action( 'pcm_crm_email_sent', $pcm_contact_id, $pcm_filled_subject, $pcm_args );

	return array( 'sent' => true, 'activity_id' => $pcm_activity_id, 'subject' => $pcm_filled_subject );
}

/* ---------------------------------------------------------------------------
   Sequences
   --------------------------------------------------------------------------- */

/**
 * A sequence's steps, decoded and cleaned.
 */
function pcm_crm_sequence_steps( array $pcm_sequence ) {
	$pcm_steps = json_decode( (string) $pcm_sequence['steps'], true );

	if ( ! is_array( $pcm_steps ) ) {
		return array();
	}

	$pcm_out = array();

	foreach ( $pcm_steps as $pcm_step ) {
		if ( empty( $pcm_step['template_id'] ) ) {
			continue;
		}

		$pcm_out[] = array(
			'template_id' => (int) $pcm_step['template_id'],
			// Days after the previous step. The first step's delay is days
			// after enrollment, and zero means "now", which is what someone
			// enrolling a contact almost always wants from step one.
			'delay_days'  => max( 0, (int) ( isset( $pcm_step['delay_days'] ) ? $pcm_step['delay_days'] : 0 ) ),
		);
	}

	return $pcm_out;
}

/**
 * Put a contact into a sequence.
 */
function pcm_crm_enroll_contact( $pcm_contact_id, $pcm_sequence_id, $pcm_opportunity_id = 0 ) {
	$pcm_contact  = pcm_crm_contacts()->get( $pcm_contact_id );
	$pcm_sequence = pcm_crm_sequences()->get( $pcm_sequence_id );

	if ( ! $pcm_contact || ! $pcm_sequence ) {
		return new WP_Error( 'pcm_crm_not_found', __( 'That contact or sequence no longer exists.', 'pcm-crm' ) );
	}

	if ( ! empty( $pcm_contact['do_not_contact'] ) ) {
		return new WP_Error( 'pcm_crm_do_not_contact', __( 'This contact is marked Do Not Contact and cannot be enrolled.', 'pcm-crm' ) );
	}

	if ( ! is_email( $pcm_contact['email'] ) ) {
		return new WP_Error( 'pcm_crm_no_email', __( 'This contact has no email address.', 'pcm-crm' ) );
	}

	$pcm_steps = pcm_crm_sequence_steps( $pcm_sequence );

	if ( ! $pcm_steps ) {
		return new WP_Error( 'pcm_crm_empty_sequence', __( 'That sequence has no steps yet.', 'pcm-crm' ) );
	}

	// Enrolling someone twice in the same sequence would send them everything
	// twice, offset by however long the second enrollment came later.
	if ( pcm_crm_active_enrollment( $pcm_contact_id, $pcm_sequence_id ) ) {
		return new WP_Error( 'pcm_crm_already_enrolled', __( 'This contact is already in that sequence.', 'pcm-crm' ) );
	}

	$pcm_id = pcm_crm_enrollments()->insert( array(
		'sequence_id'    => $pcm_sequence_id,
		'contact_id'     => $pcm_contact_id,
		'opportunity_id' => $pcm_opportunity_id,
		'status'         => 'active',
		'current_step'   => 0,
		'next_send_at'   => gmdate( 'Y-m-d H:i:s', strtotime( '+' . $pcm_steps[0]['delay_days'] . ' days', current_time( 'timestamp' ) ) ),
	) );

	return is_wp_error( $pcm_id ) ? $pcm_id : (int) $pcm_id;
}

function pcm_crm_active_enrollment( $pcm_contact_id, $pcm_sequence_id = 0 ) {
	$pcm_filters = array( 'contact_id' => (int) $pcm_contact_id, 'status' => 'active' );

	if ( $pcm_sequence_id ) {
		$pcm_filters['sequence_id'] = (int) $pcm_sequence_id;
	}

	$pcm_rows = pcm_crm_enrollments()->find( array( 'filters' => $pcm_filters, 'per_page' => 1 ) );

	return $pcm_rows ? $pcm_rows[0] : null;
}

/**
 * Stop every running sequence for a contact.
 *
 * The seam a reply reaches this system through, whatever notices it: a person
 * pressing the button, an activity logged against the contact, or one day an
 * inbound mail webhook.
 */
function pcm_crm_stop_sequences_for_contact( $pcm_contact_id, $pcm_reason ) {
	$pcm_active = pcm_crm_enrollments()->find( array(
		'filters'  => array( 'contact_id' => (int) $pcm_contact_id, 'status' => 'active' ),
		'per_page' => 50,
	) );

	foreach ( $pcm_active as $pcm_enrollment ) {
		pcm_crm_stop_enrollment( $pcm_enrollment['id'], $pcm_reason );
	}

	return count( $pcm_active );
}

function pcm_crm_stop_enrollment( $pcm_enrollment_id, $pcm_reason ) {
	global $wpdb;

	// Written directly because status, stopped_reason and stopped_date are a
	// single fact, and two of them are readonly to the model on purpose.
	$wpdb->update(
		PCM_CRM_Schema::enrollments(),
		array(
			'status'         => 'stopped',
			'stopped_reason' => $pcm_reason,
			'stopped_date'   => current_time( 'mysql' ),
			'next_send_at'   => null,
		),
		array( 'id' => absint( $pcm_enrollment_id ) ),
		array( '%s', '%s', '%s', '%s' ),
		array( '%d' )
	);

	do_action( 'pcm_crm_enrollment_stopped', absint( $pcm_enrollment_id ), $pcm_reason );

	return true;
}

/**
 * Treat anything logged against a contact as a reply.
 *
 * The CRM cannot read a mailbox, but it does see a form submission, a logged
 * call, a meeting, or a note someone wrote after a reply landed. Any of those
 * means the conversation is live, and continuing to send scheduled outreach
 * into a live conversation is the thing that makes sequences feel like spam.
 */
function pcm_crm_watch_for_replies( $pcm_object, $pcm_id, $pcm_row ) {
	if ( 'activity' !== $pcm_object || pcm_crm_sending_sequence() ) {
		return;
	}

	$pcm_activity = pcm_crm_activities()->get( $pcm_id );

	if ( ! $pcm_activity || ! $pcm_activity['who_id'] ) {
		return;
	}

	$pcm_signals = apply_filters(
		'pcm_crm_reply_activity_types',
		array( 'Email', 'Call', 'Meeting', 'Note', 'Web Form' )
	);

	if ( ! in_array( $pcm_activity['activity_type'], $pcm_signals, true ) ) {
		return;
	}

	pcm_crm_stop_sequences_for_contact(
		$pcm_activity['who_id'],
		sprintf(
			/* translators: %s: activity type, e.g. Call */
			__( 'A %s was logged against this contact', 'pcm-crm' ),
			strtolower( $pcm_activity['activity_type'] )
		)
	);
}
add_action( 'pcm_crm_inserted', 'pcm_crm_watch_for_replies', 10, 3 );

/**
 * Send whatever is due.
 *
 * Hung off the same cron as the scheduled reports: one timer, two jobs, and
 * both want the same quarter-hourly resolution.
 */
function pcm_crm_run_sequences() {
	$pcm_due = pcm_crm_enrollments()->find( array(
		'filters'  => array(
			'status'       => 'active',
			'next_send_at' => array( 'max' => current_time( 'mysql' ) ),
		),
		'orderby'  => 'next_send_at',
		'order'    => 'ASC',
		'per_page' => 50,
	) );

	foreach ( $pcm_due as $pcm_enrollment ) {
		pcm_crm_advance_enrollment( $pcm_enrollment );
	}
}
add_action( 'pcm_crm_send_scheduled', 'pcm_crm_run_sequences' );

/**
 * Send the enrollment's current step and queue the next.
 */
function pcm_crm_advance_enrollment( array $pcm_enrollment ) {
	global $wpdb;

	$pcm_sequence = pcm_crm_sequences()->get( $pcm_enrollment['sequence_id'] );

	if ( ! $pcm_sequence || empty( $pcm_sequence['is_active'] ) ) {
		return pcm_crm_stop_enrollment( $pcm_enrollment['id'], __( 'The sequence was switched off', 'pcm-crm' ) );
	}

	$pcm_steps = pcm_crm_sequence_steps( $pcm_sequence );
	$pcm_index = (int) $pcm_enrollment['current_step'];

	if ( ! isset( $pcm_steps[ $pcm_index ] ) ) {
		return pcm_crm_complete_enrollment( $pcm_enrollment['id'] );
	}

	$pcm_template = pcm_crm_templates()->get( $pcm_steps[ $pcm_index ]['template_id'] );

	if ( ! $pcm_template ) {
		return pcm_crm_stop_enrollment( $pcm_enrollment['id'], __( 'A template in this sequence no longer exists', 'pcm-crm' ) );
	}

	pcm_crm_sending_sequence( true );

	$pcm_result = pcm_crm_send_contact_email(
		$pcm_enrollment['contact_id'],
		$pcm_template['subject'],
		$pcm_template['body'],
		array(
			'opportunity_id' => $pcm_enrollment['opportunity_id'],
			'enrollment_id'  => $pcm_enrollment['id'],
			'sequence_name'  => $pcm_sequence['name'],
			'sender'         => pcm_crm_user_name( $pcm_enrollment['owner_id'] ),
		)
	);

	pcm_crm_sending_sequence( false );

	if ( is_wp_error( $pcm_result ) ) {
		// A refusal is permanent — do-not-contact, no address, a dead template
		// — so the enrollment stops rather than retrying every quarter hour
		// until someone notices.
		return pcm_crm_stop_enrollment( $pcm_enrollment['id'], $pcm_result->get_error_message() );
	}

	$pcm_next = $pcm_index + 1;

	if ( ! isset( $pcm_steps[ $pcm_next ] ) ) {
		$wpdb->update(
			PCM_CRM_Schema::enrollments(),
			array( 'current_step' => $pcm_next, 'last_sent_at' => current_time( 'mysql' ), 'next_send_at' => null, 'status' => 'completed' ),
			array( 'id' => (int) $pcm_enrollment['id'] ),
			array( '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return true;
	}

	$wpdb->update(
		PCM_CRM_Schema::enrollments(),
		array(
			'current_step' => $pcm_next,
			'last_sent_at' => current_time( 'mysql' ),
			'next_send_at' => gmdate( 'Y-m-d H:i:s', strtotime( '+' . $pcm_steps[ $pcm_next ]['delay_days'] . ' days', current_time( 'timestamp' ) ) ),
		),
		array( 'id' => (int) $pcm_enrollment['id'] ),
		array( '%d', '%s', '%s' ),
		array( '%d' )
	);

	return true;
}

function pcm_crm_complete_enrollment( $pcm_enrollment_id ) {
	global $wpdb;

	$wpdb->update(
		PCM_CRM_Schema::enrollments(),
		array( 'status' => 'completed', 'next_send_at' => null ),
		array( 'id' => absint( $pcm_enrollment_id ) ),
		array( '%s', '%s' ),
		array( '%d' )
	);

	return true;
}
