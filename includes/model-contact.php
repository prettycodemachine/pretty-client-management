<?php
/**
 * Contact — Salesforce's Contact.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_contacts() {
	static $pcm_model = null;

	if ( null === $pcm_model ) {
		$pcm_fields = array_merge(
			array(
				'account_id'          => array( 'type' => 'id',   'sf' => 'AccountId' ),
				'salutation'          => array( 'type' => 'text', 'sf' => 'Salutation' ),
				'first_name'          => array( 'type' => 'text', 'sf' => 'FirstName' ),
				'last_name'           => array( 'type' => 'text', 'sf' => 'LastName' ),
				'title'               => array( 'type' => 'text', 'sf' => 'Title' ),
				'email'               => array( 'type' => 'email', 'sf' => 'Email' ),
				'phone'               => array( 'type' => 'text', 'sf' => 'Phone' ),
				'mobile_phone'        => array( 'type' => 'text', 'sf' => 'MobilePhone' ),
				'mailing_street'      => array( 'type' => 'text', 'sf' => 'MailingStreet' ),
				'mailing_city'        => array( 'type' => 'text', 'sf' => 'MailingCity' ),
				'mailing_state'       => array( 'type' => 'text', 'sf' => 'MailingState' ),
				'mailing_postal_code' => array( 'type' => 'text', 'sf' => 'MailingPostalCode' ),
				'mailing_country'     => array( 'type' => 'text', 'sf' => 'MailingCountry' ),
				'lead_source'         => array( 'type' => 'text', 'sf' => 'LeadSource' ),
				'do_not_contact'      => array( 'type' => 'bool', 'sf' => 'DoNotCall' ),
				'description'         => array( 'type' => 'longtext', 'sf' => 'Description' ),
			),
			PCM_CRM_Model::system_fields()
		);

		$pcm_model = new PCM_CRM_Model(
			'contact',
			PCM_CRM_Schema::contacts(),
			$pcm_fields,
			array( 'first_name', 'last_name', 'email', 'title', 'phone', 'description' )
		);
	}

	return $pcm_model;
}

function pcm_crm_contact_name( array $pcm_contact ) {
	$pcm_name = trim( $pcm_contact['first_name'] . ' ' . $pcm_contact['last_name'] );

	return '' !== $pcm_name ? $pcm_name : $pcm_contact['email'];
}

function pcm_crm_find_contact_by_email( $pcm_email ) {
	global $wpdb;

	$pcm_email = sanitize_email( (string) $pcm_email );

	if ( ! $pcm_email ) {
		return 0;
	}

	$pcm_table = PCM_CRM_Schema::contacts();

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$pcm_table} WHERE email = %s AND is_deleted = 0 ORDER BY id ASC LIMIT 1",
		$pcm_email
	) );
}

/**
 * Find a Contact by email, or create one.
 *
 * On a repeat enquiry the existing record wins: only fields that are currently
 * empty are filled in. Someone who types their name differently the second
 * time, or leaves the organization blank, must not overwrite what is already
 * known about them — and account_id in particular is a link a human may have
 * corrected by hand.
 */
function pcm_crm_upsert_contact( array $pcm_input ) {
	$pcm_model = pcm_crm_contacts();
	$pcm_id    = pcm_crm_find_contact_by_email( isset( $pcm_input['email'] ) ? $pcm_input['email'] : '' );

	if ( ! $pcm_id ) {
		$pcm_new = $pcm_model->insert( $pcm_input );

		return is_wp_error( $pcm_new ) ? 0 : (int) $pcm_new;
	}

	$pcm_existing = $pcm_model->get( $pcm_id );
	$pcm_fill     = array();

	foreach ( $pcm_input as $pcm_key => $pcm_value ) {
		if ( ! $pcm_model->has_field( $pcm_key ) || '' === $pcm_value || null === $pcm_value ) {
			continue;
		}

		$pcm_current = isset( $pcm_existing[ $pcm_key ] ) ? $pcm_existing[ $pcm_key ] : '';

		if ( '' === $pcm_current || 0 === $pcm_current || '0' === $pcm_current || null === $pcm_current ) {
			$pcm_fill[ $pcm_key ] = $pcm_value;
		}
	}

	if ( $pcm_fill ) {
		$pcm_model->update( $pcm_id, $pcm_fill );
	}

	return $pcm_id;
}
