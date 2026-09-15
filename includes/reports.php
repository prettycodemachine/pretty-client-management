<?php
/**
 * Aggregates behind the dashboard, the pipeline board and the report builder.
 *
 * Every figure here runs through the same model filters a list view uses, so a
 * chart and the records behind it can never disagree — clicking through from a
 * tile to a filtered list has to land on the same rows that were counted.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Merge the caller's filters into a base set without letting either clobber
 * the other's keys silently.
 */
function pcm_crm_merge_args( array $pcm_args, array $pcm_extra_filters ) {
	$pcm_filters = isset( $pcm_args['filters'] ) && is_array( $pcm_args['filters'] ) ? $pcm_args['filters'] : array();

	$pcm_args['filters']  = array_merge( $pcm_filters, $pcm_extra_filters );
	$pcm_args['per_page'] = 0;

	return $pcm_args;
}

/**
 * The dashboard payload: KPI tiles plus the four charts.
 */
function pcm_crm_dashboard_data( array $pcm_args = array() ) {
	$pcm_opps       = pcm_crm_opportunities();
	$pcm_contacts   = pcm_crm_contacts();
	$pcm_activities = pcm_crm_activities();

	$pcm_open   = pcm_crm_merge_args( $pcm_args, array( 'is_closed' => 0 ) );
	$pcm_won    = pcm_crm_merge_args( $pcm_args, array( 'is_won' => 1 ) );
	$pcm_closed = pcm_crm_merge_args( $pcm_args, array( 'is_closed' => 1 ) );

	$pcm_won_count    = $pcm_opps->count( $pcm_won );
	$pcm_closed_count = $pcm_opps->count( $pcm_closed );

	// Overdue means a due date in the past on something not finished. Compared
	// in site time, not UTC, or a deadline flips to overdue hours early.
	$pcm_overdue = pcm_crm_merge_args( $pcm_args, array(
		'is_completed' => 0,
		'due_date'     => array( 'max' => current_time( 'Y-m-d' ) ),
	) );

	return array(
		'tiles' => array(
			'openValue'      => $pcm_opps->sum( 'amount', $pcm_open ),
			'openCount'      => $pcm_opps->count( $pcm_open ),
			'weightedValue'  => pcm_crm_weighted_pipeline( $pcm_open ),
			'wonValue'       => $pcm_opps->sum( 'amount', $pcm_won ),
			'wonCount'       => $pcm_won_count,
			// Win rate is won over everything closed, not over everything —
			// open deals have not lost, and counting them drags it to zero.
			'winRate'        => $pcm_closed_count ? round( ( $pcm_won_count / $pcm_closed_count ) * 100 ) : 0,
			'contactCount'   => $pcm_contacts->count( $pcm_args ),
			'accountCount'   => pcm_crm_accounts()->count( $pcm_args ),
			'overdueCount'   => $pcm_activities->count( $pcm_overdue ),
			'cycleDays'      => pcm_crm_sales_cycle_days( $pcm_args ),
			'stalledCount'   => $pcm_opps->count( pcm_crm_stalled_args( $pcm_args ) ),
			'stallDays'      => pcm_crm_stall_days(),
		),
		'charts' => array(
			'pipelineByStage' => pcm_crm_pipeline_by_stage( $pcm_open ),
			'byMonth'         => pcm_crm_opportunities_by_month( $pcm_args ),
			'byLeadSource'    => $pcm_opps->group_by( 'lead_source', pcm_crm_merge_args( $pcm_args, array() ), 'amount' ),
			'activityByType'  => $pcm_activities->group_by( 'activity_type', $pcm_args ),
			'avgDaysByStage'  => pcm_crm_avg_days_by_stage(),
			'conversion'      => pcm_crm_stage_conversion(),
		),
	);
}

/**
 * Pipeline value discounted by each stage's probability.
 *
 * Summed per stage rather than per record: probability is a property of the
 * stage, so one query per stage beats loading every open opportunity to
 * multiply them one at a time.
 */
function pcm_crm_weighted_pipeline( array $pcm_args ) {
	$pcm_total = 0.0;
	$pcm_opps  = pcm_crm_opportunities();

	foreach ( pcm_crm_open_stages() as $pcm_stage ) {
		$pcm_sum    = $pcm_opps->sum( 'amount', pcm_crm_merge_args( $pcm_args, array( 'stage_name' => $pcm_stage['name'] ) ) );
		$pcm_total += $pcm_sum * ( (int) $pcm_stage['probability'] / 100 );
	}

	return round( $pcm_total, 2 );
}

/**
 * Count and value per open stage, in pipeline order.
 *
 * Ordered by the stage list rather than by size, and including empty stages,
 * because a funnel with a gap in it is the useful signal.
 */
function pcm_crm_pipeline_by_stage( array $pcm_args ) {
	$pcm_opps = pcm_crm_opportunities();
	$pcm_out  = array();

	foreach ( pcm_crm_open_stages() as $pcm_stage ) {
		$pcm_stage_args = pcm_crm_merge_args( $pcm_args, array( 'stage_name' => $pcm_stage['name'] ) );

		$pcm_out[] = array(
			'value' => $pcm_stage['name'],
			'count' => $pcm_opps->count( $pcm_stage_args ),
			'total' => $pcm_opps->sum( 'amount', $pcm_stage_args ),
		);
	}

	return $pcm_out;
}

/**
 * Opportunities created and won by month, for the trend line.
 *
 * Two grouped queries rather than a join: created and won are counted on
 * different dates, and MySQL cannot group one result set by both.
 */
function pcm_crm_opportunities_by_month( array $pcm_args, $pcm_months = 12 ) {
	global $wpdb;

	$pcm_opps  = pcm_crm_opportunities();
	$pcm_table = $pcm_opps->table();

	$pcm_since = gmdate( 'Y-m-01 00:00:00', strtotime( '-' . ( (int) $pcm_months - 1 ) . ' months', current_time( 'timestamp' ) ) );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal
	$pcm_created = $wpdb->get_results( $wpdb->prepare(
		"SELECT DATE_FORMAT(created_date, '%%Y-%%m') AS month, COUNT(*) AS count, COALESCE(SUM(amount),0) AS total
		 FROM {$pcm_table} WHERE is_deleted = 0 AND created_date >= %s
		 GROUP BY month ORDER BY month ASC",
		$pcm_since
	), ARRAY_A );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal
	$pcm_won = $wpdb->get_results( $wpdb->prepare(
		"SELECT DATE_FORMAT(close_date, '%%Y-%%m') AS month, COUNT(*) AS count, COALESCE(SUM(amount),0) AS total
		 FROM {$pcm_table} WHERE is_deleted = 0 AND is_won = 1 AND close_date >= %s
		 GROUP BY month ORDER BY month ASC",
		substr( $pcm_since, 0, 10 )
	), ARRAY_A );

	$pcm_index = array();
	foreach ( (array) $pcm_created as $pcm_row ) {
		$pcm_index[ $pcm_row['month'] ]['created'] = (int) $pcm_row['count'];
	}
	foreach ( (array) $pcm_won as $pcm_row ) {
		$pcm_index[ $pcm_row['month'] ]['won']   = (int) $pcm_row['count'];
		$pcm_index[ $pcm_row['month'] ]['value'] = (float) $pcm_row['total'];
	}

	// Every month in the window is emitted, empty or not, so the line reads as
	// a flat stretch rather than skipping straight over a quiet quarter.
	$pcm_out = array();
	for ( $pcm_i = (int) $pcm_months - 1; $pcm_i >= 0; $pcm_i-- ) {
		$pcm_key = gmdate( 'Y-m', strtotime( '-' . $pcm_i . ' months', current_time( 'timestamp' ) ) );

		$pcm_out[] = array(
			'month'   => $pcm_key,
			'label'   => gmdate( 'M', strtotime( $pcm_key . '-01' ) ),
			'created' => isset( $pcm_index[ $pcm_key ]['created'] ) ? $pcm_index[ $pcm_key ]['created'] : 0,
			'won'     => isset( $pcm_index[ $pcm_key ]['won'] ) ? $pcm_index[ $pcm_key ]['won'] : 0,
			'value'   => isset( $pcm_index[ $pcm_key ]['value'] ) ? $pcm_index[ $pcm_key ]['value'] : 0,
		);
	}

	return $pcm_out;
}

/**
 * The kanban board: one column per open stage, plus the closed stages so a
 * card has somewhere to be dropped when the deal lands.
 */
function pcm_crm_pipeline_data( array $pcm_args = array() ) {
	$pcm_opps    = pcm_crm_opportunities();
	$pcm_columns = array();

	foreach ( pcm_crm_stages() as $pcm_stage ) {
		$pcm_stage_args            = pcm_crm_merge_args( $pcm_args, array( 'stage_name' => $pcm_stage['name'] ) );
		$pcm_stage_args['orderby'] = 'close_date';
		$pcm_stage_args['order']   = 'ASC';

		// Closed columns hold the recent landings only. All of history would
		// swamp the board and none of it is actionable.
		if ( ! empty( $pcm_stage['is_closed'] ) ) {
			$pcm_stage_args['per_page'] = 20;
			$pcm_stage_args['orderby']  = 'last_modified_date';
			$pcm_stage_args['order']    = 'DESC';
		}

		$pcm_items = $pcm_opps->find( $pcm_stage_args );

		$pcm_accounts = pcm_crm_accounts()->get_many( wp_list_pluck( $pcm_items, 'account_id' ) );
		$pcm_now      = current_time( 'mysql' );
		$pcm_stall    = pcm_crm_stall_days();

		foreach ( $pcm_items as $pcm_i => $pcm_item ) {
			$pcm_items[ $pcm_i ]['_account_name'] = isset( $pcm_accounts[ $pcm_item['account_id'] ] )
				? $pcm_accounts[ $pcm_item['account_id'] ]['name'] : '';

			// The board's whole purpose is spotting what is not moving, so
			// every card carries its own age in the column it sits in.
			$pcm_days = pcm_crm_days_between( $pcm_item['stage_entered_date'], $pcm_now );

			$pcm_items[ $pcm_i ]['_days_in_stage'] = $pcm_days;
			$pcm_items[ $pcm_i ]['_is_stalled']    = ( empty( $pcm_stage['is_closed'] ) && $pcm_days >= $pcm_stall ) ? 1 : 0;
		}

		$pcm_columns[] = array(
			'stage'    => $pcm_stage['name'],
			'isClosed' => (int) $pcm_stage['is_closed'],
			'isWon'    => (int) $pcm_stage['is_won'],
			'count'    => $pcm_opps->count( pcm_crm_merge_args( $pcm_args, array( 'stage_name' => $pcm_stage['name'] ) ) ),
			'total'    => $pcm_opps->sum( 'amount', pcm_crm_merge_args( $pcm_args, array( 'stage_name' => $pcm_stage['name'] ) ) ),
			'items'    => $pcm_items,
		);
	}

	return array( 'columns' => $pcm_columns );
}

/**
 * The report builder: rows grouped by any column of any object.
 */
function pcm_crm_run_report( $pcm_object, $pcm_group_by, array $pcm_args = array() ) {
	$pcm_model = PCM_CRM_REST::model( $pcm_object );

	if ( ! $pcm_model ) {
		return array( 'rows' => array(), 'groups' => array(), 'total' => 0 );
	}

	$pcm_args['per_page'] = isset( $pcm_args['per_page'] ) && $pcm_args['per_page'] ? $pcm_args['per_page'] : 200;

	$pcm_sum = $pcm_model->has_field( 'amount' ) ? 'amount' : '';

	// Expanded the same way the list endpoint is — otherwise a report's Account
	// and Owner columns (and any other lookup) come back blank, since raw rows
	// carry only the foreign key id.
	return array(
		'rows'   => PCM_CRM_REST::expand( $pcm_model->object(), $pcm_model->find( $pcm_args ) ),
		'groups' => $pcm_group_by ? $pcm_model->group_by( $pcm_group_by, $pcm_args, $pcm_sum ) : array(),
		'total'  => $pcm_model->count( $pcm_args ),
		'sum'    => $pcm_sum ? $pcm_model->sum( 'amount', $pcm_args ) : 0,
	);
}

/* ---------------------------------------------------------------------------
   Stage history metrics
   --------------------------------------------------------------------------- */

/**
 * Deals sitting in one stage longer than the configured threshold.
 *
 * Open deals only — a closed one is not stalled, it is finished — and measured
 * from stage_entered_date, which the history recorder maintains.
 */
function pcm_crm_stalled_args( array $pcm_args = array() ) {
	$pcm_cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . pcm_crm_stall_days() . ' days', current_time( 'timestamp' ) ) );

	return pcm_crm_merge_args( $pcm_args, array(
		'is_closed'          => 0,
		'stage_entered_date' => array( 'max' => $pcm_cutoff ),
	) );
}

/**
 * Average days between creation and closing, for deals that actually closed.
 *
 * Won deals only by default: a loss can close in a day because it was never
 * real, and averaging those in makes the cycle look shorter than it sells.
 */
function pcm_crm_sales_cycle_days( array $pcm_args = array(), $pcm_won_only = true ) {
	global $wpdb;

	$pcm_model = pcm_crm_opportunities();
	$pcm_extra = $pcm_won_only ? array( 'is_won' => 1 ) : array( 'is_closed' => 1 );

	$pcm_where = $pcm_model->where( pcm_crm_merge_args( $pcm_args, $pcm_extra ) );

	$pcm_table = $pcm_model->table();

	// Rows with no recorded closing moment are excluded rather than counted as
	// zero-day cycles, which would drag the average toward nothing.
	$pcm_clause = $pcm_where ? $pcm_where . " AND closed_date > '0000-00-00 00:00:00'" : "WHERE closed_date > '0000-00-00 00:00:00'";

	// phpcs:ignore WordPress.DB.PreparedSQL -- clauses built and escaped by the model
	$pcm_avg = $wpdb->get_var( "SELECT AVG(DATEDIFF(closed_date, created_date)) FROM {$pcm_table} {$pcm_clause}" );

	return null === $pcm_avg ? 0 : (int) round( (float) $pcm_avg );
}

/**
 * Average days spent in each stage, from the history rows that have closed.
 *
 * Only exited rows count: a deal still sitting in Proposal has not finished
 * telling us how long Proposal takes, and including it would report an average
 * that falls whenever a new deal arrives.
 */
function pcm_crm_avg_days_by_stage() {
	global $wpdb;

	$pcm_table = PCM_CRM_Schema::history();
	$pcm_opps  = PCM_CRM_Schema::opportunities();

	// Joined to the opportunities rather than read alone: a history row whose
	// deal has been deleted is not evidence about how long a stage takes, and
	// one whose deal never existed at all — an orphan from a removed sample
	// set — is not evidence about anything.
	// phpcs:ignore WordPress.DB.PreparedSQL -- table names are internal
	$pcm_rows = $wpdb->get_results(
		"SELECT h.stage_name, AVG(h.days_in_stage) AS avg_days, COUNT(*) AS count
		 FROM {$pcm_table} h
		 INNER JOIN {$pcm_opps} o ON o.id = h.opportunity_id AND o.is_deleted = 0
		 WHERE h.exited_date IS NOT NULL AND h.days_in_stage IS NOT NULL
		 GROUP BY h.stage_name",
		ARRAY_A
	);

	$pcm_index = array();
	foreach ( (array) $pcm_rows as $pcm_row ) {
		$pcm_index[ $pcm_row['stage_name'] ] = array(
			'days'  => (int) round( (float) $pcm_row['avg_days'] ),
			'count' => (int) $pcm_row['count'],
		);
	}

	// Emitted in pipeline order, including stages nothing has left yet, so the
	// chart reads as a funnel rather than as whichever stages had data.
	$pcm_out = array();
	foreach ( pcm_crm_stages() as $pcm_stage ) {
		$pcm_name = $pcm_stage['name'];

		$pcm_out[] = array(
			'value' => $pcm_name,
			'count' => isset( $pcm_index[ $pcm_name ] ) ? $pcm_index[ $pcm_name ]['count'] : 0,
			'total' => isset( $pcm_index[ $pcm_name ] ) ? $pcm_index[ $pcm_name ]['days'] : 0,
		);
	}

	return $pcm_out;
}

/**
 * Stage-to-stage conversion.
 *
 * Counts distinct deals that ever *entered* each stage, which is what history
 * is for — a deal now in Negotiation entered Proposal on the way, and asking
 * the opportunities table alone would only ever see where things are now.
 *
 * The rate on a stage is the share of deals reaching it that went on to reach
 * the next open stage; the last open stage converts to won.
 */
function pcm_crm_stage_conversion() {
	global $wpdb;

	$pcm_table = PCM_CRM_Schema::history();
	$pcm_opps  = PCM_CRM_Schema::opportunities();

	// Same join, and for a sharper reason: this counts distinct deals per
	// stage, so a row pointing at a deal that no longer exists inflates every
	// rate it appears in.
	// phpcs:ignore WordPress.DB.PreparedSQL -- table names are internal
	$pcm_rows = $wpdb->get_results(
		"SELECT h.stage_name, COUNT(DISTINCT h.opportunity_id) AS deals
		 FROM {$pcm_table} h
		 INNER JOIN {$pcm_opps} o ON o.id = h.opportunity_id AND o.is_deleted = 0
		 GROUP BY h.stage_name",
		ARRAY_A
	);

	$pcm_entered = array();
	foreach ( (array) $pcm_rows as $pcm_row ) {
		$pcm_entered[ $pcm_row['stage_name'] ] = (int) $pcm_row['deals'];
	}

	$pcm_open = pcm_crm_open_stages();
	$pcm_out  = array();

	foreach ( $pcm_open as $pcm_i => $pcm_stage ) {
		$pcm_name = $pcm_stage['name'];
		$pcm_here = isset( $pcm_entered[ $pcm_name ] ) ? $pcm_entered[ $pcm_name ] : 0;

		$pcm_next_stage = isset( $pcm_open[ $pcm_i + 1 ] ) ? $pcm_open[ $pcm_i + 1 ]['name'] : pcm_crm_won_stage_name();
		$pcm_next       = ( $pcm_next_stage && isset( $pcm_entered[ $pcm_next_stage ] ) ) ? $pcm_entered[ $pcm_next_stage ] : 0;

		$pcm_out[] = array(
			'stage' => $pcm_name,
			'next'  => $pcm_next_stage,
			'deals' => $pcm_here,
			'moved' => $pcm_next,
			// Capped at 100: a deal can re-enter a stage, and a later stage
			// holding more deals than an earlier one would otherwise report a
			// conversion above everything, which reads as a bug.
			'rate'  => $pcm_here ? min( 100, (int) round( ( $pcm_next / $pcm_here ) * 100 ) ) : 0,
		);
	}

	return $pcm_out;
}

function pcm_crm_won_stage_name() {
	foreach ( pcm_crm_stages() as $pcm_stage ) {
		if ( ! empty( $pcm_stage['is_won'] ) ) {
			return $pcm_stage['name'];
		}
	}

	return '';
}
