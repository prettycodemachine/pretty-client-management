<?php
/**
 * Contact form submissions — a local audit trail.
 *
 * Not a Salesforce object and never exported. It exists so an inquiry leaves
 * evidence even when the intake upsert or the mail fails, which is exactly the
 * case where you most want to know what someone sent.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_submissions() {
	static $pcm_model = null;

	if ( null === $pcm_model ) {
		$pcm_model = new PCM_CRM_Model(
			'submission',
			PCM_CRM_Schema::submissions(),
			array(
				'payload'            => array( 'type' => 'longtext' ),
				'ip_address'         => array( 'type' => 'text' ),
				'user_agent'         => array( 'type' => 'text' ),
				'is_spam'            => array( 'type' => 'bool' ),
				'contact_id'         => array( 'type' => 'id' ),
				'account_id'         => array( 'type' => 'id' ),
				'activity_id'        => array( 'type' => 'id' ),
				'notification_sent'  => array( 'type' => 'bool' ),
				'autoresponder_sent' => array( 'type' => 'bool' ),
				'intake_error'       => array( 'type' => 'longtext' ),
				'created_date'       => array( 'type' => 'datetime', 'readonly' => true ),
			),
			array( 'payload' )
		);
	}

	return $pcm_model;
}
