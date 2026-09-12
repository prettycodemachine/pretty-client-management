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
				'account_id'            => array( 'type' => 'id',   'sf' => 'PCM_Account_Id__c', 'label' => 'Account' ),
				'salutation'            => array( 'type' => 'text', 'sf' => 'Salutation', 'label' => 'Salutation' ),
				'first_name'            => array( 'type' => 'text', 'sf' => 'FirstName', 'label' => 'First Name' ),
				'last_name'             => array( 'type' => 'text', 'sf' => 'LastName', 'label' => 'Last Name' ),
				'title'                 => array( 'type' => 'text', 'sf' => 'Title', 'label' => 'Title' ),
				'email'                 => array( 'type' => 'email', 'sf' => 'Email', 'label' => 'Email' ),
				'phone'                 => array( 'type' => 'text', 'sf' => 'Phone', 'label' => 'Phone' ),
				'mobile_phone'          => array( 'type' => 'text', 'sf' => 'MobilePhone', 'label' => 'Mobile' ),
				'mailing_street'        => array( 'type' => 'text', 'sf' => 'MailingStreet', 'label' => 'Mailing Street' ),
				'mailing_city'          => array( 'type' => 'text', 'sf' => 'MailingCity', 'label' => 'Mailing City' ),
				'mailing_state'         => array( 'type' => 'text', 'sf' => 'MailingState', 'label' => 'Mailing State' ),
				'mailing_postal_code'   => array( 'type' => 'text', 'sf' => 'MailingPostalCode', 'label' => 'Mailing Postal Code' ),
				'mailing_country'       => array( 'type' => 'text', 'sf' => 'MailingCountry', 'label' => 'Mailing Country' ),
				'lead_source'           => array( 'type' => 'text', 'sf' => 'LeadSource', 'label' => 'Lead Source', 'options' => 'pcm_crm_lead_sources' ),
				// What they asked about on the contact form. Kept on the person
				// so an opportunity opened for them later can inherit it —
				// otherwise the answer only survives in an activity's subject.
				'service_interest'      => array( 'type' => 'text', 'sf' => 'Service_Interest__c', 'label' => 'Interested In', 'options' => 'pcm_crm_interest_labels' ),
				'do_not_contact'        => array( 'type' => 'bool', 'sf' => 'DoNotCall', 'label' => 'Do Not Contact' ),
				'do_not_contact_reason' => array( 'type' => 'text', 'sf' => 'DoNotCallReason__c', 'label' => 'Do Not Contact Reason' ),
				'description'           => array( 'type' => 'longtext', 'sf' => 'Description', 'label' => 'Notes' ),
			),
			PCM_CRM_Model::system_fields(),
			// Admin-defined fields are real columns, so they join the map as
			// equals — every filter, export and report downstream reads this
			// map and needs no idea that some of it was configured.
			pcm_crm_custom_field_map( 'contacts' )
		);

		$pcm_model = new PCM_CRM_Model(
			'contact',
			PCM_CRM_Schema::contacts(),
			$pcm_fields,
			array( 'first_name', 'last_name', 'email', 'title', 'phone', 'description' ),
			array(
				'account' => array( 'column' => 'account_id', 'model' => 'pcm_crm_accounts', 'label' => 'Account' ),
			)
		);
	}

	return $pcm_model;
}

/**
 * A contact's display name.
 *
 * Tolerates a partial row, because it is called on a merge context as well as
 * on a record read back from the database — and a context is assembled from
 * whatever the send path could reach, which is not always all three columns.
 */
function pcm_crm_contact_name( array $pcm_contact ) {
	$pcm_first = isset( $pcm_contact['first_name'] ) ? $pcm_contact['first_name'] : '';
	$pcm_last  = isset( $pcm_contact['last_name'] ) ? $pcm_contact['last_name'] : '';

	$pcm_name = trim( $pcm_first . ' ' . $pcm_last );

	if ( '' !== $pcm_name ) {
		return $pcm_name;
	}

	return isset( $pcm_contact['email'] ) ? $pcm_contact['email'] : '';
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
 * On a repeat inquiry the existing record wins: only fields that are currently
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

/**
 * A Do Not Contact flag is not valid without a reason.
 *
 * Enforced on the server rather than only in the form, because the flag is the
 * one field here with a consequence outside the CRM — someone eventually has
 * to decide whether it still applies, and a bare checkbox with no note is
 * unanswerable. Validated on the merged record, not on what was posted, so
 * ticking the box in isolation still has to account for an existing reason.
 */
function pcm_crm_validate_contact( $pcm_error, $pcm_object, $pcm_row, $pcm_id ) {
	if ( is_wp_error( $pcm_error ) || 'contact' !== $pcm_object ) {
		return $pcm_error;
	}

	$pcm_existing = $pcm_id ? pcm_crm_contacts()->get( $pcm_id ) : array();
	$pcm_merged   = array_merge( (array) $pcm_existing, $pcm_row );

	if ( empty( $pcm_merged['do_not_contact'] ) ) {
		return $pcm_error;
	}

	if ( '' === trim( (string) ( isset( $pcm_merged['do_not_contact_reason'] ) ? $pcm_merged['do_not_contact_reason'] : '' ) ) ) {
		return new WP_Error(
			'pcm_crm_dnc_reason_required',
			__( 'Give a reason for Do Not Contact — someone will need to know why before undoing it.', 'pcm-crm' ),
			array( 'status' => 400 )
		);
	}

	return $pcm_error;
}
add_filter( 'pcm_crm_validate', 'pcm_crm_validate_contact', 10, 4 );

/**
 * Clearing the flag clears the reason with it, so a record cannot keep an
 * explanation for a restriction it no longer carries.
 */
function pcm_crm_clear_dnc_reason( $pcm_row, $pcm_object ) {
	if ( 'contact' === $pcm_object && isset( $pcm_row['do_not_contact'] ) && ! $pcm_row['do_not_contact'] ) {
		$pcm_row['do_not_contact_reason'] = '';
	}

	return $pcm_row;
}
add_filter( 'pcm_crm_before_insert', 'pcm_crm_clear_dnc_reason', 10, 2 );
add_filter( 'pcm_crm_before_update', 'pcm_crm_clear_dnc_reason', 10, 2 );

pcm_crm_register_object( 'contacts', array(
	'model'         => 'pcm_crm_contacts',
	'label'         => 'Contact',
	'plural'        => 'Contacts',
	'reportable'    => true,
	'customisable'  => true,
	'exportable'    => true,
	'sf'            => 'Contact',
	'group_options' => array( 'lead_source', 'account_id', 'title', 'owner_id' ),
	'related'       => 'fetch',
) );
