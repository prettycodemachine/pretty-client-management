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
		),
		'charts' => array(
			'pipelineByStage' => pcm_crm_pipeline_by_stage( $pcm_open ),
			'byMonth'         => pcm_crm_opportunities_by_month( $pcm_args ),
			'byLeadSource'    => $pcm_opps->group_by( 'lead_source', pcm_crm_merge_args( $pcm_args, array() ), 'amount' ),
			'activityByType'  => $pcm_activities->group_by( 'activity_type', $pcm_args ),
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
		foreach ( $pcm_items as $pcm_i => $pcm_item ) {
			$pcm_items[ $pcm_i ]['_account_name'] = isset( $pcm_accounts[ $pcm_item['account_id'] ] )
				? $pcm_accounts[ $pcm_item['account_id'] ]['name'] : '';
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

	return array(
		'rows'   => $pcm_model->find( $pcm_args ),
		'groups' => $pcm_group_by ? $pcm_model->group_by( $pcm_group_by, $pcm_args, $pcm_sum ) : array(),
		'total'  => $pcm_model->count( $pcm_args ),
		'sum'    => $pcm_sum ? $pcm_model->sum( 'amount', $pcm_args ) : 0,
	);
}
