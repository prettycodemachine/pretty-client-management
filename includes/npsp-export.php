<?php
/**
 * NPSP Data Import export.
 *
 * A different shape from the Sales Cloud files, not a different set of column
 * names. NPSP loads through a staging object, DataImport__c: one row carries a
 * contact *and* their organization together, and NPSP's own process then does
 * the matching, creates the household or organization account, and links them.
 * That is why this cannot be four files in load order — the whole point of the
 * NPSP path is that you do not have to translate ids between files.
 *
 * Field API names are NPSP's. Orgs running the managed package see them with
 * an `npsp__` prefix; the export offers both spellings for that reason, since
 * which one an org uses depends on how NPSP was installed rather than on
 * anything knowable from here.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Whether this org's Data Import fields carry the managed-package namespace.
 *
 * A setting rather than a guess: nothing on this side of the migration can see
 * the target org, and getting it wrong means every column fails to map.
 */
function pcm_crm_npsp_prefix() {
	return get_option( 'pcm_crm_npsp_namespace', '' ) ? 'npsp__' : '';
}

/**
 * The contact-and-organization columns, in the order they read best.
 *
 * Keyed by the CRM field, valued by NPSP's API name without the namespace.
 * Contact1 is NPSP's name for the primary person on a Data Import row.
 */
function pcm_crm_npsp_contact_map() {
	return array(
		'contact.first_name'          => 'Contact1_First_Name__c',
		'contact.last_name'           => 'Contact1_Last_Name__c',
		'contact.salutation'          => 'Contact1_Salutation__c',
		'contact.title'               => 'Contact1_Title__c',
		'contact.email'               => 'Contact1_Personal_Email__c',
		'contact.phone'               => 'Contact1_Work_Phone__c',
		'contact.mobile_phone'        => 'Contact1_Mobile_Phone__c',
		'contact.mailing_street'      => 'Home_Street__c',
		'contact.mailing_city'        => 'Home_City__c',
		'contact.mailing_state'       => 'Home_State_Province__c',
		'contact.mailing_postal_code' => 'Home_Zip_Postal_Code__c',
		'contact.mailing_country'     => 'Home_Country__c',
		'account.name'                => 'Account1_Name__c',
		'account.website'             => 'Account1_Website__c',
		'account.phone'               => 'Account1_Phone__c',
		'account.billing_street'      => 'Account1_Street__c',
		'account.billing_city'        => 'Account1_City__c',
		'account.billing_state'       => 'Account1_State_Province__c',
		'account.billing_postal_code' => 'Account1_Zip_Postal_Code__c',
		'account.billing_country'     => 'Account1_Country__c',
	);
}

/**
 * The donation columns NPSP fills an Opportunity from.
 *
 * Only closed-won deals are exported as donations: NPSP's Data Import creates
 * an Opportunity per row and has no concept of a pipeline stage to carry, so
 * loading open deals through it would post them as received income.
 */
function pcm_crm_npsp_donation_map() {
	return array(
		'opportunity.name'        => 'Donation_Name__c',
		'opportunity.amount'      => 'Donation_Amount__c',
		'opportunity.closed_date' => 'Donation_Date__c',
		'opportunity.description' => 'Donation_Description__c',
	);
}

/**
 * Every column in the NPSP file, prefixed for the org's namespace.
 *
 * PCM's own ids ride along in plain custom fields so a loaded record can still
 * be traced back here — NPSP ignores columns it does not recognise, so these
 * are harmless if the fields are never created.
 */
function pcm_crm_npsp_headers( $pcm_include_donations ) {
	$pcm_prefix = pcm_crm_npsp_prefix();
	$pcm_map    = pcm_crm_npsp_contact_map();

	if ( $pcm_include_donations ) {
		$pcm_map = array_merge( $pcm_map, pcm_crm_npsp_donation_map() );
	}

	$pcm_headers = array( 'PCM_Contact_Id__c', 'PCM_Account_Id__c' );

	foreach ( $pcm_map as $pcm_api ) {
		$pcm_headers[] = $pcm_prefix . $pcm_api;
	}

	return $pcm_headers;
}

/**
 * One row per contact, with their organization beside them.
 *
 * A contact with no account still gets a row: NPSP will create a household for
 * them, which is the correct outcome for an individual.
 */
function pcm_crm_npsp_rows( $pcm_include_donations ) {
	$pcm_contacts = pcm_crm_contacts()->find( array( 'per_page' => 0, 'orderby' => 'id', 'order' => 'ASC' ) );
	$pcm_accounts = pcm_crm_accounts()->get_many( wp_list_pluck( $pcm_contacts, 'account_id' ) );

	$pcm_map = pcm_crm_npsp_contact_map();

	if ( $pcm_include_donations ) {
		$pcm_map = array_merge( $pcm_map, pcm_crm_npsp_donation_map() );
	}

	$pcm_rows = array();

	foreach ( $pcm_contacts as $pcm_contact ) {
		$pcm_account = isset( $pcm_accounts[ $pcm_contact['account_id'] ] ) ? $pcm_accounts[ $pcm_contact['account_id'] ] : array();

		$pcm_sources = array(
			'contact' => $pcm_contact,
			'account' => $pcm_account,
		);

		$pcm_donations = $pcm_include_donations ? pcm_crm_npsp_donations_for( $pcm_contact['id'] ) : array( array() );

		// A contact with three won deals becomes three rows, because NPSP
		// creates one Opportunity per Data Import row. The contact columns
		// repeat, and NPSP's matching resolves them to the same person.
		foreach ( $pcm_donations as $pcm_donation ) {
			$pcm_sources['opportunity'] = $pcm_donation;

			$pcm_row = array( $pcm_contact['id'], $pcm_contact['account_id'] );

			foreach ( array_keys( $pcm_map ) as $pcm_key ) {
				list( $pcm_object, $pcm_field ) = explode( '.', $pcm_key, 2 );

				$pcm_value = isset( $pcm_sources[ $pcm_object ][ $pcm_field ] ) ? $pcm_sources[ $pcm_object ][ $pcm_field ] : '';

				if ( in_array( $pcm_field, array( 'closed_date' ), true ) && pcm_crm_is_zero_date( $pcm_value ) ) {
					$pcm_value = '';
				}

				$pcm_row[] = $pcm_value;
			}

			$pcm_rows[] = $pcm_row;
		}
	}

	return $pcm_rows;
}

/**
 * A contact's won deals, or one empty row so the contact still exports.
 */
function pcm_crm_npsp_donations_for( $pcm_contact_id ) {
	$pcm_won = pcm_crm_opportunities()->find( array(
		'filters'  => array( 'primary_contact_id' => (int) $pcm_contact_id, 'is_won' => 1 ),
		'per_page' => 0,
		'orderby'  => 'closed_date',
		'order'    => 'ASC',
	) );

	return $pcm_won ? $pcm_won : array( array() );
}

function pcm_crm_npsp_export_url( $pcm_include_donations = false ) {
	return wp_nonce_url(
		add_query_arg(
			array( 'action' => 'pcm_crm_export_npsp', 'donations' => $pcm_include_donations ? 1 : 0 ),
			admin_url( 'admin-post.php' )
		),
		'pcm_crm_export',
		'pcm_crm_nonce'
	);
}

function pcm_crm_handle_npsp_export() {
	if (
		! pcm_crm_user_can() ||
		! isset( $_GET['pcm_crm_nonce'] ) ||
		! wp_verify_nonce( sanitize_key( $_GET['pcm_crm_nonce'] ), 'pcm_crm_export' )
	) {
		wp_die( esc_html__( 'You are not allowed to export CRM data.', 'pcm-crm' ), 403 );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above
	$pcm_donations = ! empty( $_GET['donations'] );

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="pcm-npsp-data-import-' . gmdate( 'Y-m-d' ) . '.csv"' );

	$pcm_out = fopen( 'php://output', 'w' );

	// Excel reads a bare UTF-8 CSV as Latin-1 and mangles anything accented.
	fwrite( $pcm_out, "\xEF\xBB\xBF" );

	fputcsv( $pcm_out, pcm_crm_npsp_headers( $pcm_donations ) );

	foreach ( pcm_crm_npsp_rows( $pcm_donations ) as $pcm_row ) {
		fputcsv( $pcm_out, $pcm_row );
	}

	fclose( $pcm_out );
	exit;
}
add_action( 'admin_post_pcm_crm_export_npsp', 'pcm_crm_handle_npsp_export' );
