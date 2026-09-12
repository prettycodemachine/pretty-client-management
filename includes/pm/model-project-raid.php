<?php
/**
 * RAID log — Risks, Assumptions, Issues, Dependencies.
 *
 * One table with a raid_type discriminator: the same record with the same
 * lifecycle and four vocabularies.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_project_raid() {
	static $pcm_model = null;

	if ( null === $pcm_model ) {
		$pcm_model = new PCM_CRM_Model(
			'project_raid',
			pcm_crm_pm_raid_table(),
			array_merge(
				array(
					'project_id'       => array( 'type' => 'id',   'sf' => 'PCM_Project_Id__c', 'label' => 'Project' ),
					'raid_type'        => array( 'type' => 'text', 'sf' => 'PCM_Kind__c', 'label' => 'Kind', 'options' => 'pcm_crm_pm_raid_types' ),
					'title'            => array( 'type' => 'text', 'sf' => 'PCM_Title__c', 'label' => 'Title' ),
					'status'           => array( 'type' => 'text', 'sf' => 'PCM_Status__c', 'label' => 'Status', 'options' => 'pcm_crm_pm_raid_statuses' ),
					'probability'      => array( 'type' => 'text', 'sf' => 'PCM_Probability__c', 'label' => 'Probability', 'options' => 'pcm_crm_pm_raid_levels' ),
					'impact'           => array( 'type' => 'text', 'sf' => 'PCM_Impact__c', 'label' => 'Impact', 'options' => 'pcm_crm_pm_raid_levels' ),
					// Derived from probability and impact on write, and stored
					// because it is the sort key — an expression there costs a
					// filesort on every read of the top-risks block.
					'severity'         => array( 'type' => 'int',  'sf' => 'PCM_Severity__c', 'label' => 'Severity', 'readonly' => true ),
					'raised_date'      => array( 'type' => 'date', 'sf' => 'PCM_Raised_Date__c', 'label' => 'Raised' ),
					'due_date'         => array( 'type' => 'date', 'sf' => 'PCM_Due_Date__c', 'label' => 'Review By' ),
					'resolved_date'    => array( 'type' => 'date', 'sf' => 'PCM_Resolved_Date__c', 'label' => 'Resolved', 'readonly' => true ),
					'owner_contact_id' => array( 'type' => 'id',   'sf' => 'PCM_Owner_Contact__c', 'label' => 'Owned By (Contact)' ),
					'mitigation'       => array( 'type' => 'longtext', 'sf' => 'PCM_Mitigation__c', 'label' => 'Mitigation' ),
					'resolution'       => array( 'type' => 'longtext', 'sf' => 'PCM_Resolution__c', 'label' => 'Resolution' ),
					'description'      => array( 'type' => 'longtext', 'sf' => 'PCM_Description__c', 'label' => 'Detail' ),
				),
				PCM_CRM_Model::system_fields()
			),
			array( 'title', 'description', 'mitigation', 'resolution' ),
			array(
				'project' => array( 'column' => 'project_id', 'model' => 'pcm_crm_projects', 'label' => 'Project' ),
			)
		);
	}

	return $pcm_model;
}

/**
 * The words probability and impact are picked from.
 */
function pcm_crm_pm_raid_levels() {
	return array_keys( pcm_crm_pm_raid_scale() );
}

/**
 * Severity, from probability and impact.
 *
 * A product of two ordinal weights, so Medium × High outranks High × Low — which
 * a lexical sort on either column alone would get wrong. Zero when either half
 * is missing, rather than 1: an unscored risk should sort below a scored Low,
 * not level with it.
 */
function pcm_crm_pm_severity( $pcm_probability, $pcm_impact ) {
	$pcm_scale = pcm_crm_pm_raid_scale();

	$pcm_p = isset( $pcm_scale[ $pcm_probability ] ) ? (int) $pcm_scale[ $pcm_probability ] : 0;
	$pcm_i = isset( $pcm_scale[ $pcm_impact ] ) ? (int) $pcm_scale[ $pcm_impact ] : 0;

	return ( $pcm_p && $pcm_i ) ? $pcm_p * $pcm_i : 0;
}

/**
 * Keep severity and the resolved date in step.
 *
 * Both halves of the score are read from the merged record, because an edit that
 * changes only the impact still has to rescore — and would otherwise score
 * against a blank probability and drop the row to zero.
 */
function pcm_crm_pm_apply_raid( $pcm_row, $pcm_object, $pcm_id = 0 ) {
	if ( 'project_raid' !== $pcm_object ) {
		return $pcm_row;
	}

	$pcm_existing = $pcm_id ? pcm_crm_project_raid()->get( $pcm_id ) : null;

	if ( isset( $pcm_row['probability'] ) || isset( $pcm_row['impact'] ) ) {
		$pcm_probability = isset( $pcm_row['probability'] )
			? $pcm_row['probability']
			: ( $pcm_existing ? $pcm_existing['probability'] : '' );

		$pcm_impact = isset( $pcm_row['impact'] )
			? $pcm_row['impact']
			: ( $pcm_existing ? $pcm_existing['impact'] : '' );

		$pcm_row['severity'] = pcm_crm_pm_severity( $pcm_probability, $pcm_impact );
	}

	if ( isset( $pcm_row['status'] ) ) {
		$pcm_closed = in_array( $pcm_row['status'], array( 'Mitigated', 'Closed', 'Accepted' ), true );

		if ( $pcm_closed ) {
			if ( ! $pcm_existing || ! in_array( $pcm_existing['status'], array( 'Mitigated', 'Closed', 'Accepted' ), true ) ) {
				$pcm_row['resolved_date'] = current_time( 'Y-m-d' );
			}
		} else {
			$pcm_row['resolved_date'] = null;
		}
	}

	if ( ! $pcm_id && empty( $pcm_row['raised_date'] ) ) {
		$pcm_row['raised_date'] = current_time( 'Y-m-d' );
	}

	return $pcm_row;
}
add_filter( 'pcm_crm_before_insert', 'pcm_crm_pm_apply_raid', 10, 2 );
add_filter( 'pcm_crm_before_update', 'pcm_crm_pm_apply_raid', 10, 3 );
