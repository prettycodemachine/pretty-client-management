<?php
/**
 * Retainer period — one allotment of hours, and what carried into it.
 *
 * A table rather than periods computed from a start date and a monthly figure,
 * because rollover is path-dependent: this period's carry-in is last period's
 * closing balance. Recomputing from a rule would rewrite what last quarter's
 * burn-down said at the time, which is exactly what a client was shown.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_retainer_periods() {
	static $pcm_model = null;

	if ( null === $pcm_model ) {
		$pcm_model = new PCM_CRM_Model(
			'retainer_period',
			pcm_crm_pm_periods_table(),
			array_merge(
				array(
					'project_id'         => array( 'type' => 'id',    'sf' => 'PCM_Project_Id__c', 'label' => 'Project', 'lookup' => 'projects' ),
					'period_start'       => array( 'type' => 'date',  'sf' => 'PCM_Period_Start__c', 'label' => 'Period Start' ),
					'period_end'         => array( 'type' => 'date',  'sf' => 'PCM_Period_End__c', 'label' => 'Period End' ),
					'allotted_hours'     => array( 'type' => 'hours', 'sf' => 'PCM_Allotted_Hours__c', 'label' => 'Allotted Hours', 'ui' => 'hours' ),
					// Written by pcm_crm_retainer_ensure() when the previous period
					// closes, from its balance — never by a form.
					'carried_in_hours'   => array( 'type' => 'hours', 'sf' => 'PCM_Carried_In__c', 'label' => 'Carried In', 'readonly' => true ),
					'rollover_cap_hours' => array( 'type' => 'hours', 'sf' => 'PCM_Rollover_Cap__c', 'label' => 'Rollover Cap' ),
					'is_closed'          => array( 'type' => 'bool',  'sf' => 'PCM_Is_Closed__c', 'label' => 'Closed', 'readonly' => true ),
					'closed_date'        => array( 'type' => 'datetime', 'sf' => 'PCM_Closed_Date__c', 'label' => 'Closed On', 'readonly' => true ),
					'description'        => array( 'type' => 'longtext', 'sf' => 'PCM_Description__c', 'label' => 'Notes' ),
				),
				PCM_CRM_Model::system_fields()
			),
			array( 'description' ),
			array(
				'project' => array( 'column' => 'project_id', 'model' => 'pcm_crm_projects', 'label' => 'Project' ),
			)
		);
	}

	return $pcm_model;
}

/* Lifecycle ------------------------------------------------------------------
   Periods are opened and closed here, lazily — whenever something is about to
   read or file against them (the project summary, the time-context route, a
   time entry being saved), when a project is saved, and once a day from the
   quarter-hourly cron so a project nobody opens still rolls over on time.
   Every step is idempotent: a period is identified by (project_id,
   period_start), which the table's unique key enforces, and a close only
   writes the successor's carry-in if it was the pass that flipped is_closed.

   The arithmetic is the pure half below (windows, carry, settle, plan), kept
   free of the database so tests/run.php can check it directly.

   - Periods run on the retainer's own anniversary, not the calendar: a
     retainer starting the 15th runs 15th to 14th. The anchor day is the first
     existing period's start day (so periods seeded on calendar months stay on
     calendar months), else the retainer start date's. A month too short for
     the anchor takes its last day — a retainer anchored on the 31st runs
     Jan 31–Feb 27, Feb 28–Mar 30, Mar 31–Apr 29 — without drifting to the
     28th for good after February.
   - New periods only ever extend forward from the latest existing one. What a
     period was allotted, and its cap, are copied from the project when it is
     opened and never rewritten, so a changed retainer_hours, cap or cadence
     (monthly ↔ quarterly) applies from the next period to open. Moving the
     retainer start date earlier once periods exist backfills nothing.
   - A period closes the first pass after its end date. Its balance
     (allotted + carried in − used) carries into the next one when the project
     rolls over, floored at 0 and capped at the period's cap (blank or zero
     means uncapped — the project model's own rule for a blank amount);
     otherwise the next one carries in nothing.
   - Carry-ins are frozen at close. Time logged late into a closed period is
     still filed under it, so its own used/remaining stay true, but nothing
     downstream is recomputed — recomputing would rewrite what the client was
     already shown for the following period (the reason this is a table at
     all, above).
   - A closed project opens nothing after its end (actual end date, else the
     day it closed); its remaining periods still close as their ends pass.
   -------------------------------------------------------------------------- */

/**
 * How many months one period of a cadence spans. Unknown cadences (one added
 * through the pcm_crm_pm_periods filter) are monthly unless filtered.
 */
function pcm_crm_retainer_months( $pcm_cadence ) {
	$pcm_months = array( 'monthly' => 1, 'quarterly' => 3 );
	$pcm_out    = isset( $pcm_months[ $pcm_cadence ] ) ? $pcm_months[ $pcm_cadence ] : 1;

	return max( 1, (int) apply_filters( 'pcm_crm_retainer_period_months', $pcm_out, $pcm_cadence ) );
}

function pcm_crm_retainer_is_date( $pcm_value ) {
	return is_string( $pcm_value ) && (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $pcm_value ) && '0000-00-00' !== $pcm_value;
}

/**
 * The start of the period after one starting $pcm_start: $pcm_months later, on
 * the anchor day or the last day of a month too short for it.
 */
function pcm_crm_retainer_step( $pcm_start, $pcm_months, $pcm_anchor ) {
	$pcm_year  = (int) substr( $pcm_start, 0, 4 );
	$pcm_month = (int) substr( $pcm_start, 5, 2 ) + (int) $pcm_months;
	$pcm_year += intdiv( $pcm_month - 1, 12 );
	$pcm_month = ( $pcm_month - 1 ) % 12 + 1;
	$pcm_days  = (int) gmdate( 't', gmmktime( 0, 0, 0, $pcm_month, 1, $pcm_year ) );

	return sprintf( '%04d-%02d-%02d', $pcm_year, $pcm_month, min( max( 1, (int) $pcm_anchor ), $pcm_days ) );
}

function pcm_crm_retainer_shift_day( $pcm_date, $pcm_days ) {
	return gmdate( 'Y-m-d', gmmktime( 0, 0, 0, (int) substr( $pcm_date, 5, 2 ), (int) substr( $pcm_date, 8, 2 ) + $pcm_days, (int) substr( $pcm_date, 0, 4 ) ) );
}

/**
 * Consecutive periods from $pcm_from, each $pcm_months long, through the one
 * containing $pcm_through. Returns array( array( start, end ), ... ).
 */
function pcm_crm_retainer_windows( $pcm_from, $pcm_anchor, $pcm_months, $pcm_through ) {
	$pcm_out = array();

	if ( ! pcm_crm_retainer_is_date( $pcm_from ) || ! pcm_crm_retainer_is_date( $pcm_through ) ) {
		return $pcm_out;
	}

	// Fifty years of monthly periods: a bound against a malformed date rather
	// than a limit anyone will meet.
	for ( $pcm_i = 0; $pcm_from <= $pcm_through && $pcm_i < 600; $pcm_i++ ) {
		$pcm_next  = pcm_crm_retainer_step( $pcm_from, $pcm_months, $pcm_anchor );
		$pcm_out[] = array( $pcm_from, pcm_crm_retainer_shift_day( $pcm_next, -1 ) );
		$pcm_from  = $pcm_next;
	}

	return $pcm_out;
}

/**
 * What a closing period carries into the next one.
 */
function pcm_crm_retainer_carry( $pcm_allotted, $pcm_carried, $pcm_used, $pcm_rollover, $pcm_cap ) {
	if ( ! $pcm_rollover ) {
		return 0.0;
	}

	$pcm_left = max( 0.0, (float) $pcm_allotted + (float) $pcm_carried - (float) $pcm_used );

	if ( null !== $pcm_cap && '' !== $pcm_cap && (float) $pcm_cap > 0 ) {
		$pcm_left = min( (float) $pcm_cap, $pcm_left );
	}

	return round( $pcm_left, 2 );
}

/**
 * The periods a project is missing, oldest first, as rows to insert.
 *
 * @param array  $pcm_project  The project row.
 * @param array  $pcm_existing Every period row it has, deleted ones included
 *                             (a deleted period still owns its start date).
 * @param string $pcm_today    Y-m-d.
 */
function pcm_crm_retainer_plan( array $pcm_project, array $pcm_existing, $pcm_today ) {
	$pcm_hours = isset( $pcm_project['retainer_hours'] ) ? (float) $pcm_project['retainer_hours'] : 0.0;

	if ( $pcm_hours <= 0 ) {
		return array();
	}

	$pcm_months = pcm_crm_retainer_months( isset( $pcm_project['retainer_period'] ) ? $pcm_project['retainer_period'] : '' );

	if ( $pcm_existing ) {
		usort( $pcm_existing, function ( $pcm_a, $pcm_b ) {
			return strcmp( $pcm_a['period_start'], $pcm_b['period_start'] );
		} );

		$pcm_anchor = (int) substr( $pcm_existing[0]['period_start'], 8, 2 );
		$pcm_last   = max( array_map( function ( $pcm_row ) {
			return (string) $pcm_row['period_end'];
		}, $pcm_existing ) );

		if ( ! pcm_crm_retainer_is_date( $pcm_last ) ) {
			return array();
		}

		$pcm_from = pcm_crm_retainer_shift_day( $pcm_last, 1 );
	} else {
		$pcm_from = '';

		foreach ( array( 'retainer_start_date', 'start_date' ) as $pcm_key ) {
			if ( ! empty( $pcm_project[ $pcm_key ] ) && pcm_crm_retainer_is_date( $pcm_project[ $pcm_key ] ) ) {
				$pcm_from = $pcm_project[ $pcm_key ];
				break;
			}
		}

		if ( ! $pcm_from ) {
			return array();
		}

		$pcm_anchor = (int) substr( $pcm_from, 8, 2 );
	}

	$pcm_through = $pcm_today;

	if ( ! empty( $pcm_project['is_closed'] ) ) {
		$pcm_end = ! empty( $pcm_project['actual_end_date'] ) && pcm_crm_retainer_is_date( $pcm_project['actual_end_date'] )
			? $pcm_project['actual_end_date']
			: substr( isset( $pcm_project['closed_date'] ) ? (string) $pcm_project['closed_date'] : '', 0, 10 );

		if ( pcm_crm_retainer_is_date( $pcm_end ) && $pcm_end < $pcm_through ) {
			$pcm_through = $pcm_end;
		}
	}

	$pcm_cap = isset( $pcm_project['retainer_rollover_cap'] ) && '' !== $pcm_project['retainer_rollover_cap'] && null !== $pcm_project['retainer_rollover_cap'] && (float) $pcm_project['retainer_rollover_cap'] > 0
		? (float) $pcm_project['retainer_rollover_cap']
		: null;

	$pcm_out = array();

	foreach ( pcm_crm_retainer_windows( $pcm_from, $pcm_anchor, $pcm_months, $pcm_through ) as $pcm_window ) {
		$pcm_out[] = array(
			'period_start'       => $pcm_window[0],
			'period_end'         => $pcm_window[1],
			'allotted_hours'     => $pcm_hours,
			'rollover_cap_hours' => $pcm_cap,
		);
	}

	return $pcm_out;
}

/**
 * Which periods to close, and what each close carries forward.
 *
 * @param array  $pcm_periods Live periods oldest first, each with `used`.
 * @param bool   $pcm_rollover Whether the project rolls unused hours over.
 * @param string $pcm_today   Y-m-d. A period closes once its end is before it.
 * @return array List of array( 'close' => id, 'carry_to' => id or 0, 'carry' => hours ).
 */
function pcm_crm_retainer_settle( array $pcm_periods, $pcm_rollover, $pcm_today ) {
	$pcm_periods = array_values( $pcm_periods );
	$pcm_ops     = array();

	foreach ( $pcm_periods as $pcm_i => $pcm_period ) {
		if ( ! empty( $pcm_period['is_closed'] ) || (string) $pcm_period['period_end'] >= $pcm_today ) {
			continue;
		}

		$pcm_carry = pcm_crm_retainer_carry(
			$pcm_period['allotted_hours'],
			$pcm_period['carried_in_hours'],
			isset( $pcm_period['used'] ) ? $pcm_period['used'] : 0,
			$pcm_rollover,
			isset( $pcm_period['rollover_cap_hours'] ) ? $pcm_period['rollover_cap_hours'] : null
		);

		$pcm_to = 0;

		// A successor already closed has had its carry frozen; leave it.
		if ( isset( $pcm_periods[ $pcm_i + 1 ] ) && empty( $pcm_periods[ $pcm_i + 1 ]['is_closed'] ) ) {
			$pcm_to = (int) $pcm_periods[ $pcm_i + 1 ]['id'];
			// Feeds the next iteration, so a backfilled run carries through.
			$pcm_periods[ $pcm_i + 1 ]['carried_in_hours'] = $pcm_carry;
		}

		$pcm_ops[] = array( 'close' => (int) $pcm_period['id'], 'carry_to' => $pcm_to, 'carry' => $pcm_to ? $pcm_carry : 0.0 );
	}

	return $pcm_ops;
}

/**
 * Hold the lifecycle off for the rest of the request, or let it run again —
 * for the sample generator, which writes projects and their time in bulk and
 * wants periods worked out once, afterwards.
 */
function pcm_crm_retainer_paused( $pcm_set = null ) {
	static $pcm_paused = false;

	if ( null !== $pcm_set ) {
		$pcm_paused = (bool) $pcm_set;
	}

	return $pcm_paused;
}

/**
 * Bring a retainer project's periods up to today: open what is missing, file
 * any unfiled time into what was opened, close what has ended.
 *
 * Memoised per request, since a summary read and a time save in one request
 * would otherwise repeat the work; $pcm_force skips the memo (a project save,
 * which may have changed what the plan reads).
 */
function pcm_crm_retainer_ensure( $pcm_project_id, $pcm_force = false ) {
	static $pcm_done = array();
	global $wpdb;

	$pcm_project_id = absint( $pcm_project_id );

	if ( ! $pcm_project_id || pcm_crm_retainer_paused() || ( ! $pcm_force && isset( $pcm_done[ $pcm_project_id ] ) ) ) {
		return;
	}

	$pcm_done[ $pcm_project_id ] = true;

	$pcm_project = pcm_crm_projects()->get( $pcm_project_id );

	if ( ! $pcm_project || ! empty( $pcm_project['is_deleted'] ) || ! pcm_crm_pm_is_retainer( $pcm_project['project_type'] ) ) {
		return;
	}

	$pcm_table = pcm_crm_pm_periods_table();
	$pcm_today = current_time( 'Y-m-d' );
	$pcm_now   = current_time( 'mysql' );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal
	$pcm_all = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$pcm_table} WHERE project_id = %d ORDER BY period_start ASC", $pcm_project_id ), ARRAY_A );

	$pcm_created = 0;

	foreach ( pcm_crm_retainer_plan( $pcm_project, $pcm_all, $pcm_today ) as $pcm_row ) {
		$pcm_row = array_merge( $pcm_row, array(
			'project_id'          => $pcm_project_id,
			'carried_in_hours'    => 0,
			'owner_id'            => isset( $pcm_project['owner_id'] ) ? (int) $pcm_project['owner_id'] : 0,
			'created_date'        => $pcm_now,
			'last_modified_date'  => $pcm_now,
			'is_test'             => empty( $pcm_project['is_test'] ) ? 0 : 1,
		) );

		if ( null === $pcm_row['rollover_cap_hours'] ) {
			unset( $pcm_row['rollover_cap_hours'] );
		}

		// A duplicate from a concurrent pass fails on the unique key, which is
		// the point of it; either way the period now exists.
		if ( $wpdb->insert( $pcm_table, $pcm_row ) ) {
			$pcm_created++;
		}
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal
	$pcm_live = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$pcm_table} WHERE project_id = %d AND is_deleted = 0 ORDER BY period_start ASC", $pcm_project_id ), ARRAY_A );

	if ( ! $pcm_live ) {
		return;
	}

	$pcm_time  = pcm_crm_pm_time_table();
	$pcm_type  = pcm_crm_pm_type( $pcm_project['project_type'] );
	$pcm_files = $pcm_type && ! empty( $pcm_type['time']['resolves_period'] );

	// Time logged before its period existed — every entry on a project that
	// predates this code, or one dated ahead — is filed once the period opens,
	// ahead of the close below that reads it.
	if ( $pcm_created && $pcm_files ) {
		foreach ( $pcm_live as $pcm_period ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$pcm_time} SET retainer_period_id = %d WHERE project_id = %d AND retainer_period_id = 0 AND is_deleted = 0 AND entry_date BETWEEN %s AND %s",
				(int) $pcm_period['id'], $pcm_project_id, $pcm_period['period_start'], $pcm_period['period_end']
			) );
		}
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal
	$pcm_sums = (array) $wpdb->get_results( $wpdb->prepare( "SELECT retainer_period_id AS id, SUM(hours) AS used FROM {$pcm_time} WHERE project_id = %d AND is_deleted = 0 AND retainer_period_id > 0 GROUP BY retainer_period_id", $pcm_project_id ), ARRAY_A );
	$pcm_used = array();

	foreach ( $pcm_sums as $pcm_sum ) {
		$pcm_used[ (int) $pcm_sum['id'] ] = (float) $pcm_sum['used'];
	}

	foreach ( $pcm_live as $pcm_i => $pcm_period ) {
		$pcm_live[ $pcm_i ]['used'] = isset( $pcm_used[ (int) $pcm_period['id'] ] ) ? $pcm_used[ (int) $pcm_period['id'] ] : 0.0;
	}

	foreach ( pcm_crm_retainer_settle( $pcm_live, ! empty( $pcm_project['retainer_rollover'] ), $pcm_today ) as $pcm_op ) {
		// Guarded on is_closed = 0, so only the pass that closes a period
		// writes what it carries — a second, concurrent pass changes nothing.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal
		$pcm_closed = $wpdb->query( $wpdb->prepare(
			"UPDATE {$pcm_table} SET is_closed = 1, closed_date = %s, last_modified_date = %s WHERE id = %d AND is_closed = 0",
			$pcm_now, $pcm_now, $pcm_op['close']
		) );

		if ( 1 === (int) $pcm_closed && $pcm_op['carry_to'] ) {
			$wpdb->update( $pcm_table, array( 'carried_in_hours' => $pcm_op['carry'] ), array( 'id' => $pcm_op['carry_to'] ), array( '%f' ), array( '%d' ) );
		}
	}
}

/**
 * A retainer saved with new dates or hours gets its periods straight away,
 * rather than on the first read.
 */
function pcm_crm_retainer_on_project_save( $pcm_object, $pcm_id ) {
	if ( 'project' === $pcm_object ) {
		pcm_crm_retainer_ensure( $pcm_id, true );
	}
}
add_action( 'pcm_crm_inserted', 'pcm_crm_retainer_on_project_save', 20, 2 );
add_action( 'pcm_crm_updated', 'pcm_crm_retainer_on_project_save', 20, 2 );

/**
 * Once a day, from the quarter-hourly cron, bring every retainer up to date —
 * so a period closes and the next opens even on a project nobody looks at.
 * Periods turn over on day boundaries, so once a day is enough.
 */
function pcm_crm_retainer_sweep() {
	global $wpdb;

	if ( ! pcm_crm_module_active( 'pm' ) ) {
		return;
	}

	$pcm_today = current_time( 'Y-m-d' );

	if ( get_option( 'pcm_crm_retainer_swept' ) === $pcm_today ) {
		return;
	}

	update_option( 'pcm_crm_retainer_swept', $pcm_today, false );

	$pcm_table = pcm_crm_pm_projects_table();

	// Archetype is decided per project by ensure(); this only narrows to rows
	// that could be retainers at all.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal
	$pcm_ids = (array) $wpdb->get_col( "SELECT id FROM {$pcm_table} WHERE is_deleted = 0 AND retainer_hours > 0" );

	foreach ( $pcm_ids as $pcm_id ) {
		pcm_crm_retainer_ensure( (int) $pcm_id );
	}
}
add_action( 'pcm_crm_send_scheduled', 'pcm_crm_retainer_sweep' );
