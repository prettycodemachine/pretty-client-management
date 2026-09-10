<?php
/**
 * Opportunity stage history — Salesforce's OpportunityHistory.
 *
 * One row per stage a deal entered, with the moment it entered and the moment
 * it left. The row still open — exited_date NULL — is both the current stage
 * and the thing a transition is detected against, which is why no state needs
 * stashing between the before_ and after_ hooks.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function pcm_crm_history() {
	static $pcm_model = null;

	if ( null === $pcm_model ) {
		$pcm_model = new PCM_CRM_Model(
			'history',
			PCM_CRM_Schema::history(),
			array(
				'sf_id'          => array( 'type' => 'text', 'internal' => true ),
				'opportunity_id' => array( 'type' => 'id', 'sf' => 'OpportunityId', 'label' => 'Opportunity' ),
				'stage_name'     => array( 'type' => 'text', 'sf' => 'StageName', 'label' => 'Stage', 'options' => 'pcm_crm_stage_names' ),
				'previous_stage' => array( 'type' => 'text', 'label' => 'Previous Stage' ),
				'amount'         => array( 'type' => 'decimal', 'sf' => 'Amount', 'label' => 'Amount' ),
				'entered_date'   => array( 'type' => 'datetime', 'sf' => 'CreatedDate', 'label' => 'Entered' ),
				'exited_date'    => array( 'type' => 'datetime', 'label' => 'Exited' ),
				'days_in_stage'  => array( 'type' => 'int', 'label' => 'Days In Stage' ),
				'is_closed'      => array( 'type' => 'bool', 'label' => 'Closed' ),
				'is_won'         => array( 'type' => 'bool', 'label' => 'Won' ),
				'created_by_id'  => array( 'type' => 'id', 'label' => 'Changed By' ),
				'created_date'   => array( 'type' => 'datetime', 'readonly' => true ),
			),
			array( 'stage_name' )
		);
	}

	return $pcm_model;
}

/**
 * How long a deal may sit in one stage before it counts as stalled.
 *
 * A setting rather than a constant because it is a judgement about how this
 * business sells, not a fact about the software.
 */
function pcm_crm_stall_days() {
	$pcm_days = (int) get_option( 'pcm_crm_stall_days', 30 );

	return $pcm_days > 0 ? $pcm_days : 30;
}

/**
 * The still-open history row for a deal — the stage it is in now.
 */
function pcm_crm_current_stage_row( $pcm_opportunity_id ) {
	global $wpdb;

	$pcm_table = PCM_CRM_Schema::history();

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal
	$pcm_row = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$pcm_table} WHERE opportunity_id = %d AND exited_date IS NULL ORDER BY id DESC LIMIT 1",
		absint( $pcm_opportunity_id )
	), ARRAY_A );

	return $pcm_row ? $pcm_row : null;
}

/**
 * Whole days between two MySQL datetimes, never negative.
 *
 * Clamped because a backdated record — the seeder writes plenty — can close a
 * stage before it opened, and a negative age would poison every average that
 * reads it.
 */
function pcm_crm_days_between( $pcm_from, $pcm_to ) {
	// MySQL's zero date does not fail strtotime — it parses to the year zero,
	// which reports as roughly two thousand years in stage. It means "not
	// recorded", so it is caught before the arithmetic rather than after.
	if ( pcm_crm_is_zero_date( $pcm_from ) || pcm_crm_is_zero_date( $pcm_to ) ) {
		return 0;
	}

	$pcm_start = strtotime( (string) $pcm_from );
	$pcm_end   = strtotime( (string) $pcm_to );

	if ( ! $pcm_start || ! $pcm_end ) {
		return 0;
	}

	return max( 0, (int) floor( ( $pcm_end - $pcm_start ) / DAY_IN_SECONDS ) );
}

/**
 * Is this an absent date rather than a real one?
 */
function pcm_crm_is_zero_date( $pcm_value ) {
	return '' === (string) $pcm_value || null === $pcm_value || 0 === strpos( (string) $pcm_value, '0000' );
}

/**
 * Record that a deal is in a stage, opening a history row if it has moved.
 *
 * Idempotent: called with the stage it is already in, it does nothing. That is
 * what lets it hang off every save rather than only off the ones that changed
 * the stage, without needing to know which was which.
 */
function pcm_crm_record_stage( $pcm_opportunity_id, $pcm_stage_name, $pcm_amount = null ) {
	global $wpdb;

	$pcm_opportunity_id = absint( $pcm_opportunity_id );

	if ( ! $pcm_opportunity_id || '' === (string) $pcm_stage_name ) {
		return false;
	}

	$pcm_open = pcm_crm_current_stage_row( $pcm_opportunity_id );

	if ( $pcm_open && $pcm_open['stage_name'] === $pcm_stage_name ) {
		return false;
	}

	$pcm_now     = current_time( 'mysql' );
	$pcm_history = PCM_CRM_Schema::history();
	$pcm_previous = '';

	if ( $pcm_open ) {
		$pcm_previous = $pcm_open['stage_name'];

		$wpdb->update(
			$pcm_history,
			array(
				'exited_date'   => $pcm_now,
				'days_in_stage' => pcm_crm_days_between( $pcm_open['entered_date'], $pcm_now ),
			),
			array( 'id' => (int) $pcm_open['id'] ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}

	$pcm_stage = pcm_crm_stage( $pcm_stage_name );

	pcm_crm_history()->insert( array(
		'opportunity_id' => $pcm_opportunity_id,
		'stage_name'     => $pcm_stage_name,
		'previous_stage' => $pcm_previous,
		'amount'         => $pcm_amount,
		'entered_date'   => $pcm_now,
		'is_closed'      => $pcm_stage ? (int) $pcm_stage['is_closed'] : 0,
		'is_won'         => $pcm_stage ? (int) $pcm_stage['is_won'] : 0,
	) );

	// Denormalised onto the deal so a stalled-deal query is a date comparison
	// rather than a join back through history for every row.
	$pcm_update = array( 'stage_entered_date' => $pcm_now );

	if ( $pcm_stage && ! empty( $pcm_stage['is_closed'] ) ) {
		$pcm_update['closed_date'] = $pcm_now;
	} elseif ( $pcm_stage ) {
		// Reopened: it has no closing moment any more.
		$pcm_update['closed_date'] = '0000-00-00 00:00:00';
	}

	$wpdb->update(
		PCM_CRM_Schema::opportunities(),
		$pcm_update,
		array( 'id' => $pcm_opportunity_id ),
		array_fill( 0, count( $pcm_update ), '%s' ),
		array( '%d' )
	);

	do_action( 'pcm_crm_stage_changed', $pcm_opportunity_id, $pcm_stage_name, $pcm_previous );

	return true;
}

/**
 * Hang the recorder off every opportunity write.
 *
 * Both hooks rather than only the update: a deal is created in a stage, and
 * that first stage is the start of its sales cycle.
 */
function pcm_crm_track_stage( $pcm_object, $pcm_id, $pcm_row ) {
	if ( 'opportunity' !== $pcm_object ) {
		return;
	}

	$pcm_opportunity = pcm_crm_opportunities()->get( $pcm_id );

	if ( ! $pcm_opportunity ) {
		return;
	}

	pcm_crm_record_stage( $pcm_id, $pcm_opportunity['stage_name'], $pcm_opportunity['amount'] );
}
add_action( 'pcm_crm_inserted', 'pcm_crm_track_stage', 10, 3 );
add_action( 'pcm_crm_updated', 'pcm_crm_track_stage', 10, 3 );

/**
 * A deal's stages, oldest first, with the open one still running.
 */
function pcm_crm_stage_history_for( $pcm_opportunity_id ) {
	$pcm_rows = pcm_crm_history()->find( array(
		'filters'  => array( 'opportunity_id' => absint( $pcm_opportunity_id ) ),
		'orderby'  => 'entered_date',
		'order'    => 'ASC',
		'per_page' => 100,
	) );

	$pcm_now = current_time( 'mysql' );

	foreach ( $pcm_rows as $pcm_i => $pcm_row ) {
		$pcm_open = empty( $pcm_row['exited_date'] );

		$pcm_rows[ $pcm_i ]['_is_current'] = $pcm_open ? 1 : 0;
		$pcm_rows[ $pcm_i ]['_days']       = $pcm_open
			? pcm_crm_days_between( $pcm_row['entered_date'], $pcm_now )
			: (int) $pcm_row['days_in_stage'];
		$pcm_rows[ $pcm_i ]['_changed_by'] = pcm_crm_user_name( $pcm_row['created_by_id'] );
	}

	return $pcm_rows;
}
