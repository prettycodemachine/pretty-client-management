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

echo "\n--- schema ---\n";
check( 'five tables defined', count( ( new ReflectionMethod( 'PCM_CRM_Schema', 'definitions' ) )->invoke( null, '' ) ), 5 );

echo "\n" . ( $fail ? "$fail FAILED\n" : "All checks passed\n" );
exit( $fail ? 1 : 0 );
