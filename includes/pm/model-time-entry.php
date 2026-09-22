<?php
/**
 * Time entry.
 *
 * hours is the 'hours' field type rather than a decimal, so "1:30" arrives as
 * 1.5 instead of 130 — see includes/duration.php.
 *
 * bill_rate and cost_rate are snapshotted onto the row when it is written, not
 * looked up at report time, so changing a rate today cannot rewrite what last
 * quarter was worth. rate_source records which rung of the ladder answered,
 * which is what lets an unrated entry be named rather than silently counted.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_time_entries() {
	static $pcm_model = null;

	if ( null === $pcm_model ) {
		$pcm_model = new PCM_CRM_Model(
			'time_entry',
			pcm_crm_pm_time_table(),
			array_merge(
				array(
					'project_id'         => array( 'type' => 'id',    'sf' => 'PCM_Project_Id__c', 'label' => 'Project', 'lookup' => 'projects' ),
					'task_id'            => array( 'type' => 'id',    'sf' => 'PCM_Task_Id__c', 'label' => 'Task', 'lookup' => 'project_tasks', 'lookup_filter' => array( 'project_id' => 'project_id' ) ),
					// Resolved when the entry is written, so burn-down is an
					// indexed sum on one column rather than a date-range scan.
					'retainer_period_id' => array( 'type' => 'id',    'sf' => 'PCM_Period_Id__c', 'label' => 'Retainer Period', 'readonly' => true ),
					'user_id'            => array( 'type' => 'id',    'sf' => 'PCM_User__c', 'label' => 'Person', 'options' => 'pcm_crm_owner_options' ),
					'entry_date'         => array( 'type' => 'date',  'sf' => 'PCM_Date__c', 'label' => 'Date' ),
					'hours'              => array( 'type' => 'hours', 'sf' => 'PCM_Hours__c', 'label' => 'Hours', 'ui' => 'hours' ),
					'is_billable'        => array( 'type' => 'bool',  'sf' => 'PCM_Billable__c', 'label' => 'Billable' ),
					'bill_rate'          => array( 'type' => 'decimal', 'sf' => 'PCM_Bill_Rate__c', 'label' => 'Bill Rate', 'ui' => 'currency' ),
					'cost_rate'          => array( 'type' => 'decimal', 'sf' => 'PCM_Cost_Rate__c', 'label' => 'Cost Rate', 'ui' => 'currency' ),
					'rate_source'        => array( 'type' => 'text',  'sf' => 'PCM_Rate_Source__c', 'label' => 'Rate From', 'readonly' => true ),
					'invoice_ref'        => array( 'type' => 'text',  'sf' => 'PCM_Invoice_Ref__c', 'label' => 'Invoice Reference' ),
					'description'        => array( 'type' => 'longtext', 'sf' => 'PCM_Description__c', 'label' => 'What you did' ),
				),
				PCM_CRM_Model::system_fields(),
				pcm_crm_custom_field_map( 'time_entries' )
			),
			array( 'description', 'invoice_ref' ),
			array(
				'project' => array( 'column' => 'project_id', 'model' => 'pcm_crm_projects', 'label' => 'Project' ),
			)
		);
	}

	return $pcm_model;
}

/**
 * Fill in the two things a time entry should not have to be told.
 *
 * The person is whoever is logging unless someone said otherwise, and the date
 * is today. Both are the overwhelmingly common case, and an entry that lands on
 * the wrong day because a field was blank is worse than one that has to be
 * corrected.
 */
function pcm_crm_pm_default_time_entry( $pcm_row, $pcm_object ) {
	if ( 'time_entry' !== $pcm_object ) {
		return $pcm_row;
	}

	if ( empty( $pcm_row['user_id'] ) ) {
		$pcm_row['user_id'] = get_current_user_id();
	}

	if ( empty( $pcm_row['entry_date'] ) ) {
		$pcm_row['entry_date'] = current_time( 'Y-m-d' );
	}

	return $pcm_row;
}
add_filter( 'pcm_crm_before_insert', 'pcm_crm_pm_default_time_entry', 10, 2 );

/**
 * The project an entry is logged against, and its type's rules for time.
 *
 * Read from the merged row, since an edit may change the hours without saying
 * which project they are on.
 */
function pcm_crm_pm_time_context( array $pcm_row, $pcm_id = 0 ) {
	$pcm_existing = $pcm_id ? pcm_crm_time_entries()->get( $pcm_id ) : array();
	$pcm_merged   = array_merge( is_array( $pcm_existing ) ? $pcm_existing : array(), $pcm_row );
	$pcm_project  = ! empty( $pcm_merged['project_id'] ) ? pcm_crm_projects()->get( (int) $pcm_merged['project_id'] ) : null;
	$pcm_type     = $pcm_project ? pcm_crm_pm_type( $pcm_project['project_type'] ) : null;
	$pcm_default  = pcm_crm_pm_archetype( 'fixed' );

	return array(
		'merged'  => $pcm_merged,
		'project' => $pcm_project,
		'type'    => $pcm_type,
		// A project with no type logs time the permissive way: no rule it
		// cannot know to follow.
		'rules'   => $pcm_type ? $pcm_type['time'] : array_merge( $pcm_default['time'], array( 'task_required' => 0 ) ),
	);
}

/**
 * Apply what the project's type decides before an entry is written.
 *
 * - Internal work is never billable, whatever was posted.
 * - A new entry takes its type's billable default when nobody chose.
 * - A billable entry with no rate takes the project's default rate, snapshotted
 *   with where it came from.
 * - A retainer entry is filed under the period its date falls in.
 */
function pcm_crm_pm_apply_time_rules( $pcm_row, $pcm_object, $pcm_id = 0 ) {
	if ( 'time_entry' !== $pcm_object ) {
		return $pcm_row;
	}

	$pcm_context = pcm_crm_pm_time_context( $pcm_row, $pcm_id );
	$pcm_rules   = $pcm_context['rules'];
	$pcm_merged  = $pcm_context['merged'];
	$pcm_project = $pcm_context['project'];

	if ( ! empty( $pcm_rules['billable_locked'] ) ) {
		$pcm_row['is_billable'] = (int) $pcm_rules['billable_default'];
	} elseif ( ! $pcm_id && ! isset( $pcm_row['is_billable'] ) ) {
		$pcm_row['is_billable'] = (int) $pcm_rules['billable_default'];
	}

	$pcm_billable = isset( $pcm_row['is_billable'] ) ? $pcm_row['is_billable'] : ( isset( $pcm_merged['is_billable'] ) ? $pcm_merged['is_billable'] : 0 );
	$pcm_rate     = isset( $pcm_merged['bill_rate'] ) ? $pcm_merged['bill_rate'] : null;

	if ( $pcm_billable && $pcm_project && ( null === $pcm_rate || '' === $pcm_rate || 0.0 === (float) $pcm_rate ) && ! empty( $pcm_project['default_bill_rate'] ) ) {
		$pcm_row['bill_rate']   = $pcm_project['default_bill_rate'];
		$pcm_row['rate_source'] = 'project';

		if ( empty( $pcm_merged['cost_rate'] ) && ! empty( $pcm_project['default_cost_rate'] ) ) {
			$pcm_row['cost_rate'] = $pcm_project['default_cost_rate'];
		}
	}

	if ( ! empty( $pcm_rules['resolves_period'] ) && $pcm_project && ! empty( $pcm_merged['entry_date'] ) ) {
		$pcm_periods = pcm_crm_retainer_periods()->find( array(
			'filters'  => array(
				'project_id'   => (int) $pcm_project['id'],
				'period_start' => array( 'max' => $pcm_merged['entry_date'] ),
				'period_end'   => array( 'min' => $pcm_merged['entry_date'] ),
			),
			'per_page' => 1,
		) );

		$pcm_items = isset( $pcm_periods['items'] ) ? $pcm_periods['items'] : $pcm_periods;

		if ( $pcm_items ) {
			$pcm_first = reset( $pcm_items );
			$pcm_row['retainer_period_id'] = (int) $pcm_first['id'];
		}
	}

	return $pcm_row;
}
add_filter( 'pcm_crm_before_insert', 'pcm_crm_pm_apply_time_rules', 20, 2 );
add_filter( 'pcm_crm_before_update', 'pcm_crm_pm_apply_time_rules', 20, 3 );

/**
 * Refuse an entry that cannot be counted, or that its project's process says is
 * incomplete.
 */
function pcm_crm_pm_validate_time_entry( $pcm_error, $pcm_object, $pcm_row, $pcm_id ) {
	if ( 'time_entry' !== $pcm_object || is_wp_error( $pcm_error ) ) {
		return $pcm_error;
	}

	$pcm_context  = pcm_crm_pm_time_context( $pcm_row, $pcm_id );
	$pcm_merged   = $pcm_context['merged'];
	$pcm_rules    = $pcm_context['rules'];
	$pcm_settings = pcm_crm_pm_time_settings();

	if ( empty( $pcm_merged['project_id'] ) ) {
		return new WP_Error( 'pcm_crm_pm_no_project', __( 'Time has to be logged against a project.', 'pcm-crm' ), array( 'status' => 400 ) );
	}

	// Null rather than zero is what the parser returns for something it could not
	// read, so this catches "1h3O" with a letter O in it as well as a blank.
	if ( ! isset( $pcm_merged['hours'] ) || null === $pcm_merged['hours'] ) {
		return new WP_Error(
			'pcm_crm_pm_no_hours',
			__( 'How long did it take? Decimal hours (1.5) or h:mm (1:30) both work.', 'pcm-crm' ),
			array( 'status' => 400 )
		);
	}

	if ( (float) $pcm_merged['hours'] <= 0 ) {
		return new WP_Error( 'pcm_crm_pm_no_hours', __( 'An entry of no time is not worth recording.', 'pcm-crm' ), array( 'status' => 400 ) );
	}

	// A day longer than a day is always a typo — usually a date typed into the
	// hours box. The ceiling is a setting, since some teams cap entries lower.
	$pcm_max = (float) $pcm_settings['max_hours'] > 0 ? (float) $pcm_settings['max_hours'] : 24;

	if ( (float) $pcm_merged['hours'] > $pcm_max ) {
		return new WP_Error(
			'pcm_crm_pm_too_many_hours',
			24.0 === $pcm_max
				? __( 'That is more than a day in one entry. Split it across dates, or check the value.', 'pcm-crm' )
				/* translators: %s: the most hours one entry may hold */
				: sprintf( __( 'One entry can hold at most %s hours. Split it across entries, or check the value.', 'pcm-crm' ), $pcm_max ),
			array( 'status' => 400 )
		);
	}

	$pcm_today = current_time( 'Y-m-d' );

	if ( empty( $pcm_settings['allow_future'] ) && ! empty( $pcm_merged['entry_date'] ) && $pcm_merged['entry_date'] > $pcm_today ) {
		return new WP_Error( 'pcm_crm_pm_future_time', __( 'Time is logged for work already done, so the date cannot be in the future.', 'pcm-crm' ), array( 'status' => 400 ) );
	}

	if ( (int) $pcm_settings['lock_after_days'] > 0 && ! empty( $pcm_merged['entry_date'] ) ) {
		$pcm_age = pcm_crm_days_between( $pcm_merged['entry_date'] . ' 00:00:00', $pcm_today . ' 00:00:00' );

		if ( $pcm_age > (int) $pcm_settings['lock_after_days'] ) {
			return new WP_Error(
				'pcm_crm_pm_time_locked',
				/* translators: %d: number of days */
				sprintf( __( 'Entries older than %d days are locked, because they may already have been invoiced.', 'pcm-crm' ), (int) $pcm_settings['lock_after_days'] ),
				array( 'status' => 400 )
			);
		}
	}

	$pcm_type_label = $pcm_context['type'] ? $pcm_context['type']['label'] : '';

	if ( ! empty( $pcm_rules['task_required'] ) && empty( $pcm_merged['task_id'] ) ) {
		return new WP_Error(
			'pcm_crm_pm_task_required',
			/* translators: %s: project type */
			sprintf( __( 'Time on a %s project is logged against a task, so the estimate can be compared with what it took.', 'pcm-crm' ), $pcm_type_label ),
			array( 'status' => 400 )
		);
	}

	if ( ! empty( $pcm_merged['task_id'] ) ) {
		$pcm_task = pcm_crm_project_tasks()->get( (int) $pcm_merged['task_id'] );

		if ( $pcm_task && (int) $pcm_task['project_id'] !== (int) $pcm_merged['project_id'] ) {
			return new WP_Error( 'pcm_crm_pm_task_elsewhere', __( 'That task belongs to a different project.', 'pcm-crm' ), array( 'status' => 400 ) );
		}
	}

	if ( ! empty( $pcm_rules['description_required'] ) && '' === trim( (string) ( isset( $pcm_merged['description'] ) ? $pcm_merged['description'] : '' ) ) ) {
		return new WP_Error( 'pcm_crm_pm_description_required', __( 'Say what the time was spent on.', 'pcm-crm' ), array( 'status' => 400 ) );
	}

	if ( ! empty( $pcm_rules['rate_required'] ) && ! empty( $pcm_merged['is_billable'] ) && empty( $pcm_merged['bill_rate'] ) ) {
		return new WP_Error(
			'pcm_crm_pm_rate_required',
			__( 'A billable entry on this project needs a bill rate. Set one on the entry, or a default rate on the project.', 'pcm-crm' ),
			array( 'status' => 400 )
		);
	}

	return $pcm_error;
}
add_filter( 'pcm_crm_validate', 'pcm_crm_pm_validate_time_entry', 10, 4 );

/**
 * A time entry is the one record here where "may edit Projects" and "may
 * edit this entry" are different questions, because the data model already
 * names exactly one person it belongs to. Wired through the REST layer's
 * pcm_crm_can_touch_record hook rather than pcm_crm_validate, deliberately:
 * validate runs for every caller including the sample-data seeder, which
 * inserts entries under a pool of fictitious owners that is never the
 * process running it, and an authorization rule has no business there.
 *
 * An administrator bypasses it — correcting somebody else's logged hours is
 * routine for payroll and billing, not a loophole.
 */
function pcm_crm_pm_guard_time_entry_ownership( $pcm_allowed, $pcm_object, $pcm_id, $pcm_action ) {
	if ( 'time_entries' !== $pcm_object || ! $pcm_allowed || pcm_crm_is_administrator() ) {
		return $pcm_allowed;
	}

	$pcm_entry = pcm_crm_time_entries()->get( $pcm_id );

	// A missing row is not this function's question to answer — the update
	// or delete call finds the same thing and reports it as not found.
	if ( ! $pcm_entry ) {
		return $pcm_allowed;
	}

	return (int) $pcm_entry['user_id'] === get_current_user_id();
}
add_filter( 'pcm_crm_can_touch_record', 'pcm_crm_pm_guard_time_entry_ownership', 10, 4 );
