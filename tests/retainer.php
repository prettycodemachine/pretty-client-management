<?php
/**
 * Retainer period lifecycle — required from run.php, like permissions.php.
 *
 * The pure half (windows, carry, plan, settle) is checked directly. ensure()
 * runs against a small in-memory wpdb that understands only the statements it
 * issues, which is enough to prove the orchestration: open, file, close,
 * carry, and that a second pass changes nothing.
 */

echo "\n--- retainer periods: dates ---\n";
check( 'monthly spans one month', pcm_crm_retainer_months( 'monthly' ), 1 );
check( 'quarterly spans three', pcm_crm_retainer_months( 'quarterly' ), 3 );
check( 'an unknown cadence is monthly', pcm_crm_retainer_months( 'fortnightly' ), 1 );

check( 'a step lands on the anchor day', pcm_crm_retainer_step( '2026-01-15', 1, 15 ), '2026-02-15' );
check( 'a short month takes its last day', pcm_crm_retainer_step( '2026-01-31', 1, 31 ), '2026-02-28' );
check( 'and the anchor returns after it', pcm_crm_retainer_step( '2026-02-28', 1, 31 ), '2026-03-31' );
check( 'a quarter crosses the year', pcm_crm_retainer_step( '2026-11-15', 3, 15 ), '2027-02-15' );
check( 'December steps to January', pcm_crm_retainer_step( '2026-12-01', 1, 1 ), '2027-01-01' );
check( 'a leap February', pcm_crm_retainer_step( '2028-01-30', 1, 30 ), '2028-02-29' );

check( 'windows run anniversary to anniversary, through the one holding today',
	pcm_crm_retainer_windows( '2026-01-15', 15, 1, '2026-03-20' ),
	array( array( '2026-01-15', '2026-02-14' ), array( '2026-02-15', '2026-03-14' ), array( '2026-03-15', '2026-04-14' ) ) );
check( 'a period starting today is opened',
	count( pcm_crm_retainer_windows( '2026-03-15', 15, 1, '2026-03-15' ) ), 1 );
check( 'nothing opens before it starts', pcm_crm_retainer_windows( '2026-04-01', 1, 1, '2026-03-31' ), array() );
check( 'a quarterly window is three months less a day',
	pcm_crm_retainer_windows( '2026-01-01', 1, 3, '2026-01-01' ), array( array( '2026-01-01', '2026-03-31' ) ) );
check( 'a malformed date opens nothing', pcm_crm_retainer_windows( '0000-00-00', 1, 1, '2026-01-01' ), array() );

echo "\n--- retainer periods: carry ---\n";
check( 'no rollover carries nothing', pcm_crm_retainer_carry( 10, 2, 4, false, 8 ), 0.0 );
check( 'unused hours carry', pcm_crm_retainer_carry( 10, 2, 7, true, 8 ), 5.0 );
check( 'up to the cap', pcm_crm_retainer_carry( 10, 2, 1, true, 8 ), 8.0 );
check( 'an overrun carries nothing, not a debt', pcm_crm_retainer_carry( 10, 0, 14, true, 8 ), 0.0 );
check( 'a blank cap is uncapped', pcm_crm_retainer_carry( 10, 5, 0, true, null ), 15.0 );
check( 'and so is a zero one', pcm_crm_retainer_carry( 10, 5, 0, true, '0.00' ), 15.0 );

echo "\n--- retainer periods: plan ---\n";
$pcm_rp = array(
	'retainer_hours' => 10, 'retainer_period' => 'monthly', 'retainer_rollover' => 1,
	'retainer_rollover_cap' => 4, 'retainer_start_date' => '2026-07-10', 'start_date' => '2026-06-01',
	'is_closed' => 0,
);
$pcm_plan = pcm_crm_retainer_plan( $pcm_rp, array(), '2026-09-25' );
check( 'a new retainer opens every period from its start to today',
	array_map( function ( $r ) { return $r['period_start'] . '..' . $r['period_end']; }, $pcm_plan ),
	array( '2026-07-10..2026-08-09', '2026-08-10..2026-09-09', '2026-09-10..2026-10-09' ) );
check( 'allotted from the project', $pcm_plan[0]['allotted_hours'], 10.0 );
check( 'capped from the project', $pcm_plan[0]['rollover_cap_hours'], 4.0 );
check( 'no retainer start falls back to the project start',
	pcm_crm_retainer_plan( array_merge( $pcm_rp, array( 'retainer_start_date' => null ) ), array(), '2026-06-15' )[0]['period_start'], '2026-06-01' );
check( 'no dates at all opens nothing',
	pcm_crm_retainer_plan( array_merge( $pcm_rp, array( 'retainer_start_date' => null, 'start_date' => null ) ), array(), '2026-09-25' ), array() );
check( 'no hours opens nothing',
	pcm_crm_retainer_plan( array_merge( $pcm_rp, array( 'retainer_hours' => 0 ) ), array(), '2026-09-25' ), array() );
check( 'a blank cap is stored blank',
	pcm_crm_retainer_plan( array_merge( $pcm_rp, array( 'retainer_rollover_cap' => null ) ), array(), '2026-07-10' )[0]['rollover_cap_hours'], null );

// Periods seeded on calendar months keep to calendar months whatever the
// retainer start date says, and new hours and cadence start with the next one.
$pcm_seeded = array(
	array( 'period_start' => '2026-08-01', 'period_end' => '2026-08-31' ),
	array( 'period_start' => '2026-09-01', 'period_end' => '2026-09-30' ),
);
check( 'up to date plans nothing', pcm_crm_retainer_plan( $pcm_rp, $pcm_seeded, '2026-09-25' ), array() );
$pcm_plan = pcm_crm_retainer_plan( array_merge( $pcm_rp, array( 'retainer_hours' => 20, 'retainer_period' => 'quarterly' ) ), $pcm_seeded, '2026-10-02' );
check( 'the next period follows the last one, on its anchor, at the new cadence',
	array( $pcm_plan[0]['period_start'], $pcm_plan[0]['period_end'] ), array( '2026-10-01', '2026-12-31' ) );
check( 'with the new allotment', $pcm_plan[0]['allotted_hours'], 20.0 );
check( 'a closed project opens nothing past its end',
	count( pcm_crm_retainer_plan( array_merge( $pcm_rp, array( 'is_closed' => 1, 'actual_end_date' => '2026-08-15' ) ), array(), '2026-09-25' ) ), 2 );
check( 'or past the day it closed, with no end date',
	count( pcm_crm_retainer_plan( array_merge( $pcm_rp, array( 'is_closed' => 1, 'actual_end_date' => null, 'closed_date' => '2026-07-20 10:00:00' ) ), array(), '2026-09-25' ) ), 1 );

echo "\n--- retainer periods: settle ---\n";
$pcm_p = function ( $id, $end, $used, $closed = 0, $carried = 0 ) {
	return array( 'id' => $id, 'period_end' => $end, 'allotted_hours' => 10, 'carried_in_hours' => $carried,
		'rollover_cap_hours' => 4, 'is_closed' => $closed, 'used' => $used );
};
$pcm_ops = pcm_crm_retainer_settle( array( $pcm_p( 1, '2026-08-09', 8 ), $pcm_p( 2, '2026-09-09', 5 ), $pcm_p( 3, '2026-10-09', 0 ) ), true, '2026-09-25' );
check( 'a backfill closes each ended period and carries through the chain', $pcm_ops, array(
	array( 'close' => 1, 'carry_to' => 2, 'carry' => 2.0 ),
	array( 'close' => 2, 'carry_to' => 3, 'carry' => 4.0 ), // 10 + 2 - 5 = 7, capped at 4
) );
check( 'without rollover every carry is zero',
	wp_list_pluck( pcm_crm_retainer_settle( array( $pcm_p( 1, '2026-08-09', 0 ), $pcm_p( 2, '2026-09-09', 0 ) ), false, '2026-09-25' ), 'carry' ), array( 0.0, 0.0 ) );
check( 'a period ending today stays open',
	pcm_crm_retainer_settle( array( $pcm_p( 1, '2026-09-25', 0 ) ), true, '2026-09-25' ), array() );
check( 'a closed period is not closed again',
	pcm_crm_retainer_settle( array( $pcm_p( 1, '2026-08-09', 0, 1 ), $pcm_p( 2, '2026-10-09', 0, 0, 3 ) ), true, '2026-09-25' ), array() );
check( 'the last period closes with nowhere to carry',
	pcm_crm_retainer_settle( array( $pcm_p( 1, '2026-08-09', 0 ) ), true, '2026-09-25' ), array( array( 'close' => 1, 'carry_to' => 0, 'carry' => 0.0 ) ) );
check( 'a successor already closed keeps its frozen carry',
	pcm_crm_retainer_settle( array( $pcm_p( 1, '2026-08-09', 0 ), $pcm_p( 2, '2026-09-09', 0, 1, 1 ) ), true, '2026-09-25' ),
	array( array( 'close' => 1, 'carry_to' => 0, 'carry' => 0.0 ) ) );

echo "\n--- retainer periods: ensure ---\n";

/**
 * Just the statements ensure() issues, over two in-memory tables.
 */
class PCM_Test_Retainer_DB extends FakeWPDB {
	public $project = null;
	public $periods = array();
	public $time    = array();
	public $next    = 100;

	function get_row( $q = '', $o = null ) {
		return false !== strpos( $q, 'projects' ) ? $this->project : null;
	}

	function get_results( $q = '', $o = null ) {
		if ( false !== strpos( $q, 'SUM(hours)' ) ) {
			$out = array();
			foreach ( $this->time as $t ) {
				if ( $t['retainer_period_id'] > 0 && ! $t['is_deleted'] ) {
					$out[ $t['retainer_period_id'] ] = ( isset( $out[ $t['retainer_period_id'] ] ) ? $out[ $t['retainer_period_id'] ] : 0 ) + $t['hours'];
				}
			}
			$rows = array();
			foreach ( $out as $id => $used ) { $rows[] = array( 'id' => $id, 'used' => $used ); }
			return $rows;
		}
		if ( false !== strpos( $q, 'retainer_periods' ) ) {
			$rows = array_values( array_filter( $this->periods, function ( $r ) use ( $q ) {
				return false === strpos( $q, 'is_deleted = 0' ) || ! $r['is_deleted'];
			} ) );
			usort( $rows, function ( $a, $b ) { return strcmp( $a['period_start'], $b['period_start'] ); } );
			return $rows;
		}
		return array();
	}

	function insert( $table = '', $row = array(), $f = null ) {
		foreach ( $this->periods as $p ) {
			if ( $p['period_start'] === $row['period_start'] ) { return false; } // the unique key
		}
		$id = $this->next++;
		$this->periods[ $id ] = array_merge( array( 'rollover_cap_hours' => null, 'is_closed' => 0, 'is_deleted' => 0, 'closed_date' => '0000-00-00 00:00:00' ), $row, array( 'id' => $id ) );
		return 1;
	}

	function update( $table = '', $data = array(), $where = array(), $f = null, $wf = null ) {
		$this->periods[ $where['id'] ] = array_merge( $this->periods[ $where['id'] ], $data );
		return 1;
	}

	function query( $q ) {
		if ( preg_match( "/SET retainer_period_id = (\d+) WHERE .* BETWEEN '([\d-]+)' AND '([\d-]+)'/", $q, $m ) ) {
			$n = 0;
			foreach ( $this->time as $i => $t ) {
				if ( 0 === $t['retainer_period_id'] && $t['entry_date'] >= $m[2] && $t['entry_date'] <= $m[3] ) {
					$this->time[ $i ]['retainer_period_id'] = (int) $m[1];
					$n++;
				}
			}
			return $n;
		}
		if ( preg_match( "/SET is_closed = 1, closed_date = '([^']+)'.* WHERE id = (\d+) AND is_closed = 0/", $q, $m ) ) {
			if ( $this->periods[ $m[2] ]['is_closed'] ) { return 0; }
			$this->periods[ $m[2] ]['is_closed']   = 1;
			$this->periods[ $m[2] ]['closed_date'] = $m[1];
			return 1;
		}
		return false;
	}

	function summary() {
		$out = array();
		foreach ( $this->periods as $p ) {
			$out[] = sprintf( '%s..%s %s+%s%s', $p['period_start'], $p['period_end'], (float) $p['allotted_hours'], (float) $p['carried_in_hours'], $p['is_closed'] ? ' closed' : '' );
		}
		return $out;
	}

	function filed() {
		return array_map( function ( $t ) { return $t['entry_date'] . '=' . $t['retainer_period_id']; }, $this->time );
	}
}

$pcm_real_wpdb   = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = $pcm_db = new PCM_Test_Retainer_DB();
$pcm_db->project = array(
	'id' => 7, 'project_type' => 'Support Retainer', 'owner_id' => 3, 'is_deleted' => 0, 'is_test' => 0,
	'retainer_hours' => 10, 'retainer_period' => 'monthly', 'retainer_rollover' => 1, 'retainer_rollover_cap' => 4,
	'retainer_start_date' => '2026-07-10', 'start_date' => '2026-07-01', 'is_closed' => 0,
);
$pcm_entry = function ( $date, $hours ) { return array( 'entry_date' => $date, 'hours' => $hours, 'retainer_period_id' => 0, 'is_deleted' => 0 ); };
// Time logged before any period existed, as on every real retainer today.
$pcm_db->time = array( $pcm_entry( '2026-06-20', 3 ), $pcm_entry( '2026-07-12', 8 ), $pcm_entry( '2026-08-20', 5 ), $pcm_entry( '2026-09-15', 1 ) );

$GLOBALS['pcm_test_now'] = strtotime( '2026-09-25 10:00:00' );
pcm_crm_retainer_ensure( 7, true );

check( 'a real retainer gets its periods, closed and carried', $pcm_db->summary(), array(
	'2026-07-10..2026-08-09 10+0 closed',
	'2026-08-10..2026-09-09 10+2 closed', // 10 - 8
	'2026-09-10..2026-10-09 10+4',        // 10 + 2 - 5 = 7, capped at 4
) );
check( 'and its existing time is filed under them', $pcm_db->filed(),
	array( '2026-06-20=0', '2026-07-12=100', '2026-08-20=101', '2026-09-15=102' ) );
check( 'closing stamps the date', $pcm_db->periods[100]['closed_date'], '2026-09-25 10:00:00' );
check( 'owned by the project owner', $pcm_db->periods[100]['owner_id'], 3 );

$pcm_before = $pcm_db->summary();
pcm_crm_retainer_ensure( 7, true );
check( 'a second pass changes nothing', $pcm_db->summary(), $pcm_before );

// Late time into a closed period, then a month on with the allotment raised.
$pcm_db->time[] = array_merge( $pcm_entry( '2026-08-01', 6 ), array( 'retainer_period_id' => 100 ) );
$pcm_db->project['retainer_hours'] = 20;
$GLOBALS['pcm_test_now'] = strtotime( '2026-10-12 09:00:00' );
pcm_crm_retainer_ensure( 7, true );

check( 'late time does not recompute a frozen carry; new hours start with the new period', $pcm_db->summary(), array(
	'2026-07-10..2026-08-09 10+0 closed',
	'2026-08-10..2026-09-09 10+2 closed',
	'2026-09-10..2026-10-09 10+4 closed',
	'2026-10-10..2026-11-09 20+4', // 10 + 4 - 1 = 13, capped at 4
) );

$pcm_db->project['retainer_rollover'] = 0;
$GLOBALS['pcm_test_now'] = strtotime( '2026-11-10 09:00:00' );
pcm_crm_retainer_ensure( 7, true );
check( 'with rollover off, the next period carries nothing',
	array_slice( $pcm_db->summary(), -1 ), array( '2026-11-10..2026-12-09 20+0' ) );

$pcm_db->periods = array();
$pcm_db->project['project_type'] = 'Custom Development';
pcm_crm_retainer_ensure( 7, true );
check( 'a project that is not a retainer gets none', $pcm_db->periods, array() );

$pcm_db->project['project_type'] = 'Support Retainer';
pcm_crm_retainer_ensure( 7 );
check( 'ensure() is memoised within a request', $pcm_db->periods, array() );

pcm_crm_retainer_paused( true );
pcm_crm_retainer_ensure( 7, true );
check( 'nothing opens while the sample generator has it paused', $pcm_db->periods, array() );
pcm_crm_retainer_paused( false );
pcm_crm_retainer_ensure( 7, true );
check( 'and runs again once it is released', count( $pcm_db->periods ) > 0, true );

$GLOBALS['wpdb'] = $pcm_real_wpdb;
unset( $GLOBALS['pcm_test_now'] );
