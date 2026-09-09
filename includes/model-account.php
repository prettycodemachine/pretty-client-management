<?php
/**
 * Account — Salesforce's Account.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_accounts() {
	static $pcm_model = null;

	if ( null === $pcm_model ) {
		$pcm_fields = array_merge(
			array(
				'name'                => array( 'type' => 'text', 'sf' => 'Name' ),
				// Normalised copy of name, maintained by pcm_crm_account_key().
				// The form intake matches on it, so "The Smith Trust" and
				// "the smith trust." do not become two accounts.
				'name_key'            => array( 'type' => 'text', 'readonly' => true ),
				'type'                => array( 'type' => 'text', 'sf' => 'Type' ),
				'industry'            => array( 'type' => 'text', 'sf' => 'Industry' ),
				'website'             => array( 'type' => 'url',  'sf' => 'Website' ),
				'phone'               => array( 'type' => 'text', 'sf' => 'Phone' ),
				'billing_street'      => array( 'type' => 'text', 'sf' => 'BillingStreet' ),
				'billing_city'        => array( 'type' => 'text', 'sf' => 'BillingCity' ),
				'billing_state'       => array( 'type' => 'text', 'sf' => 'BillingState' ),
				'billing_postal_code' => array( 'type' => 'text', 'sf' => 'BillingPostalCode' ),
				'billing_country'     => array( 'type' => 'text', 'sf' => 'BillingCountry' ),
				'annual_revenue'      => array( 'type' => 'decimal', 'sf' => 'AnnualRevenue' ),
				'number_of_employees' => array( 'type' => 'int',  'sf' => 'NumberOfEmployees' ),
				'description'         => array( 'type' => 'longtext', 'sf' => 'Description' ),
			),
			PCM_CRM_Model::system_fields()
		);

		$pcm_model = new PCM_CRM_Model(
			'account',
			PCM_CRM_Schema::accounts(),
			$pcm_fields,
			array( 'name', 'website', 'phone', 'billing_city', 'description' )
		);
	}

	return $pcm_model;
}

/**
 * Normalise an organization name for matching.
 *
 * Case, punctuation, a leading "the" and the common legal suffixes all vary
 * between how someone types their employer's name twice, and none of them mean
 * a different organization.
 */
function pcm_crm_account_key( $pcm_name ) {
	$pcm_key = strtolower( trim( (string) $pcm_name ) );
	$pcm_key = preg_replace( '/\b(inc|llc|ltd|co|corp|corporation|company|foundation)\b\.?/', '', $pcm_key );
	$pcm_key = preg_replace( '/[^a-z0-9]+/', ' ', $pcm_key );
	$pcm_key = preg_replace( '/^the /', '', trim( $pcm_key ) );

	return trim( preg_replace( '/\s+/', ' ', $pcm_key ) );
}

/**
 * Find an Account by normalised name, or create it.
 *
 * Used by the contact form intake, where the organization arrives as free text
 * and the same one will be typed a dozen slightly different ways over time.
 */
function pcm_crm_upsert_account( $pcm_name, array $pcm_extra = array() ) {
	global $wpdb;

	$pcm_name = trim( (string) $pcm_name );

	if ( '' === $pcm_name ) {
		return 0;
	}

	$pcm_key   = pcm_crm_account_key( $pcm_name );
	$pcm_table = PCM_CRM_Schema::accounts();

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal
	$pcm_existing = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$pcm_table} WHERE name_key = %s AND is_deleted = 0 ORDER BY id ASC LIMIT 1",
		$pcm_key
	) );

	if ( $pcm_existing ) {
		return $pcm_existing;
	}

	$pcm_id = pcm_crm_accounts()->insert( array_merge( array( 'name' => $pcm_name ), $pcm_extra ) );

	if ( is_wp_error( $pcm_id ) ) {
		return 0;
	}

	// name_key needs no write here: pcm_crm_sync_account_key() derives it on
	// the way into every insert and update, so it cannot fall out of step.
	return (int) $pcm_id;
}

/**
 * Keep name_key in step whenever a name is written through the normal path.
 */
function pcm_crm_sync_account_key( $pcm_row, $pcm_object ) {
	if ( 'account' === $pcm_object && isset( $pcm_row['name'] ) ) {
		$pcm_row['name_key'] = pcm_crm_account_key( $pcm_row['name'] );
	}

	return $pcm_row;
}
add_filter( 'pcm_crm_before_insert', 'pcm_crm_sync_account_key', 10, 2 );
add_filter( 'pcm_crm_before_update', 'pcm_crm_sync_account_key', 10, 2 );
