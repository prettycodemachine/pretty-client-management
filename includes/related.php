<?php
/**
 * Related-list providers.
 *
 * What hangs off a record used to be an if-chain inside the REST class, with
 * the same three slugs repeated in its route's regex. Both are now a registry,
 * so a module can add a list to an Account without touching the function that
 * builds the Account's other three — and without the two places disagreeing
 * about which objects have related lists at all.
 *
 * A provider is a callable taking ( $pcm_id, $pcm_object ) and returning
 * array( list key => rows ). Several may register against one slug; their
 * returns are merged in registration order.
 *
 * Note the half of this that lives in the browser: a list only gets a *tab* if
 * childTypes() in assets/crm.js names it. A provider whose key nothing renders
 * returns data nobody sees.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_register_related( $pcm_slug, $pcm_callable ) {
	if ( ! isset( $GLOBALS['pcm_crm_related'] ) ) {
		$GLOBALS['pcm_crm_related'] = array();
	}

	$GLOBALS['pcm_crm_related'][ $pcm_slug ][] = $pcm_callable;
}

function pcm_crm_related_providers() {
	$pcm_providers = isset( $GLOBALS['pcm_crm_related'] ) ? $GLOBALS['pcm_crm_related'] : array();

	return apply_filters( 'pcm_crm_related_providers', $pcm_providers );
}

/**
 * Everything hanging off one record.
 */
function pcm_crm_related_for( $pcm_object, $pcm_id ) {
	$pcm_providers = pcm_crm_related_providers();
	$pcm_out       = array();

	if ( empty( $pcm_providers[ $pcm_object ] ) ) {
		return $pcm_out;
	}

	foreach ( $pcm_providers[ $pcm_object ] as $pcm_provider ) {
		if ( ! is_callable( $pcm_provider ) ) {
			continue;
		}

		$pcm_out = array_merge( $pcm_out, (array) call_user_func( $pcm_provider, $pcm_id, $pcm_object ) );
	}

	return $pcm_out;
}

/* Core providers -------------------------------------------------------------
   Lifted verbatim out of PCM_CRM_REST::related().
   -------------------------------------------------------------------------- */

function pcm_crm_related_account( $pcm_id ) {
	return array(
		'contacts'      => PCM_CRM_REST::expand( 'contact', pcm_crm_contacts()->find( array(
			'filters'  => array( 'account_id' => $pcm_id ),
			'orderby'  => 'last_name',
			'order'    => 'ASC',
			'per_page' => 100,
		) ) ),
		'opportunities' => PCM_CRM_REST::expand( 'opportunity', pcm_crm_opportunities()->find( array(
			'filters'  => array( 'account_id' => $pcm_id ),
			'orderby'  => 'close_date',
			'order'    => 'ASC',
			'per_page' => 100,
		) ) ),
		'activities'    => PCM_CRM_REST::expand( 'activity', pcm_crm_activities_for( 'account', $pcm_id ) ),
	);
}

function pcm_crm_related_contact( $pcm_id ) {
	return array(
		'opportunities' => PCM_CRM_REST::expand( 'opportunity', pcm_crm_opportunities()->find( array(
			'filters'  => array( 'primary_contact_id' => $pcm_id ),
			'orderby'  => 'close_date',
			'order'    => 'ASC',
			'per_page' => 100,
		) ) ),
		'activities'    => PCM_CRM_REST::expand( 'activity', pcm_crm_activities_for( 'contact', $pcm_id ) ),
		'enrollments'   => PCM_CRM_REST::expand( 'enrollment', pcm_crm_enrollments()->find( array(
			'filters'  => array( 'contact_id' => $pcm_id ),
			'orderby'  => 'created_date',
			'order'    => 'DESC',
			'per_page' => 50,
		) ) ),
	);
}

function pcm_crm_related_opportunity( $pcm_id ) {
	return array(
		'activities' => PCM_CRM_REST::expand( 'activity', pcm_crm_activities_for( 'opportunity', $pcm_id ) ),
		'history'    => pcm_crm_stage_history_for( $pcm_id ),
	);
}

pcm_crm_register_related( 'accounts', 'pcm_crm_related_account' );
pcm_crm_register_related( 'contacts', 'pcm_crm_related_contact' );
pcm_crm_register_related( 'opportunities', 'pcm_crm_related_opportunity' );
