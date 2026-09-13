<?php
/**
 * Status report — what was sent, as it was sent.
 *
 * The rendered subject and body are stored rather than regenerated, and so are
 * the figures. A status report is a statement made to a client on a date;
 * rebuilding it from today's numbers would change what you told them, which
 * makes the history worse than useless.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_status_reports() {
	static $pcm_model = null;

	if ( null === $pcm_model ) {
		$pcm_model = new PCM_CRM_Model(
			'status_report',
			pcm_crm_pm_reports_table(),
			array_merge(
				array(
					'project_id'     => array( 'type' => 'id',   'sf' => 'PCM_Project_Id__c', 'label' => 'Project', 'lookup' => 'projects' ),
					'template_id'    => array( 'type' => 'id',   'sf' => 'PCM_Template_Id__c', 'label' => 'Template' ),
					'subject'        => array( 'type' => 'text', 'sf' => 'PCM_Subject__c', 'label' => 'Subject' ),
					// 'raw' so the rendered HTML is stored verbatim: a text
					// sanitiser would take the markup apart, and this column is
					// a record of what went out rather than an editable draft.
					'body'           => array( 'type' => 'raw',  'sf' => 'PCM_Body__c', 'label' => 'Body' ),
					'recipients'     => array( 'type' => 'text', 'sf' => 'PCM_Recipients__c', 'label' => 'Sent To' ),
					'period_start'   => array( 'type' => 'date', 'sf' => 'PCM_Period_Start__c', 'label' => 'Covering From' ),
					'period_end'     => array( 'type' => 'date', 'sf' => 'PCM_Period_End__c', 'label' => 'Covering To' ),
					'health'         => array( 'type' => 'text', 'sf' => 'PCM_Health__c', 'label' => 'Health At Send' ),
					'hours_used'     => array( 'type' => 'hours', 'sf' => 'PCM_Hours_Used__c', 'label' => 'Hours Used' ),
					'hours_allotted' => array( 'type' => 'hours', 'sf' => 'PCM_Hours_Allotted__c', 'label' => 'Hours Allotted' ),
					'budget_used'    => array( 'type' => 'decimal', 'sf' => 'PCM_Budget_Used__c', 'label' => 'Budget Used', 'ui' => 'currency' ),
					'sent_date'      => array( 'type' => 'datetime', 'sf' => 'PCM_Sent_Date__c', 'label' => 'Sent', 'readonly' => true ),
					// Recorded on the row rather than only in a log, the way a
					// schedule's is: a failure nobody can see is a failure that
					// gets discovered by the client.
					'last_error'     => array( 'type' => 'text', 'label' => 'Last Error', 'readonly' => true ),
				),
				PCM_CRM_Model::system_fields()
			),
			array( 'subject', 'body' ),
			array(
				'project' => array( 'column' => 'project_id', 'model' => 'pcm_crm_projects', 'label' => 'Project' ),
			)
		);
	}

	return $pcm_model;
}
