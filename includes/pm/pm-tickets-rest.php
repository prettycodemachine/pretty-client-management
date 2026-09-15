<?php
/**
 * Staff-side routes for a ticket's comment thread and a project's document
 * library — kept out of pm-rest.php, which is already large, and out of
 * class-pcm-crm-rest.php's generic object routes because neither
 * help_ticket_comment nor project_document is a registered object (see
 * model-ticket-comment.php's and model-project-document.php's doc comments).
 *
 * Every route here is gated on the staff capability. The Client Portal module
 * registers its own, differently-scoped twins in portal/portal-rest.php —
 * this file and that one call the same model insert/validate path; the only
 * difference is which permission_callback is used and what stamps
 * is_client_comment.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_pm_register_ticket_routes() {
	register_rest_route( PCM_CRM_REST::NS, '/pm/tickets/(?P<pcm_id>\d+)/comments', array(
		array(
			'methods'             => 'GET',
			'callback'            => 'pcm_crm_pm_rest_list_comments',
			'permission_callback' => array( 'PCM_CRM_REST', 'permission' ),
		),
		array(
			'methods'             => 'POST',
			'callback'            => 'pcm_crm_pm_rest_create_comment',
			'permission_callback' => array( 'PCM_CRM_REST', 'permission' ),
		),
	) );

	register_rest_route( PCM_CRM_REST::NS, '/pm/projects/(?P<pcm_id>\d+)/documents', array(
		'methods'             => 'POST',
		'callback'            => 'pcm_crm_pm_rest_create_document',
		'permission_callback' => array( 'PCM_CRM_REST', 'permission' ),
	) );

	register_rest_route( PCM_CRM_REST::NS, '/pm/documents/(?P<pcm_id>\d+)', array(
		'methods'             => 'DELETE',
		'callback'            => 'pcm_crm_pm_rest_delete_document',
		'permission_callback' => array( 'PCM_CRM_REST', 'permission' ),
	) );

	register_rest_route( PCM_CRM_REST::NS, '/pm/documents/(?P<pcm_id>\d+)/download', array(
		'methods'             => 'GET',
		'callback'            => 'pcm_crm_pm_rest_download_document',
		'permission_callback' => array( 'PCM_CRM_REST', 'permission' ),
	) );

	register_rest_route( PCM_CRM_REST::NS, '/pm/tickets/(?P<pcm_id>\d+)/attachments', array(
		array(
			'methods'             => 'GET',
			'callback'            => 'pcm_crm_pm_rest_list_attachments',
			'permission_callback' => array( 'PCM_CRM_REST', 'permission' ),
		),
		array(
			'methods'             => 'POST',
			'callback'            => 'pcm_crm_pm_rest_create_attachment',
			'permission_callback' => array( 'PCM_CRM_REST', 'permission' ),
		),
	) );
}
add_action( 'rest_api_init', 'pcm_crm_pm_register_ticket_routes' );

/**
 * A comment thread, with each row carrying the name behind created_by_id —
 * meaningful either way here, since a client comment's author is a real WP
 * user under the Client Portal module the same as a staff one.
 */
function pcm_crm_pm_decorate_comments( array $pcm_rows ) {
	return array_map( function ( $pcm_row ) {
		$pcm_row['_author_name'] = pcm_crm_user_name( $pcm_row['created_by_id'] );
		return $pcm_row;
	}, $pcm_rows );
}

function pcm_crm_pm_rest_list_comments( WP_REST_Request $pcm_request ) {
	$pcm_id = absint( $pcm_request->get_url_params()['pcm_id'] );

	return rest_ensure_response( pcm_crm_pm_decorate_comments( pcm_crm_pm_ticket_thread( $pcm_id ) ) );
}

function pcm_crm_pm_rest_create_comment( WP_REST_Request $pcm_request ) {
	$pcm_id   = absint( $pcm_request->get_url_params()['pcm_id'] );
	$pcm_body = (array) $pcm_request->get_json_params();

	$pcm_new_id = pcm_crm_ticket_comments()->insert( array(
		'ticket_id' => $pcm_id,
		'parent_id' => isset( $pcm_body['parent_id'] ) ? absint( $pcm_body['parent_id'] ) : 0,
		'body'      => isset( $pcm_body['body'] ) ? (string) $pcm_body['body'] : '',
	) );

	if ( is_wp_error( $pcm_new_id ) ) {
		$pcm_new_id->add_data( array( 'status' => 400 ) );
		return $pcm_new_id;
	}

	return rest_ensure_response( pcm_crm_ticket_comments()->get( $pcm_new_id ) );
}

function pcm_crm_pm_rest_create_document( WP_REST_Request $pcm_request ) {
	$pcm_id   = absint( $pcm_request->get_url_params()['pcm_id'] );
	$pcm_body = (array) $pcm_request->get_json_params();

	$pcm_new_id = pcm_crm_project_documents()->insert( array(
		'project_id'    => $pcm_id,
		'attachment_id' => isset( $pcm_body['attachment_id'] ) ? absint( $pcm_body['attachment_id'] ) : 0,
		'label'         => isset( $pcm_body['label'] ) ? (string) $pcm_body['label'] : '',
	) );

	if ( is_wp_error( $pcm_new_id ) ) {
		$pcm_new_id->add_data( array( 'status' => 400 ) );
		return $pcm_new_id;
	}

	return rest_ensure_response( pcm_crm_pm_decorate_document( pcm_crm_project_documents()->get( $pcm_new_id ) ) );
}

function pcm_crm_pm_rest_list_attachments( WP_REST_Request $pcm_request ) {
	$pcm_id = absint( $pcm_request->get_url_params()['pcm_id'] );

	return rest_ensure_response( pcm_crm_pm_attachments_for_ticket( $pcm_id ) );
}

function pcm_crm_pm_rest_create_attachment( WP_REST_Request $pcm_request ) {
	$pcm_id     = absint( $pcm_request->get_url_params()['pcm_id'] );
	$pcm_ticket = pcm_crm_help_tickets()->get( $pcm_id );

	if ( ! $pcm_ticket ) {
		return new WP_Error( 'pcm_crm_ticket_not_found', __( 'That ticket no longer exists.', 'pcm-crm' ), array( 'status' => 404 ) );
	}

	return pcm_crm_pm_handle_ticket_attachment_upload( $pcm_ticket );
}

/**
 * Upload one file and attach it to a Help Ticket — the shared implementation
 * behind both this staff route and the portal's twin
 * (pcm_crm_portal_rest_create_attachment() in portal/portal-rest.php). A
 * client has no wp.media (that needs the upload_files capability, which
 * pcm_client deliberately has none of), so this is the one place either side
 * turns a raw upload into an attachment_id — via WordPress's own
 * media_handle_upload(), which validates the file type against the site's
 * allowed list the same way any other WordPress upload does.
 */
function pcm_crm_pm_handle_ticket_attachment_upload( array $pcm_ticket, $pcm_field = 'file' ) {
	if ( empty( $_FILES[ $pcm_field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- REST route, gated by its own permission_callback
		return new WP_Error( 'pcm_crm_attachment_missing_file', __( 'Choose a file first.', 'pcm-crm' ), array( 'status' => 400 ) );
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	$pcm_attachment_id = media_handle_upload( $pcm_field, 0 );

	if ( is_wp_error( $pcm_attachment_id ) ) {
		$pcm_attachment_id->add_data( array( 'status' => 400 ) );
		return $pcm_attachment_id;
	}

	$pcm_new_id = pcm_crm_project_documents()->insert( array(
		'project_id'    => (int) $pcm_ticket['project_id'],
		'ticket_id'     => (int) $pcm_ticket['id'],
		'attachment_id' => $pcm_attachment_id,
		'label'         => get_the_title( $pcm_attachment_id ),
	) );

	if ( is_wp_error( $pcm_new_id ) ) {
		// The attachment itself uploaded fine; only the CRM row failed to
		// save (project_id somehow missing). Leaving an unattached Media
		// Library row behind is a smaller problem than losing the upload.
		$pcm_new_id->add_data( array( 'status' => 400 ) );
		return $pcm_new_id;
	}

	return rest_ensure_response( pcm_crm_pm_decorate_document( pcm_crm_project_documents()->get( $pcm_new_id ) ) );
}

function pcm_crm_pm_rest_delete_document( WP_REST_Request $pcm_request ) {
	$pcm_id = absint( $pcm_request->get_url_params()['pcm_id'] );

	$pcm_result = pcm_crm_project_documents()->delete( $pcm_id );

	if ( is_wp_error( $pcm_result ) ) {
		$pcm_result->add_data( array( 'status' => 400 ) );
		return $pcm_result;
	}

	return rest_ensure_response( array( 'deleted' => true ) );
}

/**
 * Stream a document to a staff member — the same download mechanism the
 * portal uses (see portal/portal-rest.php), so staff and clients fetch a
 * project's files through one code path with two different permission
 * checks, never a public Media Library URL either way.
 */
function pcm_crm_pm_rest_download_document( WP_REST_Request $pcm_request ) {
	$pcm_id  = absint( $pcm_request->get_url_params()['pcm_id'] );
	$pcm_doc = pcm_crm_project_documents()->get( $pcm_id );

	if ( ! $pcm_doc ) {
		return new WP_Error( 'pcm_crm_document_not_found', __( 'That document no longer exists.', 'pcm-crm' ), array( 'status' => 404 ) );
	}

	pcm_crm_stream_document( (int) $pcm_doc['attachment_id'], 'inline' === $pcm_request->get_param( 'disposition' ) );
}

/**
 * Send a Media Library file's bytes directly, rather than the REST response
 * cycle — the shared implementation behind both the staff and portal download
 * routes. A Media Library attachment URL on this host is otherwise publicly
 * reachable by anyone who has or guesses it (see CLAUDE.md's SiteGround
 * notes), which a client or project document must not be.
 *
 * $pcm_inline asks the browser to render the file (an image, a PDF) in place
 * for the record page's preview pane, rather than saving it — same bytes,
 * same permission check already run by the caller, only the disposition
 * header differs.
 */
function pcm_crm_stream_document( $pcm_attachment_id, $pcm_inline = false ) {
	$pcm_path = get_attached_file( $pcm_attachment_id );

	if ( ! $pcm_path || ! is_readable( $pcm_path ) ) {
		wp_die( esc_html__( 'That file is no longer available.', 'pcm-crm' ), 404 );
	}

	$pcm_type = get_post_mime_type( $pcm_attachment_id );

	nocache_headers();
	header( 'Content-Type: ' . ( $pcm_type ? $pcm_type : 'application/octet-stream' ) );
	header( 'Content-Disposition: ' . ( $pcm_inline ? 'inline' : 'attachment' ) . '; filename="' . sanitize_file_name( wp_basename( $pcm_path ) ) . '"' );
	header( 'Content-Length: ' . filesize( $pcm_path ) );
	header( 'X-Content-Type-Options: nosniff' );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile -- streaming a file, not reading it into memory
	readfile( $pcm_path );
	exit;
}
