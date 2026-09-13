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
	 *
	 * Resolved from the object registry, in registration order — the routes
	 * build their slug alternation from these keys.
	 *
	 * Memoised, and not only for tidiness: model() is called once per custom
	 * relationship field per row by expand_custom_relationships(), and every
	 * accessor merges a custom-field map, which is a get_option. Rebuilding
	 * the whole set for each of those was quietly the most repeated work in a
	 * list request.
	 */
	public static function models() {
		static $pcm_models = null;
		static $pcm_built_from = -1;

		$pcm_registered = count( pcm_crm_objects() );

		// Keyed on how many objects were registered when the memo was built, so a
		// module registering after something has already asked for the models
		// cannot be left out of them. Modules load last in the bootstrap and
		// nothing calls this before that finishes, so in practice it is built
		// once — but a stale memo here would be a silently missing REST route,
		// which is not a failure worth risking to save a count().
		if ( null === $pcm_models || $pcm_built_from !== $pcm_registered ) {
			$pcm_models     = array();
			$pcm_built_from = $pcm_registered;

			foreach ( pcm_crm_objects() as $pcm_slug => $pcm_object ) {
				$pcm_model = pcm_crm_object_model( $pcm_slug );

				if ( $pcm_model ) {
					$pcm_models[ $pcm_slug ] = $pcm_model;
				}
			}
		}

		return $pcm_models;
	}

	public static function model( $pcm_slug ) {
		$pcm_models = self::models();

		return isset( $pcm_models[ $pcm_slug ] ) ? $pcm_models[ $pcm_slug ] : null;
	}

	public static function permission() {
		return pcm_crm_user_can();
	}

	/**
	 * The object and id a route captured.
	 *
	 * Read from the URL parameters specifically, never through the request's
	 * array access. WordPress merges the JSON body *ahead* of URL parameters,
	 * so a record with a field of the same name shadows the route's own —
	 * which is exactly what happened: creating a schedule sent an `object`
	 * field of its own, and the collection route stopped knowing it was
	 * looking at schedules. The captures are prefixed for the same reason.
	 */
	protected static function route_object( WP_REST_Request $pcm_request ) {
		$pcm_url = $pcm_request->get_url_params();

		return isset( $pcm_url['pcm_object'] ) ? (string) $pcm_url['pcm_object'] : '';
	}

	protected static function route_id( WP_REST_Request $pcm_request ) {
		$pcm_url = $pcm_request->get_url_params();

		return isset( $pcm_url['pcm_id'] ) ? (int) $pcm_url['pcm_id'] : 0;
	}

	public static function register_routes() {
		$pcm_slugs = implode( '|', array_keys( self::models() ) );

		register_rest_route( self::NS, '/(?P<pcm_object>' . $pcm_slugs . ')', array(
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

		register_rest_route( self::NS, '/(?P<pcm_object>' . $pcm_slugs . ')/(?P<pcm_id>\d+)', array(
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

		// The recycle bin.
		register_rest_route( self::NS, '/recycle-bin', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'recycle_bin' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'empty_whole_bin' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			),
		) );

		register_rest_route( self::NS, '/(?P<pcm_object>' . $pcm_slugs . ')/(?P<pcm_id>\d+)/restore', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'restore_item' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );

		register_rest_route( self::NS, '/(?P<pcm_object>' . $pcm_slugs . ')/(?P<pcm_id>\d+)/purge', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'purge_item' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );

		register_rest_route( self::NS, '/(?P<pcm_object>' . $pcm_slugs . ')/empty-bin', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'empty_bin' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
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

		register_rest_route( self::NS, '/schedules/(?P<pcm_id>\d+)/send', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'send_schedule' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );

		// Outreach from a contact record: send one now, start a sequence, or
		// stop one because they replied.
		register_rest_route( self::NS, '/contacts/(?P<pcm_id>\d+)/email', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'send_contact_email' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );

		register_rest_route( self::NS, '/contacts/(?P<pcm_id>\d+)/enroll', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'enroll_contact' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );

		register_rest_route( self::NS, '/enrollments/(?P<pcm_id>\d+)/stop', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'stop_enrollment' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );

		register_rest_route( self::NS, '/report', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'report' ),
			'permission_callback' => array( __CLASS__, 'permission' ),
		) );

		// Related lists for a record's detail drawer, in one round trip.
		$pcm_related_slugs = implode( '|', array_map( 'preg_quote', array_keys( pcm_crm_related_providers() ) ) );

		// Guarded, because an implode of nothing gives an empty alternation —
		// '(?P<pcm_object>)' matches the empty string and the route would answer
		// for every object rather than none.
		if ( '' !== $pcm_related_slugs ) {
			register_rest_route( self::NS, '/related/(?P<pcm_object>' . $pcm_related_slugs . ')/(?P<pcm_id>\d+)', array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'related' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			) );
		}
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
			// The recycle bin is the only caller that wants deleted rows, and
			// it has to ask: every other list would be wrong to show them.
			'include_deleted' => (bool) $pcm_request->get_param( 'include_deleted' ),
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
		$pcm_model = self::model( self::route_object( $pcm_request ) );

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
		$pcm_model = self::model( self::route_object( $pcm_request ) );
		$pcm_item  = $pcm_model ? $pcm_model->get( self::route_id( $pcm_request ) ) : null;

		if ( ! $pcm_item ) {
			return new WP_Error( 'pcm_crm_not_found', __( 'Record not found.', 'pcm-crm' ), array( 'status' => 404 ) );
		}

		$pcm_expanded = self::expand( $pcm_model->object(), array( $pcm_item ) );

		return rest_ensure_response( $pcm_expanded[0] );
	}

	public static function create_item( WP_REST_Request $pcm_request ) {
		$pcm_model = self::model( self::route_object( $pcm_request ) );

		if ( ! $pcm_model ) {
			return new WP_Error( 'pcm_crm_unknown_object', __( 'Unknown object.', 'pcm-crm' ), array( 'status' => 404 ) );
		}

		$pcm_id = $pcm_model->insert( (array) $pcm_request->get_json_params() );

		if ( is_wp_error( $pcm_id ) ) {
			return $pcm_id;
		}

		$pcm_row = $pcm_model->get( $pcm_id );

		if ( ! $pcm_row ) {
			return new WP_Error( 'pcm_crm_not_found', __( 'The record was saved but could not be read back.', 'pcm-crm' ), array( 'status' => 500 ) );
		}

		// Indexed rather than assumed: expand() now ends in a filter, so a
		// listener returning the wrong shape would otherwise surface as an
		// undefined offset rather than as its own mistake.
		$pcm_expanded = self::expand( $pcm_model->object(), array( $pcm_row ) );

		return rest_ensure_response( $pcm_expanded ? reset( $pcm_expanded ) : $pcm_row );
	}

	public static function update_item( WP_REST_Request $pcm_request ) {
		$pcm_model = self::model( self::route_object( $pcm_request ) );

		if ( ! $pcm_model ) {
			return new WP_Error( 'pcm_crm_unknown_object', __( 'Unknown object.', 'pcm-crm' ), array( 'status' => 404 ) );
		}

		$pcm_result = $pcm_model->update( self::route_id( $pcm_request ), (array) $pcm_request->get_json_params() );

		if ( is_wp_error( $pcm_result ) ) {
			return $pcm_result;
		}

		$pcm_expanded = self::expand( $pcm_model->object(), array( $pcm_model->get( $pcm_result ) ) );

		return rest_ensure_response( $pcm_expanded[0] );
	}

	public static function delete_item( WP_REST_Request $pcm_request ) {
		$pcm_model = self::model( self::route_object( $pcm_request ) );

		if ( ! $pcm_model ) {
			return new WP_Error( 'pcm_crm_unknown_object', __( 'Unknown object.', 'pcm-crm' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( array( 'deleted' => $pcm_model->delete( self::route_id( $pcm_request ) ) ) );
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
	public static function expand( $pcm_object, array $pcm_items ) {
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
			// A row can be missing — a record deleted between the write and the
			// read back, most plausibly — and expanding null should skip it
			// rather than take the whole response down.
			if ( ! is_array( $pcm_item ) ) {
				unset( $pcm_items[ $pcm_i ] );
				continue;
			}

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
				$pcm_days = pcm_crm_days_between(
					isset( $pcm_item['stage_entered_date'] ) ? $pcm_item['stage_entered_date'] : '',
					current_time( 'mysql' )
				);

				$pcm_items[ $pcm_i ]['_days_in_stage'] = $pcm_days;
				$pcm_items[ $pcm_i ]['_is_stalled']    = ( empty( $pcm_item['is_closed'] ) && $pcm_days >= pcm_crm_stall_days() ) ? 1 : 0;
			}

			if ( isset( $pcm_item['owner_id'] ) ) {
				$pcm_items[ $pcm_i ]['_owner_name'] = pcm_crm_user_name( $pcm_item['owner_id'] );
			}

			$pcm_items[ $pcm_i ] = self::expand_custom_relationships( $pcm_object, $pcm_items[ $pcm_i ] );

			// For the System Information panel. Resolved here rather than in
			// the browser because only the server can see the user table.
			if ( isset( $pcm_item['created_by_id'] ) ) {
				$pcm_items[ $pcm_i ]['_created_by_name'] = pcm_crm_user_name( $pcm_item['created_by_id'] );
			}

			if ( isset( $pcm_item['last_modified_by_id'] ) ) {
				$pcm_items[ $pcm_i ]['_modified_by_name'] = pcm_crm_user_name( $pcm_item['last_modified_by_id'] );
			}
		}

		// A whole page at once, deliberately, so a listener can answer with one
		// grouped query rather than one query per row. Anything added here is
		// underscore-prefixed by convention and so is dropped again by the
		// model's sanitiser on the way back in — a save cannot write one.
		return self::expand_lookups( $pcm_object, apply_filters( 'pcm_crm_expand_items', array_values( $pcm_items ), $pcm_object ) );
	}

	/**
	 * A plural slug for a model's singular object name.
	 *
	 * Appending an s is wrong for half the objects here — opportunity, activity,
	 * time_entry — so the answer comes from the models themselves.
	 */
	public static function slug_for( $pcm_object ) {
		foreach ( self::models() as $pcm_slug => $pcm_model ) {
			if ( $pcm_model->object() === $pcm_object ) {
				return $pcm_slug;
			}
		}

		return '';
	}

	/**
	 * A name for every lookup on every row, as _<column>_name.
	 *
	 * The record page renders any lookup as a link with the record's name, so it
	 * needs one predictable key per column rather than the handful of aliases
	 * that grew up per object (_account_name, _contact_name). Those are reused
	 * where they exist, and everything else is resolved in one query per target
	 * object for the whole page.
	 */
	protected static function expand_lookups( $pcm_object, array $pcm_items ) {
		$pcm_model = self::model( self::slug_for( $pcm_object ) );

		if ( ! $pcm_model || ! $pcm_items ) {
			return $pcm_items;
		}

		$pcm_aliases = array(
			'account_id'         => '_account_name',
			'primary_contact_id' => '_contact_name',
			'who_id'             => '_contact_name',
			'opportunity_id'     => '_opportunity_name',
			'project_id'         => '_project_name',
			'owner_contact_id'   => '_owner_contact_name',
		);

		$pcm_lookups = array();

		foreach ( $pcm_model->fields() as $pcm_column => $pcm_def ) {
			if ( ! empty( $pcm_def['lookup'] ) ) {
				$pcm_lookups[ $pcm_column ] = $pcm_def['lookup'];
			}
		}

		// First pass: reuse an alias, or note the id to resolve.
		$pcm_wanted = array();
		$pcm_target = function ( $pcm_item, $pcm_column, $pcm_lookup ) {
			if ( 'polymorphic' !== $pcm_lookup ) {
				return $pcm_lookup;
			}

			$pcm_type = isset( $pcm_item['what_type'] ) ? (string) $pcm_item['what_type'] : '';

			return '' === $pcm_type ? '' : self::slug_for( $pcm_type );
		};

		foreach ( $pcm_items as $pcm_i => $pcm_item ) {
			foreach ( $pcm_lookups as $pcm_column => $pcm_lookup ) {
				$pcm_key = '_' . $pcm_column . '_name';

				if ( isset( $pcm_item[ $pcm_key ] ) ) {
					continue;
				}

				if ( isset( $pcm_aliases[ $pcm_column ] ) && ! empty( $pcm_item[ $pcm_aliases[ $pcm_column ] ] ) ) {
					$pcm_items[ $pcm_i ][ $pcm_key ] = $pcm_item[ $pcm_aliases[ $pcm_column ] ];
					continue;
				}

				$pcm_id   = isset( $pcm_item[ $pcm_column ] ) ? (int) $pcm_item[ $pcm_column ] : 0;
				$pcm_slug = $pcm_target( $pcm_item, $pcm_column, $pcm_lookup );

				if ( $pcm_id && $pcm_slug ) {
					$pcm_wanted[ $pcm_slug ][ $pcm_id ] = true;
				}
			}
		}

		$pcm_labels = array();

		foreach ( $pcm_wanted as $pcm_slug => $pcm_ids ) {
			$pcm_parent = self::model( $pcm_slug );

			if ( ! $pcm_parent ) {
				continue;
			}

			foreach ( $pcm_parent->get_many( array_keys( $pcm_ids ) ) as $pcm_id => $pcm_row ) {
				$pcm_labels[ $pcm_slug ][ $pcm_id ] = pcm_crm_record_label( $pcm_slug, $pcm_row );
			}
		}

		// Second pass: fill what was resolved. A missing parent reads as empty
		// rather than as a notice, the way the aliases already do.
		foreach ( $pcm_items as $pcm_i => $pcm_item ) {
			foreach ( $pcm_lookups as $pcm_column => $pcm_lookup ) {
				$pcm_key = '_' . $pcm_column . '_name';

				if ( isset( $pcm_items[ $pcm_i ][ $pcm_key ] ) ) {
					continue;
				}

				$pcm_id   = isset( $pcm_item[ $pcm_column ] ) ? (int) $pcm_item[ $pcm_column ] : 0;
				$pcm_slug = $pcm_target( $pcm_item, $pcm_column, $pcm_lookup );

				$pcm_items[ $pcm_i ][ $pcm_key ] = ( $pcm_id && isset( $pcm_labels[ $pcm_slug ][ $pcm_id ] ) )
					? $pcm_labels[ $pcm_slug ][ $pcm_id ] : '';
			}
		}

		return $pcm_items;
	}

	/**
	 * Resolve a custom relationship column to the record's name.
	 *
	 * Without this a relationship field shows the raw id in a list, which is
	 * the one thing a lookup exists to avoid. Cached per request, so a page of
	 * rows pointing at the same handful of records is one query, not one each.
	 */
	protected static function expand_custom_relationships( $pcm_object, array $pcm_item ) {
		static $pcm_cache = array();

		$pcm_slug = self::slug_for( $pcm_object );

		foreach ( pcm_crm_custom_fields( $pcm_slug ) as $pcm_field ) {
			if ( 'relationship' !== $pcm_field['type'] || empty( $pcm_field['related'] ) ) {
				continue;
			}

			$pcm_column = pcm_crm_custom_column( $pcm_field['key'] );
			$pcm_id     = isset( $pcm_item[ $pcm_column ] ) ? (int) $pcm_item[ $pcm_column ] : 0;

			if ( ! $pcm_id ) {
				continue;
			}

			$pcm_key = $pcm_field['related'] . ':' . $pcm_id;

			if ( ! isset( $pcm_cache[ $pcm_key ] ) ) {
				$pcm_model = self::model( $pcm_field['related'] );
				$pcm_row   = $pcm_model ? $pcm_model->get( $pcm_id ) : null;

				$pcm_cache[ $pcm_key ] = $pcm_row ? pcm_crm_record_label( $pcm_field['related'], $pcm_row ) : '';
			}

			$pcm_item[ '_' . $pcm_column . '_name' ] = $pcm_cache[ $pcm_key ];
		}

		return $pcm_item;
	}

	public static function related( WP_REST_Request $pcm_request ) {
		return rest_ensure_response( pcm_crm_related_for(
			self::route_object( $pcm_request ),
			self::route_id( $pcm_request )
		) );
	}

	/* -----------------------------------------------------------------------
	   App data
	   ----------------------------------------------------------------------- */

	/**
	 * How much is in the bin, per object.
	 */
	public static function recycle_bin( WP_REST_Request $pcm_request ) {
		$pcm_out = array();

		foreach ( pcm_crm_recyclable_objects() as $pcm_slug => $pcm_label ) {
			$pcm_model = self::model( $pcm_slug );

			$pcm_out[] = array(
				'object' => $pcm_slug,
				'label'  => $pcm_label,
				'count'  => $pcm_model ? $pcm_model->count( array(
					'filters'         => array( 'is_deleted' => 1 ),
					'include_deleted' => true,
				) ) : 0,
			);
		}

		return rest_ensure_response( $pcm_out );
	}

	/**
	 * Empty every object's bin at once.
	 *
	 * Opportunities go last, and deliberately: purging one removes its stage
	 * history, and doing them first would leave nothing for the later objects
	 * to be inconsistent with either way — but the order is worth keeping
	 * stable so the count reported back is reproducible.
	 */
	public static function empty_whole_bin( WP_REST_Request $pcm_request ) {
		$pcm_purged = 0;

		foreach ( array_keys( pcm_crm_recyclable_objects() ) as $pcm_slug ) {
			$pcm_model = self::model( $pcm_slug );

			if ( $pcm_model ) {
				$pcm_purged += (int) $pcm_model->purge_all();
			}
		}

		return rest_ensure_response( array( 'purged' => $pcm_purged ) );
	}

	public static function restore_item( WP_REST_Request $pcm_request ) {
		$pcm_model = self::model( self::route_object( $pcm_request ) );

		if ( ! $pcm_model ) {
			return new WP_Error( 'pcm_crm_unknown_object', __( 'Unknown object.', 'pcm-crm' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( array( 'restored' => $pcm_model->restore( self::route_id( $pcm_request ) ) ) );
	}

	public static function purge_item( WP_REST_Request $pcm_request ) {
		$pcm_model = self::model( self::route_object( $pcm_request ) );

		if ( ! $pcm_model ) {
			return new WP_Error( 'pcm_crm_unknown_object', __( 'Unknown object.', 'pcm-crm' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( array( 'purged' => $pcm_model->purge( self::route_id( $pcm_request ) ) ) );
	}

	public static function empty_bin( WP_REST_Request $pcm_request ) {
		$pcm_model = self::model( self::route_object( $pcm_request ) );

		if ( ! $pcm_model ) {
			return new WP_Error( 'pcm_crm_unknown_object', __( 'Unknown object.', 'pcm-crm' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( array( 'purged' => $pcm_model->purge_all() ) );
	}

	public static function bootstrap( WP_REST_Request $pcm_request ) {
		return rest_ensure_response( apply_filters( 'pcm_crm_bootstrap', array(
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
			'users'             => pcm_crm_user_directory(),
			'currency'          => pcm_crm_currency_symbol(),
			'stallDays'         => pcm_crm_stall_days(),
			'recyclable'        => pcm_crm_recyclable_objects(),
			'frequencies'       => pcm_crm_frequencies(),
			'emailVariables'    => pcm_crm_email_variables(),
			'templates'         => pcm_crm_template_choices(),
			'sequences'         => pcm_crm_sequence_choices(),
			'weekdays'          => pcm_crm_weekdays(),
			'objects'           => pcm_crm_object_directory(),
		) ) );
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
			// Only the record-shaped objects belong in the filter builder; the
			// rest are machinery rather than records anyone reports on. The
			// registry says which is which, so this is an allow-list now and a
			// new object is invisible here until it asks to be seen.
			if ( ! pcm_crm_object_is( $pcm_slug, 'reportable' ) ) {
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

			$pcm_object = pcm_crm_object( $pcm_slug );

			$pcm_group_options = $pcm_object ? (array) $pcm_object['group_options'] : array();

			$pcm_out[ $pcm_slug ] = array(
				'label'   => $pcm_model->object(),
				'fields'  => self::field_list( $pcm_model, '' ),
				'related' => $pcm_related,
				// Which columns the report screen offers to group by, and which
				// it opens on. Served from the registry rather than declared in
				// the JS, for the same reason the layout is: an object should
				// state this beside its own field map.
				'groupOptions' => $pcm_group_options,
				'groupBy'      => ( $pcm_object && $pcm_object['group_by'] )
					? $pcm_object['group_by']
					: ( $pcm_group_options ? reset( $pcm_group_options ) : '' ),
				// The record form is built from this rather than from a list
				// in the JS, which is what makes a layout editable at all.
				'layout'  => pcm_crm_layout( $pcm_slug ),
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

			// How to draw the control, where that differs from how the value
			// is stored: a currency and a plain number are both decimals.
			foreach ( array( 'ui', 'related', 'help', 'custom', 'lookup', 'lookup_filter' ) as $pcm_hint ) {
				if ( ! empty( $pcm_def[ $pcm_hint ] ) ) {
					$pcm_field[ $pcm_hint ] = $pcm_def[ $pcm_hint ];
				}
			}

			if ( ! empty( $pcm_def['options'] ) ) {
				// A built-in picklist names a function; a custom one carries
				// its values directly.
				$pcm_field['options'] = is_callable( $pcm_def['options'] )
					? self::normalize_options( call_user_func( $pcm_def['options'] ) )
					: self::normalize_options( $pcm_def['options'] );
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
		$pcm_schedule = pcm_crm_schedules()->get( self::route_id( $pcm_request ) );

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

	public static function send_contact_email( WP_REST_Request $pcm_request ) {
		$pcm_body = (array) $pcm_request->get_json_params();

		$pcm_result = pcm_crm_send_contact_email(
			self::route_id( $pcm_request ),
			isset( $pcm_body['subject'] ) ? $pcm_body['subject'] : '',
			isset( $pcm_body['body'] ) ? $pcm_body['body'] : '',
			array( 'opportunity_id' => isset( $pcm_body['opportunity_id'] ) ? (int) $pcm_body['opportunity_id'] : 0 )
		);

		if ( is_wp_error( $pcm_result ) ) {
			$pcm_result->add_data( array( 'status' => 400 ) );

			return $pcm_result;
		}

		return rest_ensure_response( $pcm_result );
	}

	public static function enroll_contact( WP_REST_Request $pcm_request ) {
		$pcm_body = (array) $pcm_request->get_json_params();

		$pcm_result = pcm_crm_enroll_contact(
			self::route_id( $pcm_request ),
			isset( $pcm_body['sequence_id'] ) ? (int) $pcm_body['sequence_id'] : 0,
			isset( $pcm_body['opportunity_id'] ) ? (int) $pcm_body['opportunity_id'] : 0
		);

		if ( is_wp_error( $pcm_result ) ) {
			$pcm_result->add_data( array( 'status' => 400 ) );

			return $pcm_result;
		}

		return rest_ensure_response( array( 'enrollment_id' => $pcm_result ) );
	}

	public static function stop_enrollment( WP_REST_Request $pcm_request ) {
		$pcm_body   = (array) $pcm_request->get_json_params();
		$pcm_reason = ! empty( $pcm_body['reason'] ) ? sanitize_text_field( $pcm_body['reason'] ) : __( 'Stopped by hand', 'pcm-crm' );

		pcm_crm_stop_enrollment( self::route_id( $pcm_request ), $pcm_reason );

		return rest_ensure_response( array( 'stopped' => true ) );
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

/**
 * Everyone who could be sent a report.
 *
 * Wider than the owner list on purpose: a report often goes to someone who
 * reads it and never touches the CRM. Capped, because a site with thousands of
 * subscribers should not ship them all to the browser — beyond that, the free
 * text field takes an address directly.
 */
function pcm_crm_user_directory() {
	$pcm_users = get_users( array(
		'number'  => (int) apply_filters( 'pcm_crm_user_directory_limit', 200 ),
		'orderby' => 'display_name',
		'order'   => 'ASC',
	) );

	$pcm_out = array();

	foreach ( $pcm_users as $pcm_user ) {
		if ( ! is_email( $pcm_user->user_email ) ) {
			continue;
		}

		$pcm_out[] = array(
			'id'    => (int) $pcm_user->ID,
			'name'  => pcm_crm_user_label( $pcm_user ),
			'email' => $pcm_user->user_email,
		);
	}

	return $pcm_out;
}

/**
 * Active templates, for the pickers.
 */
function pcm_crm_template_choices() {
	$pcm_out = array();

	foreach ( pcm_crm_templates()->find( array( 'filters' => array( 'is_active' => 1 ), 'orderby' => 'name', 'order' => 'ASC', 'per_page' => 200 ) ) as $pcm_template ) {
		$pcm_out[] = array(
			'id'      => (int) $pcm_template['id'],
			'name'    => $pcm_template['name'],
			'subject' => $pcm_template['subject'],
			'body'    => $pcm_template['body'],
		);
	}

	return $pcm_out;
}

function pcm_crm_sequence_choices() {
	$pcm_out = array();

	foreach ( pcm_crm_sequences()->find( array( 'filters' => array( 'is_active' => 1 ), 'orderby' => 'name', 'order' => 'ASC', 'per_page' => 200 ) ) as $pcm_sequence ) {
		$pcm_out[] = array(
			'id'    => (int) $pcm_sequence['id'],
			'name'  => $pcm_sequence['name'],
			'steps' => count( pcm_crm_sequence_steps( $pcm_sequence ) ),
		);
	}

	return $pcm_out;
}

/**
 * The objects whose deleted records the bin shows.
 *
 * The four Salesforce-shaped ones. Templates, sequences and schedules are
 * machinery — deleting one is a configuration change, not something to fish
 * back out of a bin.
 */
function pcm_crm_recyclable_objects() {
	$pcm_out = array();

	foreach ( pcm_crm_objects_where( 'recyclable' ) as $pcm_slug => $pcm_object ) {
		$pcm_out[ $pcm_slug ] = $pcm_object['plural'];
	}

	return $pcm_out;
}

function pcm_crm_currency_symbol() {
	return (string) apply_filters( 'pcm_crm_currency_symbol', '$' );
}
