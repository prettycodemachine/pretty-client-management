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
 * Returns the ids it created or matched. Deliberately creates no Opportunity:
 * an inquiry is not a deal, and a pipeline full of unqualified rows is worse
 * than an empty one.
 */
function pcm_crm_intake( array $pcm_fields ) {
	$pcm_account_id = pcm_crm_upsert_account( $pcm_fields['org'], array(
		'type'        => 'Prospect',
		'industry'    => '',
		'description' => '',
	) );

	$pcm_contact_id = pcm_crm_upsert_contact( array(
		'account_id'  => $pcm_account_id,
		'first_name'  => $pcm_fields['first'],
		'last_name'   => $pcm_fields['last'],
		'email'       => $pcm_fields['email'],
		'lead_source' => PCM_CRM_FORM_SOURCE,
	) );

	// Subject names the interest so the activity list reads as a log of what
	// people are asking for, not a column of identical "Website inquiry" rows.
	$pcm_activity_id = pcm_crm_log_activity( array(
		'subject'       => 'Web inquiry — ' . $pcm_fields['interest'],
		'activity_type' => 'Web Form',
		'status'        => 'Completed',
		'priority'      => 'Normal',
		'activity_date' => current_time( 'mysql' ),
		'who_id'        => $pcm_contact_id,
		'what_id'       => $pcm_account_id,
		'what_type'     => $pcm_account_id ? 'account' : '',
		'description'   => $pcm_fields['message'],
	) );

	return array(
		'account_id'  => $pcm_account_id,
		'contact_id'  => $pcm_contact_id,
		'activity_id' => $pcm_activity_id,
	);
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
