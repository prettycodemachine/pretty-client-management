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

	register_rest_route( PCM_CRM_REST::NS, '/portal/milestones', array(
		'methods'             => 'GET',
		'callback'            => 'pcm_crm_portal_rest_milestones',
		'permission_callback' => 'pcm_crm_portal_permission_project',
	) );

	register_rest_route( PCM_CRM_REST::NS, '/portal/roles', array(
		'methods'             => 'GET',
		'callback'            => 'pcm_crm_portal_rest_roles',
		'permission_callback' => 'pcm_crm_portal_permission_project',
	) );

	register_rest_route( PCM_CRM_REST::NS, '/portal/documents', array(
		array(
			'methods'             => 'GET',
			'callback'            => 'pcm_crm_portal_rest_documents',
			'permission_callback' => 'pcm_crm_portal_permission_project',
		),
		array(
			'methods'             => 'POST',
			'callback'            => 'pcm_crm_portal_rest_create_document',
			'permission_callback' => 'pcm_crm_portal_permission_project',
		),
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
 * The identifying half of a project — what the portal's Summary card names
 * before any number is shown.
 *
 * Its own allow-list rather than a slice of the row, for the same reason
 * pcm_crm_portal_safe_summary() exists: the projects table also holds a
 * budget, two default rates and an internal health note, and a client reading
 * a project's name must not be the path by which any of those leak. A field
 * added to model-project.php later is therefore invisible here until someone
 * names it, which is the safe default.
 */
function pcm_crm_portal_safe_project( array $pcm_project ) {
	return array(
		'name'        => $pcm_project['name'],
		'type_label'  => pcm_crm_pm_type_label( $pcm_project['project_type'] ),
		'stage_name'  => $pcm_project['stage_name'],
		'start_date'  => $pcm_project['start_date'],
		'end_date'    => $pcm_project['end_date'],
	);
}

/**
 * What to call the current-period bar — "Hours Used This Month" for a
 * monthly retainer, "...This Quarter" for a quarterly one, and a generic
 * fallback for anything pcm_crm_pm_periods() does not name (an older row, or
 * a cadence added later). Never hardcoded to "Month": a quarterly retainer
 * showing its period as a month would be wrong on every project that isn't
 * one, not just imprecise.
 */
function pcm_crm_portal_period_label( array $pcm_project ) {
	$pcm_labels = array(
		'monthly'   => __( 'Hours Used This Month', 'pcm-crm' ),
		'quarterly' => __( 'Hours Used This Quarter', 'pcm-crm' ),
	);

	$pcm_period = isset( $pcm_project['retainer_period'] ) ? $pcm_project['retainer_period'] : '';

	return isset( $pcm_labels[ $pcm_period ] ) ? $pcm_labels[ $pcm_period ] : __( 'Hours Used This Period', 'pcm-crm' );
}

/**
 * The project summary, with everything dollar- or rate-valued stripped —
 * hours only. The raw admin route (pcm_crm_pm_project_summary(), which this
 * calls) is never handed to the browser directly for exactly this reason.
 *
 * The project row travels in as a second argument rather than being looked up
 * here, so this stays a pure projection of what it is handed — which is what
 * makes the "no rate, no dollar figure" promise testable without a database.
 */
function pcm_crm_portal_safe_summary( array $pcm_summary, array $pcm_project = array() ) {
	return array(
		'project'        => $pcm_project ? pcm_crm_portal_safe_project( $pcm_project ) : null,
		'type_label'     => $pcm_summary['type_label'],
		'logged_hours'   => $pcm_summary['logged_hours'],
		'billable_hours' => $pcm_summary['billable_hours'],
		'budget_hours'   => $pcm_summary['budget_hours'],
		'estimate_hours' => $pcm_summary['estimate_hours'],
		'current_period' => $pcm_summary['current_period'] ? array(
			'label'     => pcm_crm_portal_period_label( $pcm_project ),
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
	$pcm_project_id = pcm_crm_portal_requested_project( $pcm_request );
	$pcm_summary    = pcm_crm_pm_project_summary( $pcm_project_id );

	if ( ! $pcm_summary ) {
		return new WP_Error( 'pcm_crm_portal_no_summary', __( 'Nothing to show yet.', 'pcm-crm' ), array( 'status' => 404 ) );
	}

	$pcm_project = pcm_crm_projects()->get( $pcm_project_id );

	return rest_ensure_response( pcm_crm_portal_safe_summary( $pcm_summary, $pcm_project ? $pcm_project : array() ) );
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

/**
 * One milestone, projected down to what a client is told about it.
 *
 * A milestone is already a client-facing commitment by design (see
 * model-project-milestone.php, which exists precisely so a commitment is not
 * a filtered task query), so this is nearly the whole row — but it is still
 * written out field by field rather than handed over whole, so a field added
 * to the model later does not reach the portal unexamined.
 */
function pcm_crm_portal_safe_milestone( array $pcm_row ) {
	return array(
		'id'             => (int) $pcm_row['id'],
		'name'           => $pcm_row['name'],
		'status'         => $pcm_row['status'],
		'due_date'       => $pcm_row['due_date'],
		'completed_date' => $pcm_row['completed_date'],
		'description'    => $pcm_row['description'],
	);
}

/**
 * Due date ascending — the order a Gantt reads in, and the order the portal
 * draws. An undated milestone sorts last rather than first: MySQL puts NULL
 * and '0000-00-00' ahead of every real date, which would hand the chart a row
 * with nowhere to sit as its opening bar.
 */
function pcm_crm_portal_rest_milestones( WP_REST_Request $pcm_request ) {
	$pcm_rows = pcm_crm_project_milestones()->find( array(
		'filters'  => array( 'project_id' => pcm_crm_portal_requested_project( $pcm_request ) ),
		'orderby'  => 'due_date',
		'order'    => 'ASC',
		'per_page' => 200,
	) );

	$pcm_dated   = array();
	$pcm_undated = array();

	foreach ( $pcm_rows as $pcm_row ) {
		$pcm_safe = pcm_crm_portal_safe_milestone( $pcm_row );

		if ( $pcm_safe['due_date'] ) {
			$pcm_dated[] = $pcm_safe;
		} else {
			$pcm_undated[] = $pcm_safe;
		}
	}

	return rest_ensure_response( array_merge( $pcm_dated, $pcm_undated ) );
}

/**
 * Who is on the project, as a client may see it.
 *
 * bill_rate and cost_rate are the reason this is not
 * PCM_CRM_REST::expand( 'project_role', ... ): a role row carries both, and
 * the portal's whole rule is hours and names, never money. The Notes field is
 * dropped for the same reason at one remove — it is where staff record things
 * about a person, written with no expectation that the client would read them.
 *
 * The party's name is resolved here rather than through expand()'s lookups,
 * because the three party types name their party in three different columns:
 * an internal person is a WordPress user, a client or partner contact is a
 * CRM contact row, and a partner firm may be engaged before anyone there has
 * been named at all (see pcm_crm_pm_validate_role()).
 */
function pcm_crm_portal_role_party_name( array $pcm_row ) {
	if ( 'internal' === $pcm_row['party_type'] ) {
		// pcm_crm_user_label() — the same first/last-name-over-display_name
		// preference the owner column already uses (class-pcm-crm-rest.php):
		// display_name on this site is frequently just the login email, which
		// is not something to hand a client in place of the person's name.
		return $pcm_row['user_id'] ? pcm_crm_user_label( get_userdata( (int) $pcm_row['user_id'] ) ) : '';
	}

	if ( $pcm_row['contact_id'] ) {
		$pcm_contact = pcm_crm_contacts()->get( (int) $pcm_row['contact_id'] );

		if ( $pcm_contact ) {
			return pcm_crm_contact_name( $pcm_contact );
		}
	}

	return '';
}

function pcm_crm_portal_safe_role( array $pcm_row ) {
	$pcm_firm    = $pcm_row['partner_account_id'] ? pcm_crm_accounts()->get( (int) $pcm_row['partner_account_id'] ) : null;
	$pcm_parties = pcm_crm_pm_party_types();

	return array(
		'id'           => (int) $pcm_row['id'],
		'party_type'   => $pcm_row['party_type'],
		'party_label'  => isset( $pcm_parties[ $pcm_row['party_type'] ] ) ? $pcm_parties[ $pcm_row['party_type'] ] : $pcm_row['party_type'],
		'party_name'   => pcm_crm_portal_role_party_name( $pcm_row ),
		'organization' => $pcm_firm ? $pcm_firm['name'] : '',
		'role'         => $pcm_row['role'],
		'is_primary'   => ! empty( $pcm_row['is_primary'] ),
		'start_date'   => $pcm_row['start_date'],
		'end_date'     => $pcm_row['end_date'],
	);
}

/**
 * Primary roles first, then everyone else — a client scanning this wants the
 * person they escalate to at the top, not whoever happened to be added first.
 */
function pcm_crm_portal_rest_roles( WP_REST_Request $pcm_request ) {
	$pcm_rows = pcm_crm_project_roles()->find( array(
		'filters'  => array( 'project_id' => pcm_crm_portal_requested_project( $pcm_request ) ),
		'orderby'  => 'is_primary',
		'order'    => 'DESC',
		'per_page' => 200,
	) );

	$pcm_out = array();

	foreach ( $pcm_rows as $pcm_row ) {
		$pcm_out[] = pcm_crm_portal_safe_role( $pcm_row );
	}

	return rest_ensure_response( $pcm_out );
}

/**
 * A document as a client may see it: the file, the label a client set
 * ("what is this document?"), and who put it there and when — never a
 * ticket_id or an attachment_id pointing into the Media Library directly.
 *
 * created_by_id names a real WordPress user whichever side uploaded it — a
 * staff member on their own login, or the client on the pcm_client login
 * their own invite created (permissions.php) — so pcm_crm_user_label() (the
 * same first/last-name-over-display_name preference the owner column and
 * pcm_crm_portal_role_party_name() already use) resolves either one.
 */
function pcm_crm_portal_safe_document( array $pcm_row ) {
	$pcm_row   = pcm_crm_pm_decorate_document( $pcm_row );
	$pcm_owner = $pcm_row['created_by_id'] ? get_userdata( (int) $pcm_row['created_by_id'] ) : null;

	return array(
		'id'           => (int) $pcm_row['id'],
		'label'        => $pcm_row['label'],
		'_filename'    => $pcm_row['_filename'],
		'_filesize'    => $pcm_row['_filesize'],
		'_mime_type'   => $pcm_row['_mime_type'],
		'_missing'     => $pcm_row['_missing'],
		'uploaded_by'  => $pcm_owner ? pcm_crm_user_label( $pcm_owner ) : '',
		'created_date' => $pcm_row['created_date'],
	);
}

function pcm_crm_portal_rest_documents( WP_REST_Request $pcm_request ) {
	$pcm_rows = pcm_crm_pm_documents_for( pcm_crm_portal_requested_project( $pcm_request ) );

	return rest_ensure_response( array_map( 'pcm_crm_portal_safe_document', $pcm_rows ) );
}

/**
 * A client adding a file to their own project's library — the portal's twin
 * of pcm_crm_pm_handle_ticket_attachment_upload(), but a library document
 * (ticket_id left at 0) rather than a ticket attachment, and a label taken
 * from the client's own description rather than the filename, since asking
 * for one is the whole point of this route.
 */
function pcm_crm_portal_rest_create_document( WP_REST_Request $pcm_request ) {
	if ( empty( $_FILES['file'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- REST route, gated by its own permission_callback
		return new WP_Error( 'pcm_crm_portal_document_missing_file', __( 'Choose a file first.', 'pcm-crm' ), array( 'status' => 400 ) );
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	$pcm_attachment_id = media_handle_upload( 'file', 0 );

	if ( is_wp_error( $pcm_attachment_id ) ) {
		$pcm_attachment_id->add_data( array( 'status' => 400 ) );
		return $pcm_attachment_id;
	}

	$pcm_label = trim( (string) $pcm_request->get_param( 'label' ) );

	$pcm_new_id = pcm_crm_project_documents()->insert( array(
		'project_id'    => pcm_crm_portal_requested_project( $pcm_request ),
		'attachment_id' => $pcm_attachment_id,
		'label'         => '' !== $pcm_label ? $pcm_label : get_the_title( $pcm_attachment_id ),
	) );

	if ( is_wp_error( $pcm_new_id ) ) {
		// The file itself uploaded fine; only the CRM row failed to save.
		// Leaving an unattached Media Library row behind is a smaller problem
		// than losing the client's upload.
		$pcm_new_id->add_data( array( 'status' => 400 ) );
		return $pcm_new_id;
	}

	return rest_ensure_response( pcm_crm_portal_safe_document( pcm_crm_project_documents()->get( $pcm_new_id ) ) );
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
