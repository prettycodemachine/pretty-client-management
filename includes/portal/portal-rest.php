<?php
/**
 * The client-facing REST surface: everything under /portal.
 *
 * Every route here is gated by a permission_callback from permissions.php —
 * never PCM_CRM_REST::permission(), the staff gate, which would let any
 * staff member hit these as if they were a client. Business logic that
 * already exists (pcm_crm_pm_project_summary(), pcm_crm_pm_ticket_thread())
 * is reused as-is; what's new here is the projection down to what a client
 * may see, and never trusting request identity that permissions.php has not
 * already checked.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_portal_register_routes() {
	register_rest_route( PCM_CRM_REST::NS, '/portal/me', array(
		'methods'             => 'GET',
		'callback'            => 'pcm_crm_portal_rest_me',
		'permission_callback' => 'pcm_crm_portal_permission',
	) );

	register_rest_route( PCM_CRM_REST::NS, '/portal/summary', array(
		'methods'             => 'GET',
		'callback'            => 'pcm_crm_portal_rest_summary',
		'permission_callback' => 'pcm_crm_portal_permission_project',
	) );

	register_rest_route( PCM_CRM_REST::NS, '/portal/raid', array(
		'methods'             => 'GET',
		'callback'            => 'pcm_crm_portal_rest_raid',
		'permission_callback' => 'pcm_crm_portal_permission_project',
	) );

	register_rest_route( PCM_CRM_REST::NS, '/portal/documents', array(
		'methods'             => 'GET',
		'callback'            => 'pcm_crm_portal_rest_documents',
		'permission_callback' => 'pcm_crm_portal_permission_project',
	) );

	register_rest_route( PCM_CRM_REST::NS, '/portal/documents/(?P<pcm_id>\d+)/download', array(
		'methods'             => 'GET',
		'callback'            => 'pcm_crm_portal_rest_download',
		'permission_callback' => 'pcm_crm_portal_permission_document',
	) );

	register_rest_route( PCM_CRM_REST::NS, '/portal/tickets', array(
		array(
			'methods'             => 'GET',
			'callback'            => 'pcm_crm_portal_rest_list_tickets',
			'permission_callback' => 'pcm_crm_portal_permission',
		),
		array(
			'methods'             => 'POST',
			'callback'            => 'pcm_crm_portal_rest_create_ticket',
			'permission_callback' => 'pcm_crm_portal_permission_project',
		),
	) );

	register_rest_route( PCM_CRM_REST::NS, '/portal/tickets/(?P<pcm_id>\d+)', array(
		'methods'             => 'GET',
		'callback'            => 'pcm_crm_portal_rest_get_ticket',
		'permission_callback' => 'pcm_crm_portal_permission_ticket',
	) );

	register_rest_route( PCM_CRM_REST::NS, '/portal/tickets/(?P<pcm_id>\d+)/status', array(
		'methods'             => 'PATCH',
		'callback'            => 'pcm_crm_portal_rest_update_status',
		'permission_callback' => 'pcm_crm_portal_permission_ticket',
	) );

	register_rest_route( PCM_CRM_REST::NS, '/portal/tickets/(?P<pcm_id>\d+)/attachments', array(
		array(
			'methods'             => 'GET',
			'callback'            => 'pcm_crm_portal_rest_list_attachments',
			'permission_callback' => 'pcm_crm_portal_permission_ticket',
		),
		array(
			'methods'             => 'POST',
			'callback'            => 'pcm_crm_portal_rest_create_attachment',
			'permission_callback' => 'pcm_crm_portal_permission_ticket',
		),
	) );

	register_rest_route( PCM_CRM_REST::NS, '/portal/tickets/(?P<pcm_id>\d+)/comments', array(
		array(
			'methods'             => 'GET',
			'callback'            => 'pcm_crm_portal_rest_list_comments',
			'permission_callback' => 'pcm_crm_portal_permission_ticket',
		),
		array(
			'methods'             => 'POST',
			'callback'            => 'pcm_crm_portal_rest_create_comment',
			'permission_callback' => 'pcm_crm_portal_permission_ticket',
		),
	) );
}
add_action( 'rest_api_init', 'pcm_crm_portal_register_routes' );

function pcm_crm_portal_rest_me( WP_REST_Request $pcm_request ) {
	$pcm_context = pcm_crm_portal_context();
	$pcm_contact = pcm_crm_contacts()->get( $pcm_context['contact_id'] );

	$pcm_projects = array();

	foreach ( $pcm_context['project_ids'] as $pcm_project_id ) {
		$pcm_project = pcm_crm_projects()->get( $pcm_project_id );

		if ( ! $pcm_project ) {
			continue;
		}

		$pcm_account = $pcm_project['account_id'] ? pcm_crm_accounts()->get( $pcm_project['account_id'] ) : null;

		$pcm_projects[] = array(
			'id'           => (int) $pcm_project['id'],
			'name'         => $pcm_project['name'],
			'account_name' => $pcm_account ? $pcm_account['name'] : '',
		);
	}

	return rest_ensure_response( array(
		'contact_name' => $pcm_contact ? pcm_crm_contact_name( $pcm_contact ) : '',
		'projects'     => $pcm_projects,
	) );
}

/**
 * The project summary, with everything dollar- or rate-valued stripped —
 * hours only. The raw admin route (pcm_crm_pm_project_summary(), which this
 * calls) is never handed to the browser directly for exactly this reason.
 */
function pcm_crm_portal_safe_summary( array $pcm_summary ) {
	return array(
		'type_label'     => $pcm_summary['type_label'],
		'logged_hours'   => $pcm_summary['logged_hours'],
		'billable_hours' => $pcm_summary['billable_hours'],
		'budget_hours'   => $pcm_summary['budget_hours'],
		'estimate_hours' => $pcm_summary['estimate_hours'],
		'current_period' => $pcm_summary['current_period'] ? array(
			'start'     => $pcm_summary['current_period']['start'],
			'end'       => $pcm_summary['current_period']['end'],
			'available' => $pcm_summary['current_period']['available'],
			'used'      => $pcm_summary['current_period']['used'],
			'remaining' => $pcm_summary['current_period']['remaining'],
		) : null,
		'next_milestone' => $pcm_summary['next_milestone'],
	);
}

function pcm_crm_portal_rest_summary( WP_REST_Request $pcm_request ) {
	$pcm_summary = pcm_crm_pm_project_summary( pcm_crm_portal_requested_project( $pcm_request ) );

	if ( ! $pcm_summary ) {
		return new WP_Error( 'pcm_crm_portal_no_summary', __( 'Nothing to show yet.', 'pcm-crm' ), array( 'status' => 404 ) );
	}

	return rest_ensure_response( pcm_crm_portal_safe_summary( $pcm_summary ) );
}

function pcm_crm_portal_rest_raid( WP_REST_Request $pcm_request ) {
	$pcm_rows = pcm_crm_project_raid()->find( array(
		'filters'  => array( 'project_id' => pcm_crm_portal_requested_project( $pcm_request ) ),
		'orderby'  => 'severity',
		'order'    => 'DESC',
		'per_page' => 200,
	) );

	return rest_ensure_response( PCM_CRM_REST::expand( 'project_raid', $pcm_rows ) );
}

function pcm_crm_portal_rest_documents( WP_REST_Request $pcm_request ) {
	return rest_ensure_response( pcm_crm_pm_documents_for( pcm_crm_portal_requested_project( $pcm_request ) ) );
}

function pcm_crm_portal_rest_download( WP_REST_Request $pcm_request ) {
	pcm_crm_stream_document(
		absint( pcm_crm_project_documents()->get( absint( $pcm_request->get_url_params()['pcm_id'] ) )['attachment_id'] ),
		'inline' === $pcm_request->get_param( 'disposition' )
	);
}

function pcm_crm_portal_rest_list_tickets( WP_REST_Request $pcm_request ) {
	$pcm_context = pcm_crm_portal_context();

	$pcm_rows = pcm_crm_help_tickets()->find( array(
		'filters'  => array( 'project_id' => $pcm_context['project_ids'] ),
		'orderby'  => 'last_modified_date',
		'order'    => 'DESC',
		'per_page' => 200,
	) );

	return rest_ensure_response( PCM_CRM_REST::expand( 'help_ticket', $pcm_rows ) );
}

function pcm_crm_portal_rest_get_ticket( WP_REST_Request $pcm_request ) {
	$pcm_ticket = pcm_crm_help_tickets()->get( absint( $pcm_request->get_url_params()['pcm_id'] ) );

	return rest_ensure_response( PCM_CRM_REST::expand( 'help_ticket', array( $pcm_ticket ) )[0] );
}

/**
 * Create a ticket, with identity taken from the resolved context, never from
 * the body — a client can only ever file a ticket as themselves, on one of
 * their own projects. account_id follows automatically, in
 * pcm_crm_pm_apply_ticket().
 */
function pcm_crm_portal_rest_create_ticket( WP_REST_Request $pcm_request ) {
	$pcm_context = pcm_crm_portal_context();
	$pcm_body    = (array) $pcm_request->get_json_params();

	$pcm_id = pcm_crm_help_tickets()->insert( array(
		'project_id'  => pcm_crm_portal_requested_project( $pcm_request ),
		'contact_id'  => $pcm_context['contact_id'],
		'subject'     => isset( $pcm_body['subject'] ) ? (string) $pcm_body['subject'] : '',
		'description' => isset( $pcm_body['description'] ) ? (string) $pcm_body['description'] : '',
	) );

	if ( is_wp_error( $pcm_id ) ) {
		$pcm_id->add_data( array( 'status' => 400 ) );
		return $pcm_id;
	}

	return rest_ensure_response( pcm_crm_help_tickets()->get( $pcm_id ) );
}

/**
 * Any of the four statuses, at any time — a client moving a ticket back
 * ("actually this isn't fixed") is an ordinary action, not an exception the
 * server needs to police beyond the model's own validate() check that the
 * value is one of the four.
 */
function pcm_crm_portal_rest_update_status( WP_REST_Request $pcm_request ) {
	$pcm_id   = absint( $pcm_request->get_url_params()['pcm_id'] );
	$pcm_body = (array) $pcm_request->get_json_params();

	$pcm_result = pcm_crm_help_tickets()->update( $pcm_id, array(
		'status' => isset( $pcm_body['status'] ) ? (string) $pcm_body['status'] : '',
	) );

	if ( is_wp_error( $pcm_result ) ) {
		$pcm_result->add_data( array( 'status' => 400 ) );
		return $pcm_result;
	}

	return rest_ensure_response( pcm_crm_help_tickets()->get( $pcm_id ) );
}

function pcm_crm_portal_rest_list_attachments( WP_REST_Request $pcm_request ) {
	$pcm_id = absint( $pcm_request->get_url_params()['pcm_id'] );

	return rest_ensure_response( pcm_crm_pm_attachments_for_ticket( $pcm_id ) );
}

/**
 * A client attaching a screenshot or a document to their own ticket — the
 * portal's twin of pcm_crm_pm_rest_create_attachment() in
 * pm-tickets-rest.php, permission_callback already having confirmed the
 * ticket is one of the caller's own.
 */
function pcm_crm_portal_rest_create_attachment( WP_REST_Request $pcm_request ) {
	$pcm_id     = absint( $pcm_request->get_url_params()['pcm_id'] );
	$pcm_ticket = pcm_crm_help_tickets()->get( $pcm_id );

	return pcm_crm_pm_handle_ticket_attachment_upload( $pcm_ticket );
}

function pcm_crm_portal_rest_list_comments( WP_REST_Request $pcm_request ) {
	$pcm_id = absint( $pcm_request->get_url_params()['pcm_id'] );

	return rest_ensure_response( pcm_crm_pm_decorate_comments( pcm_crm_pm_ticket_thread( $pcm_id ) ) );
}

/**
 * is_client_comment is stamped automatically by pcm_crm_pm_stamp_comment()
 * from the acting user's role — the portal user genuinely holds pcm_client,
 * so nothing here needs to force it.
 */
function pcm_crm_portal_rest_create_comment( WP_REST_Request $pcm_request ) {
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
