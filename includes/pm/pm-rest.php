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
	$pcm_boot['projectTypes']    = pcm_crm_pm_project_types();
	$pcm_boot['projectStages']   = pcm_crm_pm_all_stage_names();
	$pcm_boot['projectHealth']   = pcm_crm_pm_health_options();
	$pcm_boot['raidTypes']       = pcm_crm_pm_raid_types();
	$pcm_boot['raidStatuses']    = pcm_crm_pm_raid_statuses();
	$pcm_boot['raidLevels']      = pcm_crm_pm_raid_levels();
	$pcm_boot['taskStatuses']    = pcm_crm_pm_task_statuses();
	$pcm_boot['partyTypes']      = pcm_crm_pm_party_types();
	$pcm_boot['projectRoles']    = pcm_crm_pm_roles();
	$pcm_boot['retainerPeriods'] = pcm_crm_pm_periods();

	// Which stages belong to which type, so the record form can narrow the stage
	// picklist once a type is chosen. Sent as a map rather than fetched per type,
	// because it is three short lists and a request per keystroke is not.
	$pcm_boot['projectStageSets'] = pcm_crm_pm_stages();

	$pcm_boot['retainerTypes']      = pcm_crm_pm_retainer_types();
	$pcm_boot['opportunityTypeMap'] = pcm_crm_pm_opportunity_type_map();

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
	);
}

pcm_crm_register_related( 'accounts', 'pcm_crm_pm_related_projects_for_account' );
pcm_crm_register_related( 'opportunities', 'pcm_crm_pm_related_projects_for_opportunity' );
pcm_crm_register_related( 'projects', 'pcm_crm_pm_related_project' );

// Activities already reach a project without any registration: what_type is a
// free-text column with no whitelist behind it, and the composite index is
// (what_type,what_id), so 'project' needs nothing added here. What it does still
// need is a way to *pick* a project in the Related To control, which is in the
// browser rather than here.

/**
 * How a won deal's type becomes a project's.
 *
 * Sent to the browser rather than resolved there, so the mapping is one list in
 * one place. An unmapped type is deliberately absent rather than defaulting to
 * anything: the project type decides which stages are legal, so a wrong guess
 * offers the wrong lifecycle and the mistake is invisible until someone picks a
 * stage that will not save.
 */
function pcm_crm_pm_opportunity_type_map() {
	return apply_filters( 'pcm_crm_pm_opportunity_type_map', array(
		'New Business'      => 'Custom Development',
		'Existing Business' => 'Custom Development',
		'Renewal'           => 'Salesforce Support Retainer',
	) );
}

/**
 * The record forms.
 *
 * Supplied through the layout filter rather than added to
 * pcm_crm_default_layouts(), which is core's list — a module should not have to
 * edit it to describe its own objects. An admin can still rearrange these on the
 * Fields & Layouts tab, and a saved arrangement wins, because pcm_crm_layout()
 * reaches for the stored one first and only falls through to here.
 */
function pcm_crm_pm_layout( $pcm_layout, $pcm_object ) {
	// The filter runs after pcm_crm_append_unplaced(), which has already swept
	// every field of an object with no layout into one "Custom fields" section —
	// so what arrives here is never empty and cannot be used to detect "no
	// layout yet". The saved option is the only honest signal, and an
	// arrangement someone made on the Fields & Layouts tab must win over this.
	$pcm_saved = get_option( PCM_CRM_LAYOUTS_OPTION, array() );

	if ( is_array( $pcm_saved ) && ! empty( $pcm_saved[ $pcm_object ] ) ) {
		return $pcm_layout;
	}

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
			array( 'title' => '', 'fields' => array( 'name', 'project_id', 'assignee_user_id', 'status', 'is_milestone' ) ),
			array( 'title' => 'Dates', 'fields' => array( 'start_date', 'due_date', 'estimated_hours' ) ),
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
	);

	return isset( $pcm_layouts[ $pcm_object ] ) ? $pcm_layouts[ $pcm_object ] : $pcm_layout;
}
add_filter( 'pcm_crm_layout', 'pcm_crm_pm_layout', 10, 2 );

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
	return array( 'project', 'project_task', 'project_raid', 'project_role', 'time_entry', 'allocation', 'retainer_period', 'status_report' );
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
