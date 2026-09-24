<?php
/**
 * Project contact role.
 *
 * Internal people are WordPress users; client contacts are CRM contact rows; a
 * partner is a person at a partner account, or the firm itself before a name is
 * known. One table with a party_type discriminator says which id column is
 * filled, because every reader asks the same question — who is on this project,
 * and in what capacity.
 *
 * party_type is stored, never derived from the account's type. An account's type
 * changes, and deriving the classification would retroactively rewrite who was
 * internal to an engagement that finished last year — the same reasoning behind
 * snapshotting a rate onto a time entry.
 *
 * A contractor is honestly two rows: an internal one carrying their cost to you,
 * and a partner one saying who they are to the client.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_project_roles() {
	static $pcm_model = null;

	if ( null === $pcm_model ) {
		$pcm_model = new PCM_CRM_Model(
			'project_role',
			pcm_crm_pm_roles_table(),
			array_merge(
				array(
					'project_id'         => array( 'type' => 'id',   'sf' => 'PCM_Project_Id__c', 'label' => 'Project', 'lookup' => 'projects' ),
					'party_type'         => array( 'type' => 'text', 'sf' => 'PCM_Party_Type__c', 'label' => 'Party', 'options' => 'pcm_crm_pm_party_type_values' ),
					'user_id'            => array( 'type' => 'id',   'sf' => 'PCM_User__c', 'label' => 'Team Member', 'options' => 'pcm_crm_owner_options' ),
					'contact_id'         => array( 'type' => 'id',   'sf' => 'PCM_Contact_Id__c', 'label' => 'Contact', 'lookup' => 'contacts', 'lookup_filter' => array( 'account_id' => 'partner_account_id' ) ),
					'partner_account_id' => array( 'type' => 'id',   'sf' => 'PCM_Partner_Account__c', 'label' => 'Partner Firm', 'lookup' => 'accounts' ),
					'role'               => array( 'type' => 'text', 'sf' => 'PCM_Role__c', 'label' => 'Role', 'options' => 'pcm_crm_pm_roles' ),
					'is_primary'         => array( 'type' => 'bool', 'sf' => 'PCM_Is_Primary__c', 'label' => 'Primary' ),
					'bill_rate'          => array( 'type' => 'decimal', 'sf' => 'PCM_Bill_Rate__c', 'label' => 'Bill Rate', 'ui' => 'currency' ),
					'cost_rate'          => array( 'type' => 'decimal', 'sf' => 'PCM_Cost_Rate__c', 'label' => 'Cost Rate', 'ui' => 'currency' ),
					'start_date'         => array( 'type' => 'date', 'sf' => 'PCM_Start_Date__c', 'label' => 'From' ),
					'end_date'           => array( 'type' => 'date', 'sf' => 'PCM_End_Date__c', 'label' => 'Until' ),
					'description'        => array( 'type' => 'longtext', 'sf' => 'PCM_Description__c', 'label' => 'Notes' ),
				),
				PCM_CRM_Model::system_fields(),
				pcm_crm_custom_field_map( 'project_roles' )
			),
			array( 'role', 'description' ),
			array(
				'project' => array( 'column' => 'project_id', 'model' => 'pcm_crm_projects', 'label' => 'Project' ),
				'contact' => array( 'column' => 'contact_id', 'model' => 'pcm_crm_contacts', 'label' => 'Contact' ),
			)
		);
	}

	return $pcm_model;
}

/**
 * Party's choices for the record form: the stored key as the value, its
 * capitalised name as the label. Bare keys showed as "partner" and "client".
 */
function pcm_crm_pm_party_type_values() {
	$pcm_out = array();

	foreach ( pcm_crm_pm_party_types() as $pcm_key => $pcm_label ) {
		$pcm_out[] = array( 'value' => $pcm_key, 'label' => $pcm_label );
	}

	return $pcm_out;
}

/**
 * A role has to name somebody.
 *
 * The failure this catches is a role saved with a party type and no party: it
 * looks fine in a list, counts towards the team, and names nobody. Checked
 * against the merged record so an edit that touches only the rate does not trip.
 */
function pcm_crm_pm_validate_role( $pcm_error, $pcm_object, $pcm_row, $pcm_id ) {
	if ( 'project_role' !== $pcm_object || is_wp_error( $pcm_error ) ) {
		return $pcm_error;
	}

	$pcm_existing = $pcm_id ? pcm_crm_project_roles()->get( $pcm_id ) : array();
	$pcm_merged   = array_merge( is_array( $pcm_existing ) ? $pcm_existing : array(), $pcm_row );

	$pcm_party = (string) $pcm_merged['party_type'];

	if ( ! isset( pcm_crm_pm_party_types()[ $pcm_party ] ) ) {
		return new WP_Error(
			'pcm_crm_pm_unknown_party',
			__( 'Say whether this person is internal, a partner, or on the client side.', 'pcm-crm' ),
			array( 'status' => 400 )
		);
	}

	if ( 'internal' === $pcm_party && empty( $pcm_merged['user_id'] ) ) {
		return new WP_Error(
			'pcm_crm_pm_no_user',
			__( 'An internal role needs a team member: they log time against the project and appear on the resourcing board.', 'pcm-crm' ),
			array( 'status' => 400 )
		);
	}

	if ( 'client' === $pcm_party && empty( $pcm_merged['contact_id'] ) ) {
		return new WP_Error(
			'pcm_crm_pm_no_contact',
			__( 'A client role needs a contact — that is who a status report goes to.', 'pcm-crm' ),
			array( 'status' => 400 )
		);
	}

	// Either is enough: a partner firm may be engaged before anyone there has
	// been named, and refusing the row would mean not recording the engagement.
	if ( 'partner' === $pcm_party && empty( $pcm_merged['contact_id'] ) && empty( $pcm_merged['partner_account_id'] ) ) {
		return new WP_Error(
			'pcm_crm_pm_no_partner',
			__( 'A partner role needs either a contact or the partner firm.', 'pcm-crm' ),
			array( 'status' => 400 )
		);
	}

	return $pcm_error;
}
add_filter( 'pcm_crm_validate', 'pcm_crm_pm_validate_role', 10, 4 );
