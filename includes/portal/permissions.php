<?php
/**
 * Who a portal request is, and what they may see.
 *
 * The rest of this plugin has exactly one capability gate — pcm_crm_user_can()
 * — checked identically everywhere, with no idea of "this record belongs to
 * that person." A client login needs the opposite: every request has to be
 * checked against the handful of projects its own contact is actually a
 * client on. This file is the one place that question gets answered, so every
 * portal route asks it the same way rather than reinventing the check.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The logged-in client's identity and every project they may see, or a
 * WP_Error explaining why not.
 *
 * Not memoized: the checks are a couple of cheap, indexed lookups, and
 * memoizing across calls proved to be the wrong trade the moment more than
 * one "session" needs evaluating in the same PHP process — which a test
 * suite does constantly, and which a long-running request (a queue worker,
 * WP-CLI) is not so different from either.
 */
function pcm_crm_portal_context( $pcm_allow_no_project = false ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'pcm_crm_portal_login_required', __( 'Please log in.', 'pcm-crm' ), array( 'status' => 401 ) );
	}

	$pcm_user = wp_get_current_user();

	if ( ! in_array( 'pcm_client', (array) $pcm_user->roles, true ) ) {
		return new WP_Error( 'pcm_crm_portal_wrong_role', __( 'This area is for clients only.', 'pcm-crm' ), array( 'status' => 403 ) );
	}

	$pcm_contact_id = (int) get_user_meta( $pcm_user->ID, 'pcm_crm_contact_id', true );

	if ( ! $pcm_contact_id || ! pcm_crm_contacts()->get( $pcm_contact_id ) ) {
		return new WP_Error( 'pcm_crm_portal_unlinked', __( 'Your login is not linked to a contact. Ask whoever set this up to check your invitation.', 'pcm-crm' ), array( 'status' => 403 ) );
	}

	$pcm_project_ids = pcm_crm_portal_projects_for_contact( $pcm_contact_id );

	if ( ! $pcm_project_ids && ! $pcm_allow_no_project ) {
		return new WP_Error( 'pcm_crm_portal_no_project', __( 'No project is set up for you yet.', 'pcm-crm' ), array( 'status' => 404 ) );
	}

	return array(
		'user_id'     => $pcm_user->ID,
		'contact_id'  => $pcm_contact_id,
		'project_ids' => $pcm_project_ids,
	);
}

/**
 * Every project a contact is a client on, most recent first.
 *
 * A contact can hold a client role on more than one project at once — two
 * concurrent engagements on the same Account, say — and the portal shows all
 * of them, not one. There is deliberately no tie-break here the way an
 * earlier single-project design needed one.
 */
function pcm_crm_portal_projects_for_contact( $pcm_contact_id ) {
	$pcm_roles = pcm_crm_project_roles()->find( array(
		'filters'  => array( 'contact_id' => (int) $pcm_contact_id, 'party_type' => 'client' ),
		'orderby'  => 'created_date',
		'order'    => 'DESC',
		'per_page' => 100,
	) );

	$pcm_ids = array();

	foreach ( $pcm_roles as $pcm_role ) {
		$pcm_project_id = (int) $pcm_role['project_id'];

		if ( $pcm_project_id && ! in_array( $pcm_project_id, $pcm_ids, true ) ) {
			$pcm_ids[] = $pcm_project_id;
		}
	}

	return $pcm_ids;
}

/**
 * The generic permission_callback for a portal route that names no specific
 * project or resource — /portal/me, and the list routes that carry their own
 * project_id and check it themselves.
 */
function pcm_crm_portal_permission() {
	// The error itself, not false: false makes WordPress answer with its own
	// "Sorry, you are not allowed to do that.", which told a client nothing.
	$pcm_context = pcm_crm_portal_context();

	return is_wp_error( $pcm_context ) ? $pcm_context : true;
}

/**
 * /portal/me only: a client with no project yet is still a client, and the
 * portal needs to be able to say so rather than refuse them outright.
 */
function pcm_crm_portal_permission_me() {
	$pcm_context = pcm_crm_portal_context( true );

	return is_wp_error( $pcm_context ) ? $pcm_context : true;
}

/**
 * A project id read from the request, checked against the caller's own list.
 *
 * Reads the query param first (GET routes) and falls back to the JSON body
 * (POST routes creating a ticket) — never trusted until it has passed this
 * check, per route_object()/route_id()'s discipline in class-pcm-crm-rest.php
 * of never reading identity from a place a caller could shape freely.
 */
function pcm_crm_portal_requested_project( WP_REST_Request $pcm_request ) {
	$pcm_project_id = absint( $pcm_request->get_param( 'project_id' ) );

	if ( ! $pcm_project_id ) {
		$pcm_body       = (array) $pcm_request->get_json_params();
		$pcm_project_id = isset( $pcm_body['project_id'] ) ? absint( $pcm_body['project_id'] ) : 0;
	}

	return $pcm_project_id;
}

/**
 * permission_callback for a route naming a project directly — summary, RAID,
 * documents list, ticket create.
 */
function pcm_crm_portal_permission_project( WP_REST_Request $pcm_request ) {
	$pcm_context = pcm_crm_portal_context();

	if ( is_wp_error( $pcm_context ) ) {
		return $pcm_context;
	}

	$pcm_project_id = pcm_crm_portal_requested_project( $pcm_request );

	if ( ! $pcm_project_id || ! in_array( $pcm_project_id, $pcm_context['project_ids'], true ) ) {
		return new WP_Error( 'pcm_crm_portal_not_your_project', __( 'That is not one of your projects.', 'pcm-crm' ), array( 'status' => 403 ) );
	}

	return true;
}

/**
 * permission_callback for a route naming an existing ticket by id in the URL
 * — get, status, comments. Loads the ticket once and checks its project
 * against the caller's list, rather than trusting anything in the request.
 */
function pcm_crm_portal_permission_ticket( WP_REST_Request $pcm_request ) {
	$pcm_context = pcm_crm_portal_context();

	if ( is_wp_error( $pcm_context ) ) {
		return $pcm_context;
	}

	$pcm_ticket_id = absint( $pcm_request->get_url_params()['pcm_id'] );
	$pcm_ticket    = $pcm_ticket_id ? pcm_crm_help_tickets()->get( $pcm_ticket_id ) : null;

	if ( ! $pcm_ticket || ! in_array( (int) $pcm_ticket['project_id'], $pcm_context['project_ids'], true ) ) {
		return new WP_Error( 'pcm_crm_portal_not_your_ticket', __( 'That ticket is not yours to see.', 'pcm-crm' ), array( 'status' => 403 ) );
	}

	return true;
}

/**
 * permission_callback for a route naming an existing document by id in the
 * URL — the download route.
 */
function pcm_crm_portal_permission_document( WP_REST_Request $pcm_request ) {
	$pcm_context = pcm_crm_portal_context();

	if ( is_wp_error( $pcm_context ) ) {
		return $pcm_context;
	}

	$pcm_doc_id = absint( $pcm_request->get_url_params()['pcm_id'] );
	$pcm_doc    = $pcm_doc_id ? pcm_crm_project_documents()->get( $pcm_doc_id ) : null;

	if ( ! $pcm_doc || ! in_array( (int) $pcm_doc['project_id'], $pcm_context['project_ids'], true ) ) {
		return new WP_Error( 'pcm_crm_portal_not_your_document', __( 'That file is not yours to see.', 'pcm-crm' ), array( 'status' => 403 ) );
	}

	return true;
}
