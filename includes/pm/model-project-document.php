<?php
/**
 * Project document.
 *
 * One row per uploaded file — an open-ended, individually-removable related
 * list, which is a table's job, not the single-option-array convention the
 * email autoresponder's attachment picker uses (see pcm_crm_attachment_ids()
 * in public/email.php). Like the ticket comment thread, this is only ever
 * viewed inside its parent project, so it is a plain model reached through
 * sub-routes (pm-tickets-rest.php for staff, portal/portal-rest.php for the
 * client's read-only list and download), not a registered object.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_project_documents() {
	static $pcm_model = null;

	if ( null === $pcm_model ) {
		$pcm_model = new PCM_CRM_Model(
			'project_document',
			pcm_crm_pm_documents_table(),
			array_merge(
				array(
					'project_id'    => array( 'type' => 'id', 'sf' => 'PCM_Project_Id__c', 'label' => 'Project', 'lookup' => 'projects' ),
					// Points at wp_posts (the Media Library), not a CRM table —
					// deliberately no 'lookup', since expand() has nothing to
					// resolve it against and the browser already has the
					// filename from the wp.media picker that uploaded it.
					'attachment_id' => array( 'type' => 'id', 'label' => 'File' ),
					'label'         => array( 'type' => 'text', 'sf' => 'PCM_Label__c', 'label' => 'Label' ),
				),
				PCM_CRM_Model::system_fields()
			),
			array( 'label' ),
			array(
				'project' => array( 'column' => 'project_id', 'model' => 'pcm_crm_projects', 'label' => 'Project' ),
			)
		);
	}

	return $pcm_model;
}

function pcm_crm_pm_validate_document( $pcm_error, $pcm_object, $pcm_row, $pcm_id ) {
	if ( 'project_document' !== $pcm_object || is_wp_error( $pcm_error ) ) {
		return $pcm_error;
	}

	$pcm_existing = $pcm_id ? pcm_crm_project_documents()->get( $pcm_id ) : array();
	$pcm_merged   = array_merge( is_array( $pcm_existing ) ? $pcm_existing : array(), $pcm_row );

	if ( empty( $pcm_merged['project_id'] ) ) {
		return new WP_Error( 'pcm_crm_document_no_project', __( 'A document has to belong to a project.', 'pcm-crm' ), array( 'status' => 400 ) );
	}

	if ( empty( $pcm_merged['attachment_id'] ) || ! get_post( $pcm_merged['attachment_id'] ) ) {
		return new WP_Error( 'pcm_crm_document_missing_file', __( 'Choose a file first.', 'pcm-crm' ), array( 'status' => 400 ) );
	}

	return $pcm_error;
}
add_filter( 'pcm_crm_validate', 'pcm_crm_pm_validate_document', 10, 4 );

/**
 * A project's documents with enough about the underlying file to list and
 * download it — filename, size, mime type — resolved from the Media Library
 * rather than stored a second time on the row.
 */
function pcm_crm_pm_documents_for( $pcm_project_id ) {
	$pcm_rows = pcm_crm_project_documents()->find( array(
		'filters'  => array( 'project_id' => (int) $pcm_project_id ),
		'orderby'  => 'created_date',
		'order'    => 'DESC',
		'per_page' => 200,
	) );

	return array_map( 'pcm_crm_pm_decorate_document', $pcm_rows );
}

function pcm_crm_pm_decorate_document( array $pcm_row ) {
	$pcm_path = get_attached_file( (int) $pcm_row['attachment_id'] );

	$pcm_row['_filename']  = $pcm_path ? wp_basename( $pcm_path ) : '';
	$pcm_row['_filesize']  = ( $pcm_path && is_readable( $pcm_path ) ) ? filesize( $pcm_path ) : 0;
	$pcm_row['_mime_type'] = get_post_mime_type( (int) $pcm_row['attachment_id'] );
	$pcm_row['_missing']   = ! ( $pcm_path && is_readable( $pcm_path ) );

	return $pcm_row;
}
