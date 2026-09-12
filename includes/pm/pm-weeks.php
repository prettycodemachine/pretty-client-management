<?php
/**
 * Week bucketing for the resourcing board.
 *
 * One function decides where a week starts, and allocations are normalised
 * through it on write. That is the whole point: if a writer and a reader
 * disagreed about the boundary, allocations would land between the board's
 * columns and simply not be drawn.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The start of the week a date falls in, as Y-m-d.
 *
 * Honours the site's start_of_week so a board matches the calendar the rest of
 * the site shows, rather than assuming Monday.
 *
 * Arithmetic is done on a UTC noon timestamp: midnight plus a day repeated over
 * a DST boundary can land on the previous day in a zone that springs forward at
 * midnight, which would produce a six-day week.
 */
function pcm_crm_pm_week_start( $pcm_date ) {
	$pcm_date = substr( trim( (string) $pcm_date ), 0, 10 );

	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $pcm_date ) ) {
		return null;
	}

	$pcm_time = strtotime( $pcm_date . ' 12:00:00 UTC' );

	if ( ! $pcm_time ) {
		return null;
	}

	$pcm_starts_on = (int) get_option( 'start_of_week', 1 );
	$pcm_weekday   = (int) gmdate( 'w', $pcm_time );

	// Days to step back to reach the configured first day, wrapping the week
	// rather than going negative.
	$pcm_back = ( $pcm_weekday - $pcm_starts_on + 7 ) % 7;

	return gmdate( 'Y-m-d', $pcm_time - ( $pcm_back * DAY_IN_SECONDS ) );
}

/**
 * A run of consecutive week starts.
 */
function pcm_crm_pm_weeks( $pcm_from, $pcm_count ) {
	$pcm_start = pcm_crm_pm_week_start( $pcm_from );

	if ( ! $pcm_start ) {
		return array();
	}

	$pcm_time  = strtotime( $pcm_start . ' 12:00:00 UTC' );
	$pcm_weeks = array();

	for ( $pcm_i = 0; $pcm_i < max( 0, (int) $pcm_count ); $pcm_i++ ) {
		$pcm_weeks[] = gmdate( 'Y-m-d', $pcm_time + ( $pcm_i * 7 * DAY_IN_SECONDS ) );
	}

	return $pcm_weeks;
}

/**
 * The week starts a date range touches.
 *
 * What the date-range allocation form writes: a range of any length becomes one
 * row per week it overlaps, including a partial week at either end — a
 * Wednesday-to-Wednesday booking occupies two weeks, and both should show.
 */
function pcm_crm_pm_expand_range( $pcm_from, $pcm_to ) {
	$pcm_start = pcm_crm_pm_week_start( $pcm_from );
	$pcm_end   = pcm_crm_pm_week_start( $pcm_to );

	if ( ! $pcm_start || ! $pcm_end || $pcm_end < $pcm_start ) {
		return array();
	}

	$pcm_time  = strtotime( $pcm_start . ' 12:00:00 UTC' );
	$pcm_last  = strtotime( $pcm_end . ' 12:00:00 UTC' );
	$pcm_weeks = array();

	while ( $pcm_time <= $pcm_last ) {
		$pcm_weeks[] = gmdate( 'Y-m-d', $pcm_time );
		$pcm_time   += 7 * DAY_IN_SECONDS;
	}

	return $pcm_weeks;
}
