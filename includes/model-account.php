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
				'name'                => array( 'type' => 'text', 'sf' => 'Name', 'label' => 'Account Name' ),
				// Normalised copy of name, maintained by pcm_crm_account_key().
				// The form intake matches on it, so "The Smith Trust" and
				// "the smith trust." do not become two accounts.
				'name_key'            => array( 'type' => 'text', 'readonly' => true, 'internal' => true ),
				'type'                => array( 'type' => 'text', 'sf' => 'Type', 'label' => 'Type', 'options' => 'pcm_crm_account_types' ),
				'industry'            => array( 'type' => 'text', 'sf' => 'Industry', 'label' => 'Industry', 'options' => 'pcm_crm_industries' ),
				'website'             => array( 'type' => 'url',  'sf' => 'Website', 'label' => 'Website' ),
				'phone'               => array( 'type' => 'text', 'sf' => 'Phone', 'label' => 'Phone' ),
				'billing_street'      => array( 'type' => 'text', 'sf' => 'BillingStreet', 'label' => 'Billing Street' ),
				'billing_city'        => array( 'type' => 'text', 'sf' => 'BillingCity', 'label' => 'Billing City' ),
				'billing_state'       => array( 'type' => 'text', 'sf' => 'BillingState', 'label' => 'Billing State' ),
				'billing_postal_code' => array( 'type' => 'text', 'sf' => 'BillingPostalCode', 'label' => 'Billing Postal Code' ),
				'billing_country'     => array( 'type' => 'text', 'sf' => 'BillingCountry', 'label' => 'Billing Country' ),
				'annual_revenue'      => array( 'type' => 'decimal', 'sf' => 'AnnualRevenue', 'label' => 'Annual Revenue' ),
				'number_of_employees' => array( 'type' => 'int',  'sf' => 'NumberOfEmployees', 'label' => 'Employees' ),
				'description'         => array( 'type' => 'longtext', 'sf' => 'Description', 'label' => 'Notes' ),
			),
			PCM_CRM_Model::system_fields(),
			// Admin-defined fields are real columns, so they join the map as
			// equals — every filter, export and report downstream reads this
			// map and needs no idea that some of it was configured.
			pcm_crm_custom_field_map( 'accounts' )
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

pcm_crm_register_object( 'accounts', array(
	'model'         => 'pcm_crm_accounts',
	'label'         => 'Account',
	'plural'        => 'Accounts',
	'reportable'    => true,
	'customisable'  => true,
	'exportable'    => true,
	'sf'            => 'Account',
	'group_options' => array( 'type', 'industry', 'billing_state', 'owner_id' ),
	'related'       => 'fetch',
) );
