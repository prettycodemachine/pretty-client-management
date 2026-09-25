<?php
/**
 * What the browser needs to draw the project screens.
 *
 * Gated, so a switched-off module puts nothing in the bootstrap payload and
 * leaves no project tokens in the template picker.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The module's picklists, added to the one bootstrap call the app already makes.
 *
 * On the existing payload rather than a second request: the app fetches
 * /bootstrap and /schema once at boot and a third round trip to learn three
 * lists would be the only thing standing between the page and its first paint.
 */
function pcm_crm_pm_bootstrap( $pcm_boot ) {
	$pcm_boot['projectTypes']    = pcm_crm_pm_project_type_options();
	$pcm_boot['projectStages']   = pcm_crm_pm_all_stage_names();
	$pcm_boot['projectHealth']   = pcm_crm_pm_health_options();
	$pcm_boot['raidTypes']       = pcm_crm_pm_raid_types();
	$pcm_boot['raidStatuses']    = pcm_crm_pm_raid_statuses();
	$pcm_boot['raidLevels']      = pcm_crm_pm_raid_levels();
	$pcm_boot['taskStatuses']    = pcm_crm_pm_task_statuses();
	$pcm_boot['milestoneStatuses'] = pcm_crm_pm_milestone_statuses();
	$pcm_boot['partyTypes']      = pcm_crm_pm_party_types();
	$pcm_boot['projectRoles']    = pcm_crm_pm_roles();
	$pcm_boot['retainerPeriods'] = pcm_crm_pm_periods();

	// Which stages belong to which type, so the record form can narrow the stage
	// picklist once a type is chosen. Sent as a map rather than fetched per type,
	// because it is three short lists and a request per keystroke is not.
	$pcm_boot['projectStageSets'] = pcm_crm_pm_stages();

	$pcm_boot['retainerTypes']      = pcm_crm_pm_retainer_types();

	// Everything a type decides — fields, stages, time rules, tab order — so a
	// project form and a time entry form can reshape themselves as the type is
	// known, and the New Project chooser can describe each one.
	$pcm_boot['projectTypeDefs'] = pcm_crm_pm_type_defs();
	$pcm_boot['archetypes']      = pcm_crm_pm_archetype_defs();
	$pcm_boot['timeSettings']    = pcm_crm_pm_time_settings();

	// The same boundary pcm_crm_pm_week_start() uses, so the timesheet's weeks
	// are the resourcing board's weeks.
	$pcm_boot['weekStartsOn'] = (int) get_option( 'start_of_week', 1 );

	$pcm_boot['ticketStatuses'] = pcm_crm_pm_ticket_statuses();

	return $pcm_boot;
}
add_filter( 'pcm_crm_bootstrap', 'pcm_crm_pm_bootstrap' );

/**
 * Projects hang off an account and off the opportunity they came from.
 *
 * Registered as an extra provider on each rather than by editing the function
 * that builds their other lists — which is the whole reason the related registry
 * exists.
 */
function pcm_crm_pm_related_projects_for_account( $pcm_id ) {
	return array(
		'projects' => PCM_CRM_REST::expand( 'project', pcm_crm_projects()->find( array(
			'filters'  => array( 'account_id' => $pcm_id ),
			'orderby'  => 'start_date',
			'order'    => 'DESC',
			'per_page' => 100,
		) ) ),
	);
}

function pcm_crm_pm_related_projects_for_opportunity( $pcm_id ) {
	return array(
		'projects' => PCM_CRM_REST::expand( 'project', pcm_crm_projects()->find( array(
			'filters'  => array( 'opportunity_id' => $pcm_id ),
			'orderby'  => 'start_date',
			'order'    => 'DESC',
			'per_page' => 100,
		) ) ),
	);
}

/**
 * Everything hanging off a project.
 */
function pcm_crm_pm_related_project( $pcm_id ) {
	return array(
		'tasks'      => PCM_CRM_REST::expand( 'project_task', pcm_crm_project_tasks()->find( array(
			'filters'  => array( 'project_id' => $pcm_id ),
			'orderby'  => 'due_date',
			'order'    => 'ASC',
			'per_page' => 200,
		) ) ),
		'raid'       => PCM_CRM_REST::expand( 'project_raid', pcm_crm_project_raid()->find( array(
			'filters'  => array( 'project_id' => $pcm_id ),
			// Worst first: a RAID log read top-down should open on what matters.
			'orderby'  => 'severity',
			'order'    => 'DESC',
			'per_page' => 200,
		) ) ),
		'milestones' => PCM_CRM_REST::expand( 'project_milestone', pcm_crm_project_milestones()->find( array(
			'filters'  => array( 'project_id' => $pcm_id ),
			'orderby'  => 'due_date',
			'order'    => 'ASC',
			'per_page' => 100,
		) ) ),
		'roles'      => PCM_CRM_REST::expand( 'project_role', pcm_crm_project_roles()->find( array(
			'filters'  => array( 'project_id' => $pcm_id ),
			'orderby'  => 'party_type',
			'order'    => 'ASC',
			'per_page' => 100,
		) ) ),
		'time'       => PCM_CRM_REST::expand( 'time_entry', pcm_crm_time_entries()->find( array(
			'filters'  => array( 'project_id' => $pcm_id ),
			'orderby'  => 'entry_date',
			'order'    => 'DESC',
			'per_page' => 100,
		) ) ),
		'activities' => PCM_CRM_REST::expand( 'activity', pcm_crm_activities_for( 'project', $pcm_id ) ),
		'tickets'    => PCM_CRM_REST::expand( 'help_ticket', pcm_crm_help_tickets()->find( array(
			'filters'  => array( 'project_id' => $pcm_id ),
			'orderby'  => 'last_modified_date',
			'order'    => 'DESC',
			'per_page' => 200,
		) ) ),
		// Not run through expand() — a document has nothing worth resolving
		// against another CRM table, only against the Media Library, which is
		// what pcm_crm_pm_documents_for() already does.
		'documents'  => pcm_crm_pm_documents_for( $pcm_id ),
	);
}

/**
 * A project's Help Tickets, from its Account's or its Contact's own record
 * page — registered as their own providers, not folded into core's
 * pcm_crm_related_account()/pcm_crm_related_contact(), for the same reason
 * pcm_crm_pm_related_projects_for_account() already stands apart from them: a
 * module adding a list to a core object must not need to edit core's file.
 */
function pcm_crm_pm_related_tickets_for_account( $pcm_id ) {
	return array(
		'tickets' => PCM_CRM_REST::expand( 'help_ticket', pcm_crm_help_tickets()->find( array(
			'filters'  => array( 'account_id' => $pcm_id ),
			'orderby'  => 'last_modified_date',
			'order'    => 'DESC',
			'per_page' => 200,
		) ) ),
	);
}

function pcm_crm_pm_related_tickets_for_contact( $pcm_id ) {
	return array(
		'tickets' => PCM_CRM_REST::expand( 'help_ticket', pcm_crm_help_tickets()->find( array(
			'filters'  => array( 'contact_id' => $pcm_id ),
			'orderby'  => 'last_modified_date',
			'order'    => 'DESC',
			'per_page' => 200,
		) ) ),
	);
}

/**
 * Every project a contact holds a role on, from the contact's record page —
 * which is also the list that decides what they see in the Client Portal.
 * Keyed contact_roles, not roles: a project's own Project Role tab lists
 * people, this one lists projects, and they carry different columns.
 */
function pcm_crm_pm_related_roles_for_contact( $pcm_id ) {
	return array(
		'contact_roles' => PCM_CRM_REST::expand( 'project_role', pcm_crm_project_roles()->find( array(
			'filters'  => array( 'contact_id' => $pcm_id ),
			'orderby'  => 'start_date',
			'order'    => 'DESC',
			'per_page' => 200,
		) ) ),
	);
}

pcm_crm_register_related( 'accounts', 'pcm_crm_pm_related_projects_for_account' );
pcm_crm_register_related( 'accounts', 'pcm_crm_pm_related_tickets_for_account' );
pcm_crm_register_related( 'opportunities', 'pcm_crm_pm_related_projects_for_opportunity' );
pcm_crm_register_related( 'contacts', 'pcm_crm_pm_related_tickets_for_contact' );
pcm_crm_register_related( 'contacts', 'pcm_crm_pm_related_roles_for_contact' );
pcm_crm_register_related( 'projects', 'pcm_crm_pm_related_project' );

// Activities already reach a project without any registration: what_type is a
// free-text column with no whitelist behind it, and the composite index is
// (what_type,what_id), so 'project' needs nothing added here. What it does still
// need is a way to *pick* a project in the Related To control, which is in the
// browser rather than here.

/**
 * The record forms.
 *
 * Supplied through the layout filter rather than added to
 * pcm_crm_default_layouts(), which is core's list — a module should not have to
 * edit it to describe its own objects. An admin can still rearrange these on the
 * Fields & Layouts tab, and a saved arrangement wins — not decided here:
 * pcm_crm_layout_sections() only reaches this filter (via
 * pcm_crm_layout_default()) once it has already checked both the per-variant
 * and the base saved options and found neither, so this callback can simply
 * answer for the objects it knows about without checking for itself whether
 * something was saved.
 */
function pcm_crm_pm_layout( $pcm_layout, $pcm_object, $pcm_variant = '' ) {
	$pcm_layouts = array(
		'projects' => array(
			array( 'title' => '', 'fields' => array( 'name', 'project_code', 'account_id', 'opportunity_id', 'owner_id', 'project_type', 'stage_name' ) ),
			array( 'title' => 'Health', 'fields' => array( 'health', 'health_note' ) ),
			array( 'title' => 'Timeline', 'fields' => array( 'start_date', 'end_date', 'actual_end_date' ) ),
			array( 'title' => 'Budget', 'fields' => array( 'budget_amount', 'budget_hours', 'default_bill_rate', 'default_cost_rate' ) ),
			// Hidden by the form until the type is a retainer — see the
			// showWhen hints in pm.js. Grouped anyway, so the section reads as
			// a unit when it does appear.
			array( 'title' => 'Retainer', 'fields' => array( 'retainer_hours', 'retainer_period', 'retainer_start_date', 'retainer_rollover', 'retainer_rollover_cap' ) ),
			array( 'title' => 'Notes', 'fields' => array( 'description' ) ),
		),
		'project_tasks' => array(
			array( 'title' => '', 'fields' => array( 'name', 'project_id', 'assignee_user_id', 'status' ) ),
			array( 'title' => 'Dates', 'fields' => array( 'start_date', 'due_date', 'estimated_hours' ) ),
			array( 'title' => 'Notes', 'fields' => array( 'description' ) ),
		),
		'project_milestones' => array(
			array( 'title' => '', 'fields' => array( 'name', 'project_id', 'status', 'due_date' ) ),
			array( 'title' => 'Notes', 'fields' => array( 'description' ) ),
		),
		'project_raid' => array(
			array( 'title' => '', 'fields' => array( 'title', 'project_id', 'raid_type', 'status', 'owner_id' ) ),
			array( 'title' => 'Score', 'fields' => array( 'probability', 'impact' ) ),
			array( 'title' => 'Dates', 'fields' => array( 'raised_date', 'due_date' ) ),
			array( 'title' => 'Detail', 'fields' => array( 'description', 'mitigation', 'resolution' ) ),
		),
		'project_roles' => array(
			array( 'title' => '', 'fields' => array( 'project_id', 'party_type', 'user_id', 'contact_id', 'partner_account_id', 'role', 'is_primary' ) ),
			array( 'title' => 'Rates', 'fields' => array( 'bill_rate', 'cost_rate' ) ),
			array( 'title' => 'Dates', 'fields' => array( 'start_date', 'end_date' ) ),
			array( 'title' => 'Notes', 'fields' => array( 'description' ) ),
		),
		'time_entries' => array(
			array( 'title' => '', 'fields' => array( 'project_id', 'task_id', 'user_id', 'entry_date', 'hours', 'is_billable' ) ),
			array( 'title' => 'Notes', 'fields' => array( 'description' ) ),
			array( 'title' => 'Billing', 'fields' => array( 'bill_rate', 'cost_rate', 'invoice_ref' ) ),
		),
		'allocations' => array(
			array( 'title' => '', 'fields' => array( 'project_id', 'user_id', 'week_start', 'planned_hours', 'role' ) ),
			array( 'title' => 'Notes', 'fields' => array( 'description' ) ),
		),
		// account_id is left out on purpose: it always follows project_id
		// (pcm_crm_pm_apply_ticket()) and is readonly, so there is nothing for
		// a form to show that project_id doesn't already say.
		'help_tickets' => array(
			array( 'title' => '', 'fields' => array( 'project_id', 'contact_id', 'subject', 'status' ) ),
			array( 'title' => 'Details', 'fields' => array( 'description' ) ),
		),
	);

	return isset( $pcm_layouts[ $pcm_object ] ) ? $pcm_layouts[ $pcm_object ] : $pcm_layout;
}
add_filter( 'pcm_crm_layout', 'pcm_crm_pm_layout', 10, 3 );

/**
 * The Project Types a project's layout can vary by — key => label, the shape
 * pcm_crm_layout_variant_keys() promises. Only Project declares a
 * layout_variant field (pm-objects.php), so every other object passes
 * straight through.
 */
function pcm_crm_pm_layout_variant_keys( $pcm_keys, $pcm_object ) {
	if ( 'projects' !== $pcm_object ) {
		return $pcm_keys;
	}

	$pcm_out = array();

	foreach ( pcm_crm_pm_types() as $pcm_key => $pcm_type ) {
		$pcm_out[ $pcm_key ] = $pcm_type['label'];
	}

	return $pcm_out;
}
add_filter( 'pcm_crm_layout_variant_keys', 'pcm_crm_pm_layout_variant_keys', 10, 2 );

/**
 * {{project.*}} in an email template.
 */
function pcm_crm_pm_merge_prefix( $pcm_prefixes ) {
	$pcm_prefixes['project'] = 'projects';

	return $pcm_prefixes;
}
add_filter( 'pcm_crm_merge_prefixes', 'pcm_crm_pm_merge_prefix' );

/* Display names -------------------------------------------------------------
   Core's expand() resolves an account name only for contacts and
   opportunities, and knows nothing of projects or of people on them. Without
   this, a project list shows a blank Account column and a team list is a column
   of dashes — nothing errors, it just reads as empty.
   -------------------------------------------------------------------------- */

/**
 * The module's objects, as expand() names them.
 */
function pcm_crm_pm_expandable() {
	return array( 'project', 'project_task', 'project_raid', 'project_milestone', 'project_role', 'time_entry', 'allocation', 'retainer_period', 'status_report' );
}

/**
 * Fetch every parent a page of rows points at, one query per source.
 *
 * A page at a time rather than a row at a time, which is the whole reason the
 * pcm_crm_expand_items filter hands over the page: a list of a hundred time
 * entries is two lookups here, not two hundred.
 */
function pcm_crm_pm_expand_items( $pcm_items, $pcm_object ) {
	if ( ! $pcm_items || ! in_array( $pcm_object, pcm_crm_pm_expandable(), true ) ) {
		return $pcm_items;
	}

	$pcm_ids = array( 'accounts' => array(), 'opportunities' => array(), 'projects' => array(), 'contacts' => array() );

	foreach ( $pcm_items as $pcm_item ) {
		foreach ( array( 'account_id' => 'accounts', 'partner_account_id' => 'accounts', 'opportunity_id' => 'opportunities',
			'project_id' => 'projects', 'contact_id' => 'contacts', 'owner_contact_id' => 'contacts' ) as $pcm_column => $pcm_source ) {
			if ( ! empty( $pcm_item[ $pcm_column ] ) ) {
				$pcm_ids[ $pcm_source ][] = (int) $pcm_item[ $pcm_column ];
			}
		}
	}

	$pcm_contacts = $pcm_ids['contacts'] ? pcm_crm_contacts()->get_many( array_unique( $pcm_ids['contacts'] ) ) : array();

	// A client contact's organisation is their own account, which none of the
	// rows name directly — so it joins the account lookup before that runs,
	// keeping it to one query rather than a second pass.
	foreach ( $pcm_contacts as $pcm_contact_row ) {
		if ( ! empty( $pcm_contact_row['account_id'] ) ) {
			$pcm_ids['accounts'][] = (int) $pcm_contact_row['account_id'];
		}
	}

	$pcm_maps = array(
		'accounts'      => $pcm_ids['accounts'] ? pcm_crm_accounts()->get_many( array_unique( $pcm_ids['accounts'] ) ) : array(),
		'opportunities' => $pcm_ids['opportunities'] ? pcm_crm_opportunities()->get_many( array_unique( $pcm_ids['opportunities'] ) ) : array(),
		'projects'      => $pcm_ids['projects'] ? pcm_crm_projects()->get_many( array_unique( $pcm_ids['projects'] ) ) : array(),
		'contacts'      => $pcm_contacts,
	);

	return pcm_crm_pm_decorate( $pcm_object, $pcm_items, $pcm_maps, 'pcm_crm_user_name' );
}
add_filter( 'pcm_crm_expand_items', 'pcm_crm_pm_expand_items', 10, 2 );

/**
 * Add the display names to a page of rows, from lookups already fetched.
 *
 * Pure — no queries, and the user resolver is passed in — so the names the
 * browser reads can be asserted without a database.
 */
function pcm_crm_pm_decorate( $pcm_object, array $pcm_items, array $pcm_maps, $pcm_user_name ) {
	$pcm_name = function ( $pcm_source, $pcm_id, $pcm_key = 'name' ) use ( $pcm_maps ) {
		return ( $pcm_id && isset( $pcm_maps[ $pcm_source ][ $pcm_id ][ $pcm_key ] ) ) ? (string) $pcm_maps[ $pcm_source ][ $pcm_id ][ $pcm_key ] : '';
	};

	$pcm_contact = function ( $pcm_id ) use ( $pcm_maps ) {
		return ( $pcm_id && isset( $pcm_maps['contacts'][ $pcm_id ] ) ) ? pcm_crm_contact_name( $pcm_maps['contacts'][ $pcm_id ] ) : '';
	};

	$pcm_parties = pcm_crm_pm_party_types();

	foreach ( $pcm_items as $pcm_i => $pcm_item ) {
		if ( ! is_array( $pcm_item ) ) {
			continue;
		}

		if ( array_key_exists( 'account_id', $pcm_item ) ) {
			$pcm_items[ $pcm_i ]['_account_name'] = $pcm_name( 'accounts', (int) $pcm_item['account_id'] );
		}

		if ( array_key_exists( 'opportunity_id', $pcm_item ) ) {
			$pcm_items[ $pcm_i ]['_opportunity_name'] = $pcm_name( 'opportunities', (int) $pcm_item['opportunity_id'] );
		}

		if ( array_key_exists( 'project_id', $pcm_item ) ) {
			$pcm_items[ $pcm_i ]['_project_name'] = $pcm_name( 'projects', (int) $pcm_item['project_id'] );
		}

		if ( array_key_exists( 'user_id', $pcm_item ) ) {
			$pcm_items[ $pcm_i ]['_user_name'] = $pcm_item['user_id'] ? (string) call_user_func( $pcm_user_name, (int) $pcm_item['user_id'] ) : '';
		}

		if ( array_key_exists( 'assignee_user_id', $pcm_item ) ) {
			$pcm_items[ $pcm_i ]['_assignee_name'] = $pcm_item['assignee_user_id'] ? (string) call_user_func( $pcm_user_name, (int) $pcm_item['assignee_user_id'] ) : '';
		}

		if ( array_key_exists( 'owner_contact_id', $pcm_item ) ) {
			$pcm_items[ $pcm_i ]['_owner_contact_name'] = $pcm_contact( (int) $pcm_item['owner_contact_id'] );
		}

		if ( 'project_role' === $pcm_object ) {
			$pcm_party = isset( $pcm_item['party_type'] ) ? (string) $pcm_item['party_type'] : '';

			$pcm_items[ $pcm_i ]['_party_label'] = isset( $pcm_parties[ $pcm_party ] ) ? $pcm_parties[ $pcm_party ] : '';

			// Who the role names depends on which side they are on, which is the
			// point of party_type: a user internally, a contact on the client
			// side, and for a partner either the named person or the firm.
			if ( 'internal' === $pcm_party ) {
				$pcm_items[ $pcm_i ]['_person_name'] = $pcm_items[ $pcm_i ]['_user_name'];
				$pcm_items[ $pcm_i ]['_org_name']    = get_bloginfo( 'name' );
			} else {
				$pcm_person = $pcm_contact( (int) $pcm_item['contact_id'] );
				$pcm_firm   = $pcm_name( 'accounts', (int) $pcm_item['partner_account_id'] );

				if ( 'client' === $pcm_party ) {
					$pcm_contact_row = isset( $pcm_maps['contacts'][ (int) $pcm_item['contact_id'] ] ) ? $pcm_maps['contacts'][ (int) $pcm_item['contact_id'] ] : array();
					$pcm_firm        = ! empty( $pcm_contact_row['account_id'] ) ? $pcm_name( 'accounts', (int) $pcm_contact_row['account_id'] ) : '';
				}

				$pcm_items[ $pcm_i ]['_person_name'] = '' !== $pcm_person ? $pcm_person : $pcm_firm;
				$pcm_items[ $pcm_i ]['_org_name']    = $pcm_firm;
			}
		}
	}

	return $pcm_items;
}

/* Project summary and time context -------------------------------------------
   Two read-only routes the type-aware screens need: what a project has burned
   against what it was given, and what a time entry is about to be counted
   against. Both are sums over indexed columns, done in SQL rather than by
   fetching every entry to the browser.
   -------------------------------------------------------------------------- */

function pcm_crm_pm_register_routes() {
	register_rest_route( PCM_CRM_REST::NS, '/pm/projects/(?P<pcm_id>\d+)/summary', array(
		'methods'             => 'GET',
		'callback'            => 'pcm_crm_pm_rest_summary',
		'permission_callback' => array( 'PCM_CRM_REST', 'permission' ),
	) );

	register_rest_route( PCM_CRM_REST::NS, '/pm/time-context', array(
		'methods'             => 'GET',
		'callback'            => 'pcm_crm_pm_rest_time_context',
		'permission_callback' => array( 'PCM_CRM_REST', 'permission' ),
	) );
}
add_action( 'rest_api_init', 'pcm_crm_pm_register_routes' );

/**
 * Sum an expression over a project's live time entries.
 */
function pcm_crm_pm_time_sum( $pcm_expression, $pcm_where, array $pcm_args ) {
	global $wpdb;

	$pcm_table = pcm_crm_pm_time_table();

	// phpcs:ignore WordPress.DB.PreparedSQL -- expression and clause are literals from this file
	return (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM({$pcm_expression}), 0) FROM {$pcm_table} WHERE is_deleted = 0 AND {$pcm_where}", $pcm_args ) );
}

/**
 * One retainer period, with what has been used of it.
 */
function pcm_crm_pm_period_burn( array $pcm_period ) {
	$pcm_used    = pcm_crm_pm_time_sum( 'hours', 'retainer_period_id = %d', array( (int) $pcm_period['id'] ) );
	$pcm_allowed = (float) $pcm_period['allotted_hours'] + (float) $pcm_period['carried_in_hours'];

	return array(
		'id'        => (int) $pcm_period['id'],
		'start'     => $pcm_period['period_start'],
		'end'       => $pcm_period['period_end'],
		'allotted'  => (float) $pcm_period['allotted_hours'],
		'carried'   => (float) $pcm_period['carried_in_hours'],
		'available' => $pcm_allowed,
		'used'      => $pcm_used,
		'remaining' => round( $pcm_allowed - $pcm_used, 2 ),
		'is_closed' => (int) $pcm_period['is_closed'],
	);
}

/**
 * The retainer period a date falls in, for a project, or null.
 */
function pcm_crm_pm_period_on( $pcm_project_id, $pcm_date ) {
	pcm_crm_retainer_ensure( $pcm_project_id );

	$pcm_found = pcm_crm_retainer_periods()->find( array(
		'filters'  => array(
			'project_id'   => (int) $pcm_project_id,
			'period_start' => array( 'max' => $pcm_date ),
			'period_end'   => array( 'min' => $pcm_date ),
		),
		'per_page' => 1,
	) );

	$pcm_items = isset( $pcm_found['items'] ) ? $pcm_found['items'] : $pcm_found;

	return $pcm_items ? reset( $pcm_items ) : null;
}

function pcm_crm_pm_project_summary( $pcm_id ) {
	// Opens and closes periods first, so the Burn-down tab and the portal's
	// period bar read today's state rather than whatever the last visit left.
	pcm_crm_retainer_ensure( $pcm_id );

	$pcm_project = pcm_crm_projects()->get( $pcm_id );

	if ( ! $pcm_project ) {
		return null;
	}

	$pcm_type = pcm_crm_pm_type( $pcm_project['project_type'] );
	$pcm_args = array( (int) $pcm_id );

	$pcm_out = array(
		'type'           => $pcm_type ? $pcm_type['key'] : '',
		'type_label'     => $pcm_type ? $pcm_type['label'] : '',
		'archetype'      => $pcm_type ? $pcm_type['archetype'] : '',
		'logged_hours'   => pcm_crm_pm_time_sum( 'hours', 'project_id = %d', $pcm_args ),
		'billable_hours' => pcm_crm_pm_time_sum( 'hours', 'project_id = %d AND is_billable = 1', $pcm_args ),
		'unbilled_hours' => pcm_crm_pm_time_sum( 'hours', "project_id = %d AND is_billable = 1 AND invoice_ref = ''", $pcm_args ),
		'billable_value' => pcm_crm_pm_time_sum( 'hours * COALESCE(bill_rate, 0)', 'project_id = %d AND is_billable = 1', $pcm_args ),
		'unbilled_value' => pcm_crm_pm_time_sum( 'hours * COALESCE(bill_rate, 0)', "project_id = %d AND is_billable = 1 AND invoice_ref = ''", $pcm_args ),
		'cost_value'     => pcm_crm_pm_time_sum( 'hours * COALESCE(cost_rate, 0)', 'project_id = %d', $pcm_args ),
		'budget_amount'  => null === $pcm_project['budget_amount'] ? null : (float) $pcm_project['budget_amount'],
		'budget_hours'   => null === $pcm_project['budget_hours'] ? null : (float) $pcm_project['budget_hours'],
		'estimate_hours' => pcm_crm_project_tasks()->sum( 'estimated_hours', array( 'filters' => array( 'project_id' => (int) $pcm_id ) ) ),
		'periods'        => array(),
		'current_period' => null,
		'next_milestone' => null,
	);

	$pcm_periods = pcm_crm_retainer_periods()->find( array(
		'filters'  => array( 'project_id' => (int) $pcm_id ),
		'orderby'  => 'period_start',
		'order'    => 'DESC',
		'per_page' => 24,
	) );

	foreach ( isset( $pcm_periods['items'] ) ? $pcm_periods['items'] : $pcm_periods as $pcm_period ) {
		$pcm_burn = pcm_crm_pm_period_burn( $pcm_period );
		$pcm_out['periods'][] = $pcm_burn;

		if ( $pcm_period['period_start'] <= current_time( 'Y-m-d' ) && $pcm_period['period_end'] >= current_time( 'Y-m-d' ) ) {
			$pcm_out['current_period'] = $pcm_burn;
		}
	}

	$pcm_out['next_milestone'] = pcm_crm_pm_next_milestone( $pcm_id );

	return $pcm_out;
}

function pcm_crm_pm_rest_summary( WP_REST_Request $pcm_request ) {
	$pcm_params  = $pcm_request->get_url_params();
	$pcm_summary = pcm_crm_pm_project_summary( isset( $pcm_params['pcm_id'] ) ? absint( $pcm_params['pcm_id'] ) : 0 );

	if ( ! $pcm_summary ) {
		return new WP_Error( 'pcm_crm_not_found', __( 'That project no longer exists.', 'pcm-crm' ), array( 'status' => 404 ) );
	}

	return rest_ensure_response( $pcm_summary );
}

/**
 * What a time entry is about to be counted against: its project's type and
 * rules, the retainer period its date falls in, and the task's estimate.
 */
function pcm_crm_pm_time_context_for( $pcm_project_id, $pcm_task_id, $pcm_date ) {
	$pcm_project = $pcm_project_id ? pcm_crm_projects()->get( $pcm_project_id ) : null;

	if ( ! $pcm_project ) {
		return null;
	}

	$pcm_type    = pcm_crm_pm_type( $pcm_project['project_type'] );
	$pcm_context = pcm_crm_pm_time_context( array( 'project_id' => (int) $pcm_project_id ) );
	$pcm_date    = $pcm_date ? $pcm_date : current_time( 'Y-m-d' );

	$pcm_out = array(
		'project'      => array( 'id' => (int) $pcm_project['id'], 'name' => $pcm_project['name'] ),
		'type'         => $pcm_type ? $pcm_type['key'] : '',
		'type_label'   => $pcm_type ? $pcm_type['label'] : '',
		'archetype'    => $pcm_type ? $pcm_type['archetype'] : '',
		'rules'        => $pcm_context['rules'],
		'default_rate' => null === $pcm_project['default_bill_rate'] ? null : (float) $pcm_project['default_bill_rate'],
		'period'       => null,
		'task'         => null,
	);

	if ( ! empty( $pcm_context['rules']['resolves_period'] ) ) {
		$pcm_period = pcm_crm_pm_period_on( $pcm_project_id, $pcm_date );
		$pcm_out['period'] = $pcm_period ? pcm_crm_pm_period_burn( $pcm_period ) : null;
	}

	if ( $pcm_task_id ) {
		$pcm_task = pcm_crm_project_tasks()->get( $pcm_task_id );

		if ( $pcm_task && (int) $pcm_task['project_id'] === (int) $pcm_project_id ) {
			$pcm_out['task'] = array(
				'id'        => (int) $pcm_task['id'],
				'name'      => $pcm_task['name'],
				'estimated' => null === $pcm_task['estimated_hours'] ? null : (float) $pcm_task['estimated_hours'],
				'logged'    => pcm_crm_pm_time_sum( 'hours', 'task_id = %d', array( (int) $pcm_task_id ) ),
			);
		}
	}

	return $pcm_out;
}

function pcm_crm_pm_rest_time_context( WP_REST_Request $pcm_request ) {
	$pcm_date = sanitize_text_field( (string) $pcm_request->get_param( 'date' ) );

	$pcm_context = pcm_crm_pm_time_context_for(
		absint( $pcm_request->get_param( 'project_id' ) ),
		absint( $pcm_request->get_param( 'task_id' ) ),
		preg_match( '/^\d{4}-\d{2}-\d{2}$/', $pcm_date ) ? $pcm_date : ''
	);

	if ( ! $pcm_context ) {
		return new WP_Error( 'pcm_crm_not_found', __( 'That project no longer exists.', 'pcm-crm' ), array( 'status' => 404 ) );
	}

	return rest_ensure_response( $pcm_context );
}
