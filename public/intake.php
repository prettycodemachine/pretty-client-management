<?php
/**
 * Turn a contact form submission into CRM records.
 *
 * Ordering matters here. The raw payload is written first, so an inquiry
 * leaves evidence even if everything after it fails; then the upsert; then the
 * mail. Nothing in the upsert is allowed to stop the notification going out —
 * a CRM problem must never look to the visitor like a failed inquiry.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Record the raw submission before anything else touches it.
 */
function pcm_crm_log_submission( array $pcm_fields, $pcm_is_spam = false ) {
	$pcm_id = pcm_crm_submissions()->insert( array(
		'payload'    => wp_json_encode( $pcm_fields ),
		'ip_address' => pcm_crm_client_ip(),
		'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '',
		'is_spam'    => $pcm_is_spam ? 1 : 0,
	) );

	return is_wp_error( $pcm_id ) ? 0 : (int) $pcm_id;
}

/**
 * The submitter's IP, for the audit trail only.
 *
 * REMOTE_ADDR alone: the forwarded headers are trivially spoofed, and this is
 * never used to make a decision — only to tell two submissions apart later.
 */
function pcm_crm_client_ip() {
	return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
}

/**
 * Upsert the Account, the Contact and the Activity for one submission.
 *
 * Driven entirely by each field's `map`, so adding a field in the builder and
 * pointing it at a CRM column is all it takes for the value to land there.
 *
 * Returns the ids it created or matched. Deliberately creates no Opportunity:
 * an inquiry is not a deal, and a pipeline full of unqualified rows is worse
 * than an empty one.
 */
function pcm_crm_intake( array $pcm_values ) {
	$pcm_mapped = pcm_crm_map_submission( $pcm_values );

	$pcm_account_id = 0;

	if ( ! empty( $pcm_mapped['account']['name'] ) ) {
		$pcm_account_id = pcm_crm_upsert_account(
			$pcm_mapped['account']['name'],
			array_merge( array( 'type' => 'Prospect' ), array_diff_key( $pcm_mapped['account'], array( 'name' => '' ) ) )
		);
	}

	$pcm_contact_id = 0;

	// An email is what a contact is matched on; without one there is no way to
	// tell a returning visitor from a new one, so the submission is recorded
	// and left unlinked rather than creating a duplicate person every time.
	if ( ! empty( $pcm_mapped['contact']['email'] ) ) {
		$pcm_contact_id = pcm_crm_upsert_contact( array_merge(
			$pcm_mapped['contact'],
			array(
				'account_id'  => $pcm_account_id,
				'lead_source' => PCM_CRM_FORM_SOURCE,
			)
		) );
	}

	// Subject names the interest where there is one, so the activity list
	// reads as a log of what people are asking for rather than a column of
	// identical rows.
	$pcm_interest = isset( $pcm_mapped['contact']['service_interest'] ) ? $pcm_mapped['contact']['service_interest'] : '';
	$pcm_subject  = $pcm_interest ? 'Web inquiry — ' . $pcm_interest : 'Web inquiry';

	$pcm_activity_id = pcm_crm_log_activity( array(
		'subject'       => $pcm_subject,
		'activity_type' => 'Web Form',
		'status'        => 'Completed',
		'priority'      => 'Normal',
		'activity_date' => current_time( 'mysql' ),
		'who_id'        => $pcm_contact_id,
		'what_id'       => $pcm_account_id,
		'what_type'     => $pcm_account_id ? 'account' : '',
		'description'   => isset( $pcm_mapped['activity']['description'] )
			? $pcm_mapped['activity']['description']
			: pcm_crm_submission_summary( $pcm_values ),
	) );

	return array(
		'account_id'  => $pcm_account_id,
		'contact_id'  => $pcm_contact_id,
		'activity_id' => $pcm_activity_id,
	);
}

/**
 * Sort a submission's values into the records they belong to.
 */
function pcm_crm_map_submission( array $pcm_values ) {
	$pcm_mapped = array( 'contact' => array(), 'account' => array(), 'activity' => array() );

	foreach ( pcm_crm_form_fields() as $pcm_field ) {
		if ( empty( $pcm_field['map'] ) ) {
			continue;
		}

		$pcm_value = isset( $pcm_values[ $pcm_field['key'] ] ) ? $pcm_values[ $pcm_field['key'] ] : '';

		if ( '' === $pcm_value ) {
			continue;
		}

		list( $pcm_object, $pcm_column ) = explode( '.', $pcm_field['map'], 2 );

		if ( isset( $pcm_mapped[ $pcm_object ] ) ) {
			$pcm_mapped[ $pcm_object ][ $pcm_column ] = $pcm_value;
		}
	}

	return $pcm_mapped;
}

/**
 * Everything submitted, as readable lines.
 *
 * Used as the activity body when no field is mapped to it, so a form built
 * without a message field still leaves a record of what was actually said
 * rather than an empty activity.
 */
function pcm_crm_submission_summary( array $pcm_values ) {
	$pcm_lines = array();

	foreach ( pcm_crm_form_fields() as $pcm_field ) {
		$pcm_value = isset( $pcm_values[ $pcm_field['key'] ] ) ? $pcm_values[ $pcm_field['key'] ] : '';

		if ( '' !== $pcm_value ) {
			$pcm_lines[] = $pcm_field['label'] . ': ' . $pcm_value;
		}
	}

	return implode( "\n", $pcm_lines );
}

/**
 * Run the intake without letting it break the submission.
 *
 * Any failure is written to the submission row and to the error log, and then
 * swallowed: the inquiry email is what the visitor is being told succeeded.
 */
function pcm_crm_safe_intake( array $pcm_fields, $pcm_submission_id ) {
	$pcm_result = array( 'account_id' => 0, 'contact_id' => 0, 'activity_id' => 0 );

	try {
		$pcm_result = pcm_crm_intake( $pcm_fields );

		if ( $pcm_submission_id ) {
			pcm_crm_submissions()->update( $pcm_submission_id, $pcm_result );
		}
	} catch ( Exception $pcm_e ) {
		pcm_crm_record_intake_error( $pcm_submission_id, $pcm_e->getMessage() );
	} catch ( Error $pcm_e ) {
		// A fatal in the upsert path would otherwise take the whole request
		// down and lose the email as well as the record.
		pcm_crm_record_intake_error( $pcm_submission_id, $pcm_e->getMessage() );
	}

	return $pcm_result;
}

function pcm_crm_record_intake_error( $pcm_submission_id, $pcm_message ) {
	if ( $pcm_submission_id ) {
		pcm_crm_submissions()->update( $pcm_submission_id, array( 'intake_error' => $pcm_message ) );
	}

	error_log( 'PCM CRM intake failed: ' . $pcm_message );
}
