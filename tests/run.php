<?php
require __DIR__ . '/wp-stubs.php';

$fail = 0;
function check( $label, $got, $want ) {
	global $fail;
	$ok = $got === $want;
	if ( ! $ok ) { $fail++; }
	printf( "%s %s\n    got: %s\n   want: %s\n", $ok ? 'PASS' : 'FAIL', $label,
		var_export( $got, true ), var_export( $want, true ) );
}

echo "--- account name normalisation ---\n";
check( 'strips case and punctuation', pcm_crm_account_key( 'The Smith Trust.' ), pcm_crm_account_key( 'the smith trust' ) );
check( 'strips legal suffix', pcm_crm_account_key( 'Acme Inc.' ), 'acme' );
check( 'collapses whitespace', pcm_crm_account_key( "  Green   Mountain  LLC " ), 'green mountain' );
check( 'different orgs stay different', pcm_crm_account_key( 'Acme' ) === pcm_crm_account_key( 'Acorn' ), false );

echo "\n--- email tokens ---\n";
$fields = array( 'first' => 'Ada', 'last' => 'Lovelace', 'org' => 'Analytical & Co', 'email' => 'a@b.c', 'interest' => 'AI Enablement' );
check( 'fills full name', pcm_crm_fill_tokens( 'Hi {{FULL NAME}}', $fields ), 'Hi Ada Lovelace' );
check( 'underscored spelling works', pcm_crm_fill_tokens( '{{FIRST_NAME}}', $fields ), 'Ada' );
check( 'escapes injected markup',
	pcm_crm_fill_tokens( '{{ORGANIZATION}}', array( 'first' => '', 'last' => '', 'org' => '<script>x</script>', 'email' => '', 'interest' => '' ) ),
	'&lt;script&gt;x&lt;/script&gt;' );

echo "\n--- email body formatting ---\n";
$formatted = pcm_crm_format_body( "Dear Ada,\n\nThanks for getting in touch.\n\nTalk soon,\nJason" );
check( 'blank lines become paragraphs', substr_count( $formatted, '<p>' ), 3 );
check( 'a single newline becomes a break', strpos( $formatted, 'Talk soon,<br />' ) !== false, true );
check( 'no stray paragraph from trailing blank lines',
	substr_count( pcm_crm_format_body( "One\n\nTwo\n\n\n" ), '<p>' ), 2 );
check( 'the shipped default has no pre-tagged paragraphs',
	strpos( pcm_crm_autoresponder_default_body(), '<p>' ), false );
check( 'the shipped default still carries its links',
	substr_count( pcm_crm_autoresponder_default_body(), '<a href=' ), 2 );

echo "\n--- picklists ---\n";
$stage = pcm_crm_stage( 'Closed Won' );
check( 'Closed Won is closed', (int) $stage['is_closed'], 1 );
check( 'Closed Won is won', (int) $stage['is_won'], 1 );
check( 'Closed Lost is not won', (int) pcm_crm_stage( 'Closed Lost' )['is_won'], 0 );
check( 'open stages exclude closed', count( pcm_crm_open_stages() ), 4 );
check( 'unknown stage returns null', pcm_crm_stage( 'Nonsense' ), null );

echo "\n--- stage side effects ---\n";
$row = pcm_crm_apply_stage( array( 'stage_name' => 'Closed Won' ), 'opportunity' );
check( 'sets is_closed', $row['is_closed'], 1 );
check( 'sets probability', $row['probability'], 100 );
check( 'sets forecast category', $row['forecast_category'], 'Closed' );
check( 'leaves other objects alone', pcm_crm_apply_stage( array( 'stage_name' => 'Closed Won' ), 'contact' ), array( 'stage_name' => 'Closed Won' ) );

echo "\n--- closed lost needs a reason ---\n";
check( 'Closed Lost is recognised as a loss', pcm_crm_stage_is_lost( 'Closed Lost' ), true );
check( 'Closed Won is not', pcm_crm_stage_is_lost( 'Closed Won' ), false );
check( 'an open stage is not', pcm_crm_stage_is_lost( 'Proposal' ), false );
check( 'an unknown stage is not', pcm_crm_stage_is_lost( 'Nonsense' ), false );

check( 'losing without a reason is refused',
	is_wp_error( pcm_crm_validate_opportunity( null, 'opportunity', array( 'stage_name' => 'Closed Lost' ), 0 ) ), true );
check( 'whitespace is not a reason',
	is_wp_error( pcm_crm_validate_opportunity( null, 'opportunity', array( 'stage_name' => 'Closed Lost', 'closed_lost_reason' => '  ' ), 0 ) ), true );
check( 'losing with a reason is allowed',
	is_wp_error( pcm_crm_validate_opportunity( null, 'opportunity', array( 'stage_name' => 'Closed Lost', 'closed_lost_reason' => 'Budget pulled.' ), 0 ) ), false );
check( 'winning needs no reason',
	is_wp_error( pcm_crm_validate_opportunity( null, 'opportunity', array( 'stage_name' => 'Closed Won' ), 0 ) ), false );
check( 'an open stage needs no reason',
	is_wp_error( pcm_crm_validate_opportunity( null, 'opportunity', array( 'stage_name' => 'Proposal' ), 0 ) ), false );
check( 'other objects are not subject to the rule',
	is_wp_error( pcm_crm_validate_opportunity( null, 'contact', array( 'stage_name' => 'Closed Lost' ), 0 ) ), false );

check( 'reopening a deal drops the reason',
	pcm_crm_clear_lost_reason( array( 'stage_name' => 'Proposal', 'closed_lost_reason' => 'stale' ), 'opportunity' )['closed_lost_reason'], '' );
check( 'the reason survives while the deal is lost',
	pcm_crm_clear_lost_reason( array( 'stage_name' => 'Closed Lost', 'closed_lost_reason' => 'Budget pulled.' ), 'opportunity' )['closed_lost_reason'], 'Budget pulled.' );
check( 'winning also drops it',
	pcm_crm_clear_lost_reason( array( 'stage_name' => 'Closed Won', 'closed_lost_reason' => 'stale' ), 'opportunity' )['closed_lost_reason'], '' );

echo "\n--- probability follows the stage ---\n";
foreach ( array( 'Qualification' => 10, 'Discovery' => 25, 'Proposal' => 50, 'Negotiation' => 75, 'Closed Won' => 100, 'Closed Lost' => 0 ) as $name => $expected ) {
	check( $name . ' carries ' . $expected . '%',
		pcm_crm_apply_stage( array( 'stage_name' => $name ), 'opportunity' )['probability'], $expected );
}
check( 'a posted probability cannot override the stage on a new record',
	pcm_crm_apply_stage( array( 'stage_name' => 'Proposal', 'probability' => 99 ), 'opportunity' )['probability'], 50 );

echo "\n--- service interest ---\n";
check( 'contacts store what was asked about', pcm_crm_contacts()->has_field( 'service_interest' ), true );
check( 'opportunities do too', pcm_crm_opportunities()->has_field( 'service_interest' ), true );
check( 'the picklist is the labels the form posts',
	pcm_crm_interest_labels(), array_values( pcm_crm_interest_options() ) );
check( 'and includes the catch-all', in_array( 'Something else', pcm_crm_interest_labels(), true ), true );

echo "\n--- activity status sync ---\n";
check( 'Completed implies is_completed',
	pcm_crm_sync_activity_status( array( 'status' => 'Completed' ), 'activity' )['is_completed'], 1 );
check( 'is_completed implies Completed',
	pcm_crm_sync_activity_status( array( 'is_completed' => 1 ), 'activity' )['status'], 'Completed' );
check( 'explicit status beats the checkbox',
	pcm_crm_sync_activity_status( array( 'status' => 'In Progress', 'is_completed' => 1 ), 'activity' )['is_completed'], 0 );
check( 'unticking alone leaves an unrelated status alone',
	isset( pcm_crm_sync_activity_status( array( 'is_completed' => 0 ), 'activity' )['status'] ), false );
check( 'other objects untouched',
	pcm_crm_sync_activity_status( array( 'status' => 'Completed' ), 'account' ), array( 'status' => 'Completed' ) );

echo "\n--- model sanitising ---\n";
$opps = pcm_crm_opportunities();
$clean = $opps->sanitize( array(
	'name' => ' A deal ', 'amount' => '$12,500.50', 'close_date' => '2026-03-04T00:00:00',
	'probability' => '40', 'is_won' => 1, 'nonsense_field' => 'x',
) );
check( 'trims text', $clean['name'], 'A deal' );
check( 'parses formatted currency', $clean['amount'], 12500.5 );
check( 'truncates date', $clean['close_date'], '2026-03-04' );
check( 'drops unknown fields', isset( $clean['nonsense_field'] ), false );
check( 'drops readonly fields', isset( $clean['is_won'] ), false );
check( 'empty amount stays null', $opps->sanitize( array( 'amount' => '' ) )['amount'], null );
check( 'bad date becomes null', $opps->sanitize( array( 'close_date' => 'soon' ) )['close_date'], null );

echo "\n--- do not contact ---\n";
$contacts = pcm_crm_contacts();
check( 'the reason field exists', $contacts->has_field( 'do_not_contact_reason' ), true );

$blocked = pcm_crm_validate_contact( null, 'contact', array( 'do_not_contact' => 1, 'do_not_contact_reason' => '' ), 0 );
check( 'flagging without a reason is refused', is_wp_error( $blocked ), true );

$blank = pcm_crm_validate_contact( null, 'contact', array( 'do_not_contact' => 1, 'do_not_contact_reason' => '   ' ), 0 );
check( 'whitespace is not a reason', is_wp_error( $blank ), true );

$allowed = pcm_crm_validate_contact( null, 'contact', array( 'do_not_contact' => 1, 'do_not_contact_reason' => 'Asked to be removed' ), 0 );
check( 'flagging with a reason is allowed', is_wp_error( $allowed ), false );

$off = pcm_crm_validate_contact( null, 'contact', array( 'do_not_contact' => 0 ), 0 );
check( 'an unflagged contact needs no reason', is_wp_error( $off ), false );

check( 'other objects are not subject to the rule',
	is_wp_error( pcm_crm_validate_contact( null, 'account', array( 'do_not_contact' => 1 ), 0 ) ), false );

check( 'clearing the flag clears the reason',
	pcm_crm_clear_dnc_reason( array( 'do_not_contact' => 0, 'do_not_contact_reason' => 'stale' ), 'contact' )['do_not_contact_reason'], '' );
check( 'the reason survives while the flag stands',
	pcm_crm_clear_dnc_reason( array( 'do_not_contact' => 1, 'do_not_contact_reason' => 'Asked to be removed' ), 'contact' )['do_not_contact_reason'],
	'Asked to be removed' );

echo "\n--- contact form lead source ---\n";
check( 'Contact Form is an offered source', in_array( PCM_CRM_FORM_SOURCE, pcm_crm_lead_sources(), true ), true );
check( 'it is distinct from Web', PCM_CRM_FORM_SOURCE === 'Web', false );
check( 'Web is still offered', in_array( 'Web', pcm_crm_lead_sources(), true ), true );

echo "\n--- salesforce field map ---\n";
$map = $opps->salesforce_map();
check( 'stage maps to StageName', $map['stage_name'], 'StageName' );
check( 'account maps to AccountId', $map['account_id'], 'AccountId' );
check( 'contacts map LastName', pcm_crm_contacts()->salesforce_map()['last_name'], 'LastName' );
check( 'name_key is not exported', isset( pcm_crm_accounts()->salesforce_map()['name_key'] ), false );

echo "\n--- query building ---\n";
$where = new ReflectionMethod( 'PCM_CRM_Model', 'where' );
$w = function( $args ) use ( $where, $opps ) { return $where->invoke( $opps, $args ); };

check( 'excludes deleted by default', strpos( $w( array() ), 'is_deleted = 0' ) !== false, true );
check( 'include_deleted drops the clause', strpos( $w( array( 'include_deleted' => true ) ), 'is_deleted' ), false );
check( 'unknown filter column is ignored',
	strpos( $w( array( 'filters' => array( 'evil; DROP TABLE x' => 1 ) ) ), 'evil' ), false );
check( 'a quote in a search term is escaped',
	strpos( $w( array( 'search' => "o'brien" ) ), "o\\'brien" ) !== false, true );
check( 'range emits both bounds',
	substr_count( $w( array( 'filters' => array( 'close_date' => array( 'min' => '2026-01-01', 'max' => '2026-12-31' ) ) ) ), 'close_date' ), 2 );
check( 'open-ended range emits one bound',
	substr_count( $w( array( 'filters' => array( 'close_date' => array( 'min' => '2026-01-01' ) ) ) ), 'close_date' ), 1 );
check( 'an array filter becomes IN',
	strpos( $w( array( 'filters' => array( 'stage_name' => array( 'Proposal', 'Discovery' ) ) ) ), 'IN (' ) !== false, true );
check( 'an empty IN matches nothing rather than everything',
	strpos( $w( array( 'filters' => array( 'stage_name' => array() ) ) ), '1 = 0' ) !== false, true );
check( 'an empty scalar filter is skipped',
	strpos( $w( array( 'filters' => array( 'stage_name' => '' ) ) ), 'stage_name' ), false );
check( 'submissions table has no is_deleted clause',
	strpos( $where->invoke( pcm_crm_submissions(), array() ), 'is_deleted' ), false );

echo "\n--- system information ---\n";
foreach ( array( 'accounts', 'contacts', 'opportunities', 'activities' ) as $slug ) {
	$model = PCM_CRM_REST::model( $slug );
	check( $slug . ' record who last changed it', $model->has_field( 'last_modified_by_id' ), true );
	check( $slug . ' maps it for Salesforce', $model->salesforce_map()['last_modified_by_id'], 'LastModifiedById' );
	check( $slug . ' will not let it be written from a request',
		isset( $model->sanitize( array( 'last_modified_by_id' => 99 ) )['last_modified_by_id'] ), false );
}
check( 'the submissions log has no modified-by column',
	pcm_crm_submissions()->has_field( 'last_modified_by_id' ), false );

echo "\n--- owner names ---\n";
$user = function( $first, $last, $display, $login ) {
	return (object) array( 'first_name' => $first, 'last_name' => $last, 'display_name' => $display, 'user_login' => $login );
};

check( 'prefers first and last name',
	pcm_crm_user_label( $user( 'Jason', 'Jensen', 'jason@prettycodemachine.com', 'jason' ) ), 'Jason Jensen' );
check( 'an email display name is never used',
	pcm_crm_user_label( $user( '', '', 'jason@prettycodemachine.com', 'jasonj' ) ), 'jasonj' );
check( 'a real display name is used when there is no full name',
	pcm_crm_user_label( $user( '', '', 'Jason J', 'jasonj' ) ), 'Jason J' );
check( 'a first name alone is enough',
	pcm_crm_user_label( $user( 'Jason', '', 'jason@x.com', 'jasonj' ) ), 'Jason' );
check( 'no user resolves to nothing rather than erroring',
	pcm_crm_user_label( null ), '' );

$GLOBALS['pcm_test_users'] = array( 4 => $user( 'Ada', 'Lovelace', 'ada@example.org', 'ada' ) );
check( 'an owner id resolves to a name', pcm_crm_user_name( 4 ), 'Ada Lovelace' );
check( 'an unowned record has no owner name', pcm_crm_user_name( 0 ), '' );

echo "\n--- related lists endpoint ---\n";
// A wpdb that returns plausible rows, so expand() has data to work on.
class PCM_Related_WPDB extends FakeWPDB {
	function get_results( $q = '', $o = null ) {
		if ( false !== strpos( $q, 'opportunities' ) ) {
			return array( array( 'id' => 7, 'account_id' => 2, 'primary_contact_id' => 5, 'name' => 'A deal',
				'stage_name' => 'Proposal', 'amount' => '1000', 'close_date' => '2026-06-01',
				'is_closed' => '0', 'is_won' => '0', 'owner_id' => '1', 'is_deleted' => '0' ) );
		}
		if ( false !== strpos( $q, 'activities' ) ) {
			return array( array( 'id' => 9, 'subject' => 'Intro call', 'activity_type' => 'Call',
				'who_id' => 5, 'what_id' => 2, 'what_type' => 'account', 'owner_id' => '1', 'is_deleted' => '0' ) );
		}
		if ( false !== strpos( $q, 'opportunity_history' ) ) {
			return array( array( 'id' => 3, 'opportunity_id' => 7, 'stage_name' => 'Proposal',
				'previous_stage' => 'Discovery', 'entered_date' => '2026-03-01 09:00:00', 'exited_date' => null,
				'days_in_stage' => null, 'is_closed' => '0', 'is_won' => '0', 'created_by_id' => '1',
				'created_date' => '2026-03-01 09:00:00' ) );
		}
		if ( false !== strpos( $q, 'contacts' ) ) {
			return array( array( 'id' => 5, 'account_id' => 2, 'first_name' => 'Ada', 'last_name' => 'Lovelace',
				'email' => 'a@b.c', 'owner_id' => '1', 'is_deleted' => '0' ) );
		}
		return array( array( 'id' => 2, 'name' => 'Acme', 'owner_id' => '1', 'is_deleted' => '0' ) );
	}
}
$real_wpdb = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = new PCM_Related_WPDB();

$related = function( $object ) {
	return (array) PCM_CRM_REST::related( new WP_REST_Request( array( 'object' => $object, 'id' => 5 ) ) );
};

check( 'an account exposes all three lists',
	array_keys( $related( 'accounts' ) ), array( 'contacts', 'opportunities', 'activities' ) );
check( 'a contact exposes opportunities and activities',
	array_keys( $related( 'contacts' ) ), array( 'opportunities', 'activities' ) );
check( 'an opportunity exposes activities and its stage history',
	array_keys( $related( 'opportunities' ) ), array( 'activities', 'history' ) );

$history = $related( 'opportunities' )['history'];
check( 'the open history row is marked as current', $history[0]['_is_current'], 1 );
check( 'and its age is measured from when it was entered', $history[0]['_days'] > 0, true );

$rows = $related( 'contacts' );
check( 'related opportunities carry their account name',
	isset( $rows['opportunities'][0]['_account_name'] ), true );
check( 'related activities carry their contact name',
	isset( $rows['activities'][0]['_contact_name'] ), true );

$GLOBALS['wpdb'] = $real_wpdb;

echo "\n--- time in stage ---\n";
check( 'whole days between two moments',
	pcm_crm_days_between( '2026-03-01 09:00:00', '2026-03-11 09:00:00' ), 10 );
check( 'part of a day does not count as one',
	pcm_crm_days_between( '2026-03-01 09:00:00', '2026-03-01 23:59:00' ), 0 );
// The seeder backdates records, so an exit can precede an entry. A negative
// age would poison every average that reads it.
check( 'a backwards interval is clamped rather than negative',
	pcm_crm_days_between( '2026-03-11 09:00:00', '2026-03-01 09:00:00' ), 0 );
// MySQL's zero date parses to the year zero rather than failing, so without
// a guard an unrecorded stage reads as two thousand years old.
check( 'the zero date means unrecorded, not the year zero',
	pcm_crm_days_between( '0000-00-00 00:00:00', '2026-03-01 09:00:00' ), 0 );
check( 'and in the other position too',
	pcm_crm_days_between( '2026-03-01 09:00:00', '0000-00-00 00:00:00' ), 0 );
check( 'an empty date is unrecorded as well', pcm_crm_days_between( '', '2026-03-01 09:00:00' ), 0 );

check( 'the stall threshold defaults to 30 days', pcm_crm_stall_days(), 30 );
update_option( 'pcm_crm_stall_days', 14 );
check( 'and is configurable', pcm_crm_stall_days(), 14 );
update_option( 'pcm_crm_stall_days', 0 );
check( 'zero would flag everything, so it falls back', pcm_crm_stall_days(), 30 );
delete_option( 'pcm_crm_stall_days' );

check( 'stalled means open and sitting still',
	pcm_crm_stalled_args( array() )['filters']['is_closed'], 0 );
check( 'measured from when the stage was entered',
	isset( pcm_crm_stalled_args( array() )['filters']['stage_entered_date']['max'] ), true );

echo "\n--- stage conversion ---\n";
class PCM_History_WPDB extends FakeWPDB {
	public $entered = array( 'Qualification' => 40, 'Discovery' => 30, 'Proposal' => 12, 'Negotiation' => 9, 'Closed Won' => 6 );
	function get_results( $q = '', $o = null ) {
		if ( false !== strpos( $q, 'COUNT(DISTINCT opportunity_id)' ) ) {
			$rows = array();
			foreach ( $this->entered as $stage => $deals ) {
				$rows[] = array( 'stage_name' => $stage, 'deals' => $deals );
			}
			return $rows;
		}
		return array();
	}
}
$real_wpdb = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = new PCM_History_WPDB();

$conv = pcm_crm_stage_conversion();
check( 'a row per open stage', count( $conv ), 4 );
check( 'the first step measures Qualification to Discovery',
	array( $conv[0]['stage'], $conv[0]['next'] ), array( 'Qualification', 'Discovery' ) );
check( '30 of 40 reaching Discovery is 75%', $conv[0]['rate'], 75 );
check( '12 of 30 reaching Proposal is 40%', $conv[1]['rate'], 40 );
check( 'the last open stage converts to won', $conv[3]['next'], 'Closed Won' );
check( 'and 6 of 9 is 67%', $conv[3]['rate'], 67 );

// A deal can re-enter a stage, so a later stage can hold more deals than an
// earlier one. Reporting 150% would read as a bug rather than as churn.
$GLOBALS['wpdb']->entered = array( 'Qualification' => 4, 'Discovery' => 6 );
check( 'a rate cannot exceed 100%', pcm_crm_stage_conversion()[0]['rate'], 100 );

$GLOBALS['wpdb']->entered = array();
check( 'no history yet reports zero rather than dividing by zero',
	pcm_crm_stage_conversion()[0]['rate'], 0 );

$GLOBALS['wpdb'] = $real_wpdb;
check( 'the won stage is found by its flag', pcm_crm_won_stage_name(), 'Closed Won' );

echo "\n--- filter operators ---\n";
$opps_where = new ReflectionMethod( 'PCM_CRM_Model', 'where' );
$w = function( $args, $model = null ) use ( $opps_where ) {
	return $opps_where->invoke( $model ? $model : pcm_crm_opportunities(), $args );
};
$f = function( $filters ) use ( $w ) { return $w( array( 'filters' => $filters ) ); };

check( 'contains becomes a LIKE with wildcards',
	strpos( $f( array( 'name' => array( 'op' => 'contains', 'value' => 'Rescue' ) ) ), "LIKE '%Rescue%'" ) !== false, true );
check( 'starts anchors at the front',
	strpos( $f( array( 'name' => array( 'op' => 'starts', 'value' => 'Green' ) ) ), "LIKE 'Green%'" ) !== false, true );
// The pattern is built as '%100\%%', so the user's own % is escaped and
// cannot act as a wildcard — which is exactly that the bare '100%' is absent.
check( 'a wildcard typed by a user cannot act as one',
	strpos( $f( array( 'name' => array( 'op' => 'contains', 'value' => '100%' ) ) ), '100%' ), false );
check( 'not-contains negates the LIKE',
	strpos( $f( array( 'name' => array( 'op' => 'notcontains', 'value' => 'x' ) ) ), 'NOT LIKE' ) !== false, true );
check( 'greater-than compares',
	strpos( $f( array( 'amount' => array( 'op' => 'gt', 'value' => '5000' ) ) ), 'amount > ' ) !== false, true );
check( 'between emits both bounds',
	substr_count( $f( array( 'amount' => array( 'op' => 'between', 'min' => '1', 'max' => '9' ) ) ), 'amount' ), 2 );
check( 'empty covers NULL and the zero value',
	strpos( $f( array( 'amount' => array( 'op' => 'empty' ) ) ), 'IS NULL OR amount = 0' ) !== false, true );
check( 'empty on text compares to the empty string',
	strpos( $f( array( 'name' => array( 'op' => 'empty' ) ) ), "name = ''" ) !== false, true );
check( 'not-empty negates it',
	strpos( $f( array( 'name' => array( 'op' => 'notempty' ) ) ), 'NOT (' ) !== false, true );
check( 'an unknown operator is dropped rather than guessed at',
	strpos( $f( array( 'name' => array( 'op' => 'sql_injection', 'value' => 'x' ) ) ), 'name' ), false );
check( 'an operator with no value is not yet a filter',
	strpos( $f( array( 'name' => array( 'op' => 'eq', 'value' => '' ) ) ), 'name' ), false );

echo "\n--- filtering through a parent ---\n";
check( 'an account field becomes a subquery',
	strpos( $f( array( 'account.industry' => 'Nonprofit' ) ), 'account_id IN (SELECT id FROM' ) !== false, true );
check( 'the parent contributes its own conditions',
	strpos( $f( array( 'account.industry' => 'Nonprofit' ) ), "industry = 'Nonprofit'" ) !== false, true );
check( 'the parent still excludes its deleted rows',
	substr_count( $f( array( 'account.industry' => 'Nonprofit' ) ), 'is_deleted = 0' ), 2 );
check( 'several parent conditions share one subquery',
	substr_count( $f( array( 'account.industry' => 'Nonprofit', 'account.type' => 'Customer' ) ), 'SELECT id FROM' ), 1 );
check( 'an unknown parent prefix is ignored',
	strpos( $f( array( 'nonsense.field' => 'x' ) ), 'SELECT' ), false );
check( 'an unknown field on a known parent does not become "every account"',
	strpos( $f( array( 'account.nonsense' => 'x' ) ), 'SELECT' ), false );
check( 'nor does a known parent field left blank',
	strpos( $f( array( 'account.industry' => '' ) ), 'SELECT' ), false );
check( 'opportunities can also filter through their contact',
	strpos( $f( array( 'contact.last_name' => 'Okafor' ) ), 'primary_contact_id IN (SELECT' ) !== false, true );
check( 'accounts have no parent to filter through', count( pcm_crm_accounts()->related() ), 0 );

echo "\n--- filter schema ---\n";
$schema = (array) PCM_CRM_REST::schema( new WP_REST_Request() );
check( 'every object is described', array_keys( $schema ),
	array( 'accounts', 'contacts', 'opportunities', 'activities' ) );
check( 'the submissions log is not offered', isset( $schema['submissions'] ), false );

$opp_fields = wp_list_pluck( $schema['opportunities']['fields'], 'key' );
check( 'opportunity fields include the picklists', in_array( 'stage_name', $opp_fields, true ), true );
check( 'and the audit stamps', in_array( 'created_date', $opp_fields, true ), true );
check( 'internal columns are withheld', in_array( 'sf_id', $opp_fields, true ), false );
check( 'so is the delete flag', in_array( 'is_deleted', $opp_fields, true ), false );
check( 'accounts do not leak their matching key',
	in_array( 'name_key', wp_list_pluck( $schema['accounts']['fields'], 'key' ), true ), false );

$related = $schema['opportunities']['related'];
check( 'an opportunity offers two parents to filter through', count( $related ), 2 );
check( 'the parent is named for the UI', $related[0]['label'], 'Account' );
check( 'its fields are prefixed', strpos( $related[0]['fields'][0]['key'], 'account.' ), 0 );

$stage = null;
foreach ( $schema['opportunities']['fields'] as $field ) { if ( 'stage_name' === $field['key'] ) { $stage = $field; } }
check( 'a picklist field carries its options', count( $stage['options'] ), 6 );
check( 'options are value/label pairs', $stage['options'][0]['value'], 'Qualification' );

echo "\n--- demo data guard ---\n";
$allowed = function( $host ) {
	$GLOBALS['pcm_test_host'] = $host;
	return pcm_crm_seed_allowed();
};

check( 'staging is allowed', $allowed( 'staging2.prettycodemachine.com' ), true );
check( 'localhost is allowed', $allowed( 'localhost' ), true );
check( 'a .local host is allowed', $allowed( 'pcm.local' ), true );
check( 'PRODUCTION IS REFUSED', $allowed( 'prettycodemachine.com' ), false );
check( 'www production is refused', $allowed( 'www.prettycodemachine.com' ), false );
check( 'an unknown host is refused', $allowed( 'some-other-site.com' ), false );
check( 'the seed command is CLI-only', isset( WP_CLI::$commands['pcm-crm'] ), true );
$GLOBALS['pcm_test_host'] = 'example.com';

echo "\n--- schema ---\n";
check( 'six tables defined', count( ( new ReflectionMethod( 'PCM_CRM_Schema', 'definitions' ) )->invoke( null, '' ) ), 6 );
$defs = implode( "\n", ( new ReflectionMethod( 'PCM_CRM_Schema', 'definitions' ) )->invoke( null, '' ) );
check( 'history has an index for finding the open row',
	strpos( $defs, 'pcm_hist_open (opportunity_id,exited_date)' ) !== false, true );
check( 'opportunities can be sorted by when they entered their stage',
	strpos( $defs, 'pcm_opp_entered (stage_entered_date)' ) !== false, true );

echo "\n" . ( $fail ? "$fail FAILED\n" : "All checks passed\n" );
exit( $fail ? 1 : 0 );
