<?php
/**
 * REST routes under pcm-crm/v1.
 *
 * The admin screens are rendered by JS against these routes rather than by
 * PHP, which is what lets a filter change repaint a list without a page load.
 * Every route is gated on the CRM capability and the standard wp_rest nonce.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class PCM_CRM_REST {

	const NS = 'pcm-crm/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * object slug => model, for the generic collection routes.
	 */
	public static function models() {
		return array(
			'accounts'      => pcm_crm_accounts(),
			'contacts'      => pcm_crm_contacts(),
			'opportunities' => pcm_crm_opportunities(),
			'activities'    => pcm_crm_activities(),
			'submissions'   => pcm_crm_submissions(),
			'schedules'     => pcm_crm_schedules(),
		);
	}

	public static function model( $pcm_slug ) {
		$pcm_models = self::models();

		return isset( $pcm_models[ $pcm_slug ] ) ? $pcm_models[ $pcm_slug ] : null;
	}

	public static function permission() {
		return pcm_crm_user_can();
	}

	public static function register_routes() {
		$pcm_slugs = implode( '|', array_keys( self::models() ) );

		register_rest_route( self::NS, '/(?P<object>' . $pcm_slugs . ')', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_items' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_item' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			),
		) );

		register_rest_route( self::NS, '/(?P<object>' . $pcm_slugs . ')/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_item' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			),
			array(
				'methods'             => 'POST, PUT, PATCH',
				'callback'            => array( __CLASS__, 'update_item' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_item' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			),
		) );

		// Everything the app needs to render pickers and picklists, in one
		// call at boot rather than a request per dropdown.
		register_rest_route( self::NS, '/bootstrap', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'bootstrap' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );

		// The filter builder's vocabulary: what can be filtered, on what, with
		// which operators. Served rather than duplicated in JS so the field
		// map stays the single source of truth for it.
		register_rest_route( self::NS, '/schema', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'schema' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );

		register_rest_route( self::NS, '/dashboard', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'dashboard' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );

		register_rest_route( self::NS, '/pipeline', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'pipeline' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );

		register_rest_route( self::NS, '/schedules/(?P<id>\d+)/send', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'send_schedule' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );

		register_rest_route( self::NS, '/report', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'report' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );

		// Related lists for a record's detail drawer, in one round trip.
		register_rest_route( self::NS, '/related/(?P<object>accounts|contacts|opportunities)/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'related' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );
	}

	/* -----------------------------------------------------------------------
	   Request parsing
	   ----------------------------------------------------------------------- */

	/**
	 * Turn query parameters into find() arguments.
	 *
	 * Filters arrive as filters[stage_name]=Proposal or, for ranges,
	 * filters[close_date][min]=2026-01-01. The model drops any column it does
	 * not recognise, so nothing here needs to whitelist them a second time.
	 */
	protected static function query_args( WP_REST_Request $pcm_request ) {
		$pcm_filters = $pcm_request->get_param( 'filters' );

		$pcm_args = array(
			'filters'  => is_array( $pcm_filters ) ? $pcm_filters : array(),
			'search'   => (string) $pcm_request->get_param( 'search' ),
			'page'     => max( 1, (int) $pcm_request->get_param( 'page' ) ),
			'per_page' => null === $pcm_request->get_param( 'per_page' ) ? 25 : (int) $pcm_request->get_param( 'per_page' ),
		);

		// Left out entirely when not given, so find() applies its own default
		// rather than being handed an empty string it would fall back from.
		if ( $pcm_request->get_param( 'orderby' ) ) {
			$pcm_args['orderby'] = (string) $pcm_request->get_param( 'orderby' );
			$pcm_args['order']   = (string) $pcm_request->get_param( 'order' );
		}

		return $pcm_args;
	}

	/* -----------------------------------------------------------------------
	   Collection
	   ----------------------------------------------------------------------- */

	public static function list_items( WP_REST_Request $pcm_request ) {
		$pcm_model = self::model( $pcm_request['object'] );

		if ( ! $pcm_model ) {
			return new WP_Error( 'pcm_crm_unknown_object', __( 'Unknown object.', 'pcm-crm' ), array( 'status' => 404 ) );
		}

		$pcm_args  = self::query_args( $pcm_request );
		$pcm_items = $pcm_model->find( $pcm_args );
		$pcm_total = $pcm_model->count( $pcm_args );

		return rest_ensure_response( array(
			'items' => self::expand( $pcm_model->object(), $pcm_items ),
			'total' => $pcm_total,
			'page'  => $pcm_args['page'],
		) );
	}

	public static function get_item( WP_REST_Request $pcm_request ) {
		$pcm_model = self::model( $pcm_request['object'] );
		$pcm_item  = $pcm_model ? $pcm_model->get( (int) $pcm_request['id'] ) : null;

		if ( ! $pcm_item ) {
			return new WP_Error( 'pcm_crm_not_found', __( 'Record not found.', 'pcm-crm' ), array( 'status' => 404 ) );
		}

		$pcm_expanded = self::expand( $pcm_model->object(), array( $pcm_item ) );

		return rest_ensure_response( $pcm_expanded[0] );
	}

	public static function create_item( WP_REST_Request $pcm_request ) {
		$pcm_model = self::model( $pcm_request['object'] );

		if ( ! $pcm_model ) {
			return new WP_Error( 'pcm_crm_unknown_object', __( 'Unknown object.', 'pcm-crm' ), array( 'status' => 404 ) );
		}

		$pcm_id = $pcm_model->insert( (array) $pcm_request->get_json_params() );

		if ( is_wp_error( $pcm_id ) ) {
			return $pcm_id;
		}

		$pcm_expanded = self::expand( $pcm_model->object(), array( $pcm_model->get( $pcm_id ) ) );

		return rest_ensure_response( $pcm_expanded[0] );
	}

	public static function update_item( WP_REST_Request $pcm_request ) {
		$pcm_model = self::model( $pcm_request['object'] );

		if ( ! $pcm_model ) {
			return new WP_Error( 'pcm_crm_unknown_object', __( 'Unknown object.', 'pcm-crm' ), array( 'status' => 404 ) );
		}

		$pcm_result = $pcm_model->update( (int) $pcm_request['id'], (array) $pcm_request->get_json_params() );

		if ( is_wp_error( $pcm_result ) ) {
			return $pcm_result;
		}

		$pcm_expanded = self::expand( $pcm_model->object(), array( $pcm_model->get( $pcm_result ) ) );

		return rest_ensure_response( $pcm_expanded[0] );
	}

	public static function delete_item( WP_REST_Request $pcm_request ) {
		$pcm_model = self::model( $pcm_request['object'] );

		if ( ! $pcm_model ) {
			return new WP_Error( 'pcm_crm_unknown_object', __( 'Unknown object.', 'pcm-crm' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( array( 'deleted' => $pcm_model->delete( (int) $pcm_request['id'] ) ) );
	}

	/* -----------------------------------------------------------------------
	   Display helpers
	   ----------------------------------------------------------------------- */

	/**
	 * Attach the display names behind each row's foreign keys.
	 *
	 * Resolved in one query per relationship for the whole page, rather than
	 * the row-at-a-time lookups a naive list view would do. The names are
	 * prefixed with an underscore to mark them as computed — the sanitiser
	 * drops them on the way back in, so a save cannot write one by accident.
	 */
	protected static function expand( $pcm_object, array $pcm_items ) {
		if ( ! $pcm_items ) {
			return array();
		}

		$pcm_accounts = array();
		$pcm_contacts = array();

		if ( in_array( $pcm_object, array( 'contact', 'opportunity' ), true ) ) {
			$pcm_accounts = pcm_crm_accounts()->get_many( wp_list_pluck( $pcm_items, 'account_id' ) );
		}

		if ( 'opportunity' === $pcm_object ) {
			$pcm_contacts = pcm_crm_contacts()->get_many( wp_list_pluck( $pcm_items, 'primary_contact_id' ) );
		}

		if ( 'activity' === $pcm_object ) {
			$pcm_contacts = pcm_crm_contacts()->get_many( wp_list_pluck( $pcm_items, 'who_id' ) );
		}

		foreach ( $pcm_items as $pcm_i => $pcm_item ) {
			if ( isset( $pcm_item['account_id'] ) ) {
				$pcm_items[ $pcm_i ]['_account_name'] = isset( $pcm_accounts[ $pcm_item['account_id'] ] )
					? $pcm_accounts[ $pcm_item['account_id'] ]['name'] : '';
			}

			if ( isset( $pcm_item['primary_contact_id'] ) && isset( $pcm_contacts[ $pcm_item['primary_contact_id'] ] ) ) {
				$pcm_items[ $pcm_i ]['_contact_name'] = pcm_crm_contact_name( $pcm_contacts[ $pcm_item['primary_contact_id'] ] );
			}

			if ( isset( $pcm_item['who_id'] ) && isset( $pcm_contacts[ $pcm_item['who_id'] ] ) ) {
				$pcm_items[ $pcm_i ]['_contact_name'] = pcm_crm_contact_name( $pcm_contacts[ $pcm_item['who_id'] ] );
			}

			// Days in stage is computed, not stored: it changes every midnight,
			// and a stored copy would be wrong for most of the day.
			if ( 'opportunity' === $pcm_object ) {
				$pcm_days = pcm_crm_days_between( $pcm_item['stage_entered_date'], current_time( 'mysql' ) );

				$pcm_items[ $pcm_i ]['_days_in_stage'] = $pcm_days;
				$pcm_items[ $pcm_i ]['_is_stalled']    = ( empty( $pcm_item['is_closed'] ) && $pcm_days >= pcm_crm_stall_days() ) ? 1 : 0;
			}

			if ( isset( $pcm_item['owner_id'] ) ) {
				$pcm_items[ $pcm_i ]['_owner_name'] = pcm_crm_user_name( $pcm_item['owner_id'] );
			}

			// For the System Information panel. Resolved here rather than in
			// the browser because only the server can see the user table.
			if ( isset( $pcm_item['created_by_id'] ) ) {
				$pcm_items[ $pcm_i ]['_created_by_name'] = pcm_crm_user_name( $pcm_item['created_by_id'] );
			}

			if ( isset( $pcm_item['last_modified_by_id'] ) ) {
				$pcm_items[ $pcm_i ]['_modified_by_name'] = pcm_crm_user_name( $pcm_item['last_modified_by_id'] );
			}
		}

		return $pcm_items;
	}

	public static function related( WP_REST_Request $pcm_request ) {
		$pcm_object = $pcm_request['object'];
		$pcm_id     = (int) $pcm_request['id'];
		$pcm_out    = array();

		if ( 'accounts' === $pcm_object ) {
			$pcm_out['contacts'] = self::expand( 'contact', pcm_crm_contacts()->find( array(
				'filters'  => array( 'account_id' => $pcm_id ),
				'orderby'  => 'last_name',
				'order'    => 'ASC',
				'per_page' => 100,
			) ) );

			$pcm_out['opportunities'] = self::expand( 'opportunity', pcm_crm_opportunities()->find( array(
				'filters'  => array( 'account_id' => $pcm_id ),
				'orderby'  => 'close_date',
				'order'    => 'ASC',
				'per_page' => 100,
			) ) );

			$pcm_out['activities'] = self::expand( 'activity', pcm_crm_activities_for( 'account', $pcm_id ) );
		}

		if ( 'contacts' === $pcm_object ) {
			$pcm_out['opportunities'] = self::expand( 'opportunity', pcm_crm_opportunities()->find( array(
				'filters'  => array( 'primary_contact_id' => $pcm_id ),
				'orderby'  => 'close_date',
				'order'    => 'ASC',
				'per_page' => 100,
			) ) );

			$pcm_out['activities'] = self::expand( 'activity', pcm_crm_activities_for( 'contact', $pcm_id ) );
		}

		if ( 'opportunities' === $pcm_object ) {
			$pcm_out['activities'] = self::expand( 'activity', pcm_crm_activities_for( 'opportunity', $pcm_id ) );
			$pcm_out['history']    = pcm_crm_stage_history_for( $pcm_id );
		}

		return rest_ensure_response( $pcm_out );
	}

	/* -----------------------------------------------------------------------
	   App data
	   ----------------------------------------------------------------------- */

	public static function bootstrap( WP_REST_Request $pcm_request ) {
		return rest_ensure_response( array(
			'stages'            => pcm_crm_stages(),
			'accountTypes'      => pcm_crm_account_types(),
			'industries'        => pcm_crm_industries(),
			'opportunityTypes'  => pcm_crm_opportunity_types(),
			'leadSources'       => pcm_crm_lead_sources(),
			'activityTypes'     => pcm_crm_activity_types(),
			'activityStatuses'  => pcm_crm_activity_statuses(),
			'priorities'        => pcm_crm_priorities(),
			'interests'         => pcm_crm_interest_options(),
			'owners'            => pcm_crm_owner_choices(),
			'currency'          => pcm_crm_currency_symbol(),
			'stallDays'         => pcm_crm_stall_days(),
			'frequencies'       => pcm_crm_frequencies(),
			'weekdays'          => pcm_crm_weekdays(),
		) );
	}

	/**
	 * Filterable fields for every object, plus the parents each can be
	 * filtered through.
	 *
	 * Grouped by object so the UI can say which table a field comes from,
	 * which is the whole point of letting one object filter on another's.
	 */
	public static function schema( WP_REST_Request $pcm_request ) {
		$pcm_out = array();

		foreach ( self::models() as $pcm_slug => $pcm_model ) {
			if ( in_array( $pcm_slug, array( 'submissions', 'schedules' ), true ) ) {
				continue;
			}

			$pcm_related = array();

			foreach ( $pcm_model->related() as $pcm_prefix => $pcm_link ) {
				$pcm_parent = call_user_func( $pcm_link['model'] );

				$pcm_related[] = array(
					'prefix' => $pcm_prefix,
					'label'  => $pcm_link['label'],
					'fields' => self::field_list( $pcm_parent, $pcm_prefix . '.' ),
				);
			}

			$pcm_out[ $pcm_slug ] = array(
				'label'   => $pcm_model->object(),
				'fields'  => self::field_list( $pcm_model, '' ),
				'related' => $pcm_related,
			);
		}

		return rest_ensure_response( $pcm_out );
	}

	/**
	 * One object's filterable fields, keys optionally prefixed so a parent's
	 * field arrives as 'account.industry'.
	 */
	protected static function field_list( PCM_CRM_Model $pcm_model, $pcm_prefix ) {
		$pcm_fields = array();

		foreach ( $pcm_model->fields() as $pcm_key => $pcm_def ) {
			if ( ! empty( $pcm_def['internal'] ) || empty( $pcm_def['label'] ) ) {
				continue;
			}

			$pcm_field = array(
				'key'   => $pcm_prefix . $pcm_key,
				'label' => $pcm_def['label'],
				'type'  => $pcm_def['type'],
			);

			if ( ! empty( $pcm_def['options'] ) && is_callable( $pcm_def['options'] ) ) {
				$pcm_field['options'] = self::normalize_options( call_user_func( $pcm_def['options'] ) );
			}

			$pcm_fields[] = $pcm_field;
		}

		return $pcm_fields;
	}

	/**
	 * Picklists come back either as a list of labels or as id/name pairs;
	 * the UI wants one shape.
	 */
	protected static function normalize_options( $pcm_options ) {
		$pcm_out = array();

		foreach ( (array) $pcm_options as $pcm_option ) {
			if ( is_array( $pcm_option ) && isset( $pcm_option['value'] ) ) {
				$pcm_out[] = $pcm_option;
			} else {
				$pcm_out[] = array( 'value' => (string) $pcm_option, 'label' => (string) $pcm_option );
			}
		}

		return $pcm_out;
	}

	public static function dashboard( WP_REST_Request $pcm_request ) {
		return rest_ensure_response( pcm_crm_dashboard_data( self::query_args( $pcm_request ) ) );
	}

	public static function pipeline( WP_REST_Request $pcm_request ) {
		$pcm_args             = self::query_args( $pcm_request );
		$pcm_args['per_page'] = 0;

		return rest_ensure_response( pcm_crm_pipeline_data( $pcm_args ) );
	}

	/**
	 * Send a schedule now.
	 *
	 * Waiting until Monday at eight to discover a recipient list is wrong is
	 * not a reasonable way to find out.
	 */
	public static function send_schedule( WP_REST_Request $pcm_request ) {
		$pcm_schedule = pcm_crm_schedules()->get( (int) $pcm_request['id'] );

		if ( ! $pcm_schedule ) {
			return new WP_Error( 'pcm_crm_not_found', __( 'That schedule no longer exists.', 'pcm-crm' ), array( 'status' => 404 ) );
		}

		try {
			$pcm_sent = pcm_crm_send_schedule( $pcm_schedule );
		} catch ( Exception $pcm_e ) {
			return new WP_Error( 'pcm_crm_send_failed', $pcm_e->getMessage(), array( 'status' => 400 ) );
		}

		return rest_ensure_response( array(
			'sent'       => (bool) $pcm_sent,
			'recipients' => pcm_crm_schedule_recipients( $pcm_schedule['recipients'] ),
		) );
	}

	public static function report( WP_REST_Request $pcm_request ) {
		return rest_ensure_response( pcm_crm_run_report(
			(string) $pcm_request->get_param( 'object' ),
			(string) $pcm_request->get_param( 'group_by' ),
			self::query_args( $pcm_request )
		) );
	}
}
PCM_CRM_REST::init();

/**
 * A person's name, for showing as a record's owner.
 *
 * First and last name from the user's profile, because display_name is
 * whatever WordPress was given at registration and is frequently the email
 * address — which is what this site has, and an address is not a name. Falls
 * back through display_name to the login, so an owner column is never blank.
 */
function pcm_crm_user_label( $pcm_user ) {
	if ( ! $pcm_user ) {
		return '';
	}

	$pcm_name = trim( $pcm_user->first_name . ' ' . $pcm_user->last_name );

	if ( '' !== $pcm_name ) {
		return $pcm_name;
	}

	// display_name is only worth using when it is not just the email again.
	if ( ! empty( $pcm_user->display_name ) && ! is_email( $pcm_user->display_name ) ) {
		return $pcm_user->display_name;
	}

	return $pcm_user->user_login;
}

/**
 * Owner name for an id, cached per request.
 *
 * A list page resolves the same handful of owners over and over; without the
 * cache that is one get_userdata() per row.
 */
function pcm_crm_user_name( $pcm_user_id ) {
	static $pcm_cache = array();

	$pcm_user_id = (int) $pcm_user_id;

	if ( ! $pcm_user_id ) {
		return '';
	}

	if ( ! isset( $pcm_cache[ $pcm_user_id ] ) ) {
		$pcm_cache[ $pcm_user_id ] = pcm_crm_user_label( get_userdata( $pcm_user_id ) );
	}

	return $pcm_cache[ $pcm_user_id ];
}

/**
 * Users who can own a record — everyone with the CRM capability.
 *
 * Full user objects rather than a field subset, since the name has to be
 * assembled from profile meta that a 'fields' list would not return.
 */
function pcm_crm_owner_choices() {
	$pcm_users = get_users( array( 'capability' => PCM_CRM_CAP ) );

	// A site whose administrators predate the capability would come back empty
	// and leave the owner filter unusable, so fall back to the admin role.
	if ( ! $pcm_users ) {
		$pcm_users = get_users( array( 'role' => 'administrator' ) );
	}

	$pcm_out = array();
	foreach ( $pcm_users as $pcm_user ) {
		$pcm_out[] = array( 'id' => (int) $pcm_user->ID, 'name' => pcm_crm_user_label( $pcm_user ) );
	}

	return $pcm_out;
}

/**
 * Owners as value/label pairs, for a picklist on an owner column.
 */
function pcm_crm_owner_options() {
	$pcm_out = array();

	foreach ( pcm_crm_owner_choices() as $pcm_owner ) {
		$pcm_out[] = array( 'value' => (string) $pcm_owner['id'], 'label' => $pcm_owner['name'] );
	}

	return $pcm_out;
}

function pcm_crm_currency_symbol() {
	return (string) apply_filters( 'pcm_crm_currency_symbol', '$' );
}
