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

echo "\n--- the rendered form ---\n";
$form = pcm_crm_contact_form_shortcode();

// The theme styles this markup, and cached pages post to these input names, so
// the shipped form has to keep producing both unchanged.
check( 'keeps the theme’s form class', false !== strpos( $form, 'class="contact-form"' ), true );
check( 'carries the nonce', false !== strpos( $form, 'pcm_contact_nonce' ), true );
check( 'carries the honeypot', false !== strpos( $form, 'name="pcm_hp"' ), true );
check( 'keeps the original input names',
	false !== strpos( $form, 'name="pcm_first_name"' ) && false !== strpos( $form, 'name="pcm_org"' ), true );
check( 'renders a textarea for a paragraph field', false !== strpos( $form, '<textarea id="pcm_message"' ), true );
check( 'renders a select for a dropdown', false !== strpos( $form, '<select id="pcm_interest"' ), true );
check( 'pairs the two half-width fields into a row', substr_count( $form, 'class="field-row"' ), 1 );
// An unclosed div would swallow the rest of the page layout.
check( 'every div is closed', substr_count( $form, '<div' ), substr_count( $form, '</div>' ) );
check( 'required survives to the markup', substr_count( $form, ' required' ) >= 6, true );

echo "\n--- email tokens ---\n";
// Values are keyed by the builder's field keys now, not by fixed names.
$fields = array( 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'org' => 'Analytical & Co', 'email' => 'a@b.c', 'interest' => 'AI Enablement' );
check( 'fills a field token', pcm_crm_fill_tokens( 'Hi {{FIRST NAME}}', $fields ), 'Hi Ada' );
check( 'composes full name from two fields', pcm_crm_fill_tokens( 'Hi {{FULL NAME}}', $fields ), 'Hi Ada Lovelace' );
check( 'underscored spelling works', pcm_crm_fill_tokens( '{{FIRST_NAME}}', $fields ), 'Ada' );
// A template saved before the form was configurable must keep working.
check( 'the legacy organization token still resolves',
	pcm_crm_fill_tokens( '{{ORGANIZATION}}', $fields ), 'Analytical &amp; Co' );
check( 'so does the legacy interest token',
	pcm_crm_fill_tokens( '{{INTEREST}}', $fields ), 'AI Enablement' );
check( 'escapes injected markup',
	pcm_crm_fill_tokens( '{{ORGANIZATION}}', array( 'org' => '<script>x</script>' ) ),
	'&lt;script&gt;x&lt;/script&gt;' );
check( 'a token with no value left behind resolves to nothing',
	pcm_crm_fill_tokens( '[{{EMAIL}}]', array() ), '[]' );

echo "\n--- form builder ---\n";
check( 'the shipped form keeps its original input names',
	pcm_crm_field_input_name( pcm_crm_default_form_fields()[0] ), 'pcm_first_name' );
check( 'tokens are derived from field keys',
	pcm_crm_field_token( array( 'key' => 'first_name' ) ), '{{FIRST NAME}}' );
check( 'every field offers a token',
	count( pcm_crm_tokens() ) >= count( pcm_crm_default_form_fields() ), true );

$saved = pcm_crm_sanitize_form_fields( array(
	array( 'label' => 'Your name', 'type' => 'text', 'required' => 1, 'map' => 'contact.first_name' ),
	array( 'label' => '', 'type' => 'text' ),
	array( 'label' => 'Budget', 'type' => 'nonsense', 'map' => 'contact.forecast_category' ),
) );
check( 'a key is derived from the label when none is given', $saved[0]['key'], 'your_name' );
check( 'a field with no label is dropped', count( $saved ), 2 );
check( 'an unknown type falls back to text', $saved[1]['type'], 'text' );
check( 'a map to a column not on the list is refused', $saved[1]['map'], '' );

$dupes = pcm_crm_sanitize_form_fields( array(
	array( 'label' => 'Name', 'key' => 'name' ),
	array( 'label' => 'Name again', 'key' => 'name' ),
) );
// Two fields sharing a key would post into one input name and the second
// would silently win.
check( 'duplicate keys are made unique', $dupes[0]['key'] === $dupes[1]['key'], false );

check( 'saving an empty form falls back to the shipped one',
	count( pcm_crm_sanitize_form_fields( array() ) ), count( pcm_crm_default_form_fields() ) );

$interest_field = array( 'key' => 'interest', 'type' => 'select', 'source' => 'interests' );
check( 'a sourced dropdown draws from the offerings',
	pcm_crm_field_options( $interest_field ), array_values( pcm_crm_interest_options() ) );
check( 'a hand-written dropdown splits on newlines',
	pcm_crm_field_options( array( 'type' => 'select', 'options' => "One\nTwo\n" ) ), array( 'One', 'Two' ) );

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
// A column holding one of our ids must never wear a Salesforce standard name:
// Data Loader would map it automatically and reject every row.
check( 'account id travels as a PCM custom field', $map['account_id'], 'PCM_Account_Id__c' );
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
	check( $slug . ' keeps the modifier as a PCM field, not a Salesforce user',
		$model->salesforce_map()['last_modified_by_id'], 'PCM_Last_Modified_By__c' );
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
	return (array) PCM_CRM_REST::related( new WP_REST_Request( array( 'pcm_object' => $object, 'pcm_id' => 5 ) ) );
};

check( 'an account exposes all three lists',
	array_keys( $related( 'accounts' ) ), array( 'contacts', 'opportunities', 'activities' ) );
check( 'a contact exposes opportunities, activities and its enrollments',
	array_keys( $related( 'contacts' ) ), array( 'opportunities', 'activities', 'enrollments' ) );
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

// The record page renders every lookup as a named link, so each lookup column
// gets one predictable key whatever alias the object grew up with.
check( 'an opportunity names its account under the column’s own key',
	$rows['opportunities'][0]['_account_id_name'], 'Acme' );
check( 'and its primary contact',
	$rows['opportunities'][0]['_primary_contact_id_name'], 'Ada Lovelace' );
check( 'an activity names its contact',
	$rows['activities'][0]['_who_id_name'], 'Ada Lovelace' );
check( 'and resolves its polymorphic parent through what_type',
	$rows['activities'][0]['_what_id_name'], 'Acme' );
check( 'a plural slug comes from the models, not an appended s',
	array( PCM_CRM_REST::slug_for( 'opportunity' ), PCM_CRM_REST::slug_for( 'activity' ) ), array( 'opportunities', 'activities' ) );

$pcm_schema = array();
foreach ( (array) PCM_CRM_REST::schema( new WP_REST_Request() )['opportunities']['fields'] as $pcm_f ) { $pcm_schema[ $pcm_f['key'] ] = $pcm_f; }
check( '/schema says which object a lookup points at', $pcm_schema['account_id']['lookup'], 'accounts' );
check( 'and what narrows it', $pcm_schema['primary_contact_id']['lookup_filter'], array( 'account_id' => 'account_id' ) );

$GLOBALS['wpdb'] = $real_wpdb;

check( 'bootstrap describes every object for the browser',
	PCM_CRM_REST::bootstrap( new WP_REST_Request() )['objects']['contacts'],
	array( 'label' => 'Contact', 'plural' => 'Contacts', 'icon' => 'id', 'color' => 5, 'page' => 'pcm-crm-contacts' ) );

echo "\n--- route parameters are not shadowed by the body ---\n";
// WordPress merges the JSON body ahead of URL parameters. The schedules table
// has a column called `object`, so creating one sent an `object` field that
// shadowed the route's own capture and the collection route stopped knowing
// which table it was writing to.
$shadowing = new WP_REST_Request(
	array( 'pcm_object' => 'schedules' ),
	array( 'object' => '', 'name' => 'CRM Dashboard', 'report_type' => 'dashboard' )
);

check( 'the body does shadow a plain parameter read', $shadowing['object'], '' );
// Asserted as "not the object being lost" rather than "no error at all": the
// stub has no rows, so the insert cannot be read back and create_item reports
// that. Losing the capture would report pcm_crm_unknown_object instead, which
// is the failure this is watching for.
$shadow_create = PCM_CRM_REST::create_item( $shadowing );
check( 'but the route still resolves its own object',
	is_wp_error( $shadow_create ) ? $shadow_create->get_error_code() : 'ok',
	'pcm_crm_not_found' );

$shadow_id = new WP_REST_Request(
	array( 'pcm_object' => 'schedules', 'pcm_id' => 7 ),
	array( 'id' => 999, 'name' => 'Renamed' )
);
// The stub has no rows, so this reports "not found" — the point is that it
// got as far as looking for one, rather than losing track of the object.
$shadow_result = PCM_CRM_REST::update_item( $shadow_id );
check( 'and a write still knows which table it is for',
	is_wp_error( $shadow_result ) ? $shadow_result->get_error_code() : 'ok', 'pcm_crm_not_found' );

echo "\n--- email variables ---\n";
$groups = pcm_crm_email_variables();
$prefixes = wp_list_pluck( $groups, 'prefix' );
check( 'variables are grouped by record', array_slice( $prefixes, 0, 2 ), array( 'contact', 'account' ) );
// A contact has many opportunities and neither send path picks one, so an
// opportunity variable could only ever resolve to nothing.
check( 'opportunity variables are not offered', in_array( 'opportunity', $prefixes, true ), false );

$contact_tokens = wp_list_pluck( $groups[0]['fields'], 'token' );
check( 'a contact field is offered', in_array( '{{contact.first_name}}', $contact_tokens, true ), true );
// Ids and flags make poor sentences.
check( 'ids are not offered', in_array( '{{contact.account_id}}', $contact_tokens, true ), false );
check( 'nor are checkboxes', in_array( '{{contact.do_not_contact}}', $contact_tokens, true ), false );

$context = array(
	'contact' => array( 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'title' => 'Director' ),
	'account' => array( 'name' => 'Analytical & Co' ),
	'sender'  => 'Jason Jensen',
);

check( 'a contact variable fills', pcm_crm_fill_variables( 'Hi {{contact.first_name}}', $context ), 'Hi Ada' );
check( 'an account variable fills',
	pcm_crm_fill_variables( '{{account.name}}', $context ), 'Analytical &amp; Co' );
check( 'a composed full name fills',
	pcm_crm_fill_variables( '{{contact.full_name}}', $context ), 'Ada Lovelace' );
check( 'the sender fills', pcm_crm_fill_variables( '{{sender.name}}', $context ), 'Jason Jensen' );
// "Hi {{contact.first_name}}," reaching an inbox is worse than "Hi ,".
check( 'a variable with nothing behind it comes out empty, not as itself',
	pcm_crm_fill_variables( 'Hi [{{contact.nonsense}}]', $context ), 'Hi []' );
// A template written while opportunity variables were offered must not start
// shipping the raw token.
check( 'a withdrawn opportunity token blanks rather than going out literally',
	pcm_crm_fill_variables( 'Re: [{{opportunity.name}}]', $context ), 'Re: []' );
check( 'a submitted value cannot inject markup',
	pcm_crm_fill_variables( '{{contact.first_name}}', array( 'contact' => array( 'first_name' => '<b>x</b>' ) ) ),
	'&lt;b&gt;x&lt;/b&gt;' );

echo "\n--- outreach records need their essentials ---\n";
// A record made of nothing is a worse symptom than an error: it looks like it
// worked, and the list then shows a row of dashes.
check( 'a template with no name is refused',
	is_wp_error( pcm_crm_validate_outreach( null, 'template', array( 'subject' => 'Hello' ), 0 ) ), true );
check( 'a template with no subject is refused',
	is_wp_error( pcm_crm_validate_outreach( null, 'template', array( 'name' => 'Intro' ), 0 ) ), true );
check( 'a complete template is allowed',
	is_wp_error( pcm_crm_validate_outreach( null, 'template', array( 'name' => 'Intro', 'subject' => 'Hello' ), 0 ) ), false );
check( 'a sequence needs only a name',
	is_wp_error( pcm_crm_validate_outreach( null, 'sequence', array( 'name' => 'Follow up' ), 0 ) ), false );
check( 'and is refused without one',
	is_wp_error( pcm_crm_validate_outreach( null, 'sequence', array( 'description' => 'x' ), 0 ) ), true );
check( 'other objects are not subject to the rule',
	is_wp_error( pcm_crm_validate_outreach( null, 'account', array(), 0 ) ), false );

echo "\n--- sequence steps ---\n";
check( 'steps decode from JSON',
	count( pcm_crm_sequence_steps( array( 'steps' => '[{"template_id":3,"delay_days":2}]' ) ) ), 1 );
check( 'a step with no template is dropped',
	count( pcm_crm_sequence_steps( array( 'steps' => '[{"delay_days":2}]' ) ) ), 0 );
check( 'a negative delay is clamped',
	pcm_crm_sequence_steps( array( 'steps' => '[{"template_id":1,"delay_days":-9}]' ) )[0]['delay_days'], 0 );
check( 'malformed JSON is no steps rather than a fatal',
	pcm_crm_sequence_steps( array( 'steps' => 'not json' ) ), array() );
check( 'a missing steps column is no steps',
	pcm_crm_sequence_steps( array( 'steps' => '' ) ), array() );

echo "\n--- outreach guards ---\n";
class PCM_Outreach_WPDB extends FakeWPDB {
	public $contact = array();
	function get_row( $q = '', $o = null ) {
		return $this->contact ? $this->contact : null;
	}
}
$real_wpdb = $GLOBALS['wpdb'];
$db = new PCM_Outreach_WPDB();
$GLOBALS['wpdb'] = $db;

// The one field in this CRM with a consequence outside it. A sequence quietly
// mailing someone who asked not to be contacted is the failure it exists to
// prevent.
$db->contact = array( 'id' => 5, 'email' => 'a@b.com', 'account_id' => 0, 'do_not_contact' => '1',
	'do_not_contact_reason' => 'Asked to be removed', 'first_name' => 'Ada', 'last_name' => 'L', 'is_deleted' => '0' );
$blocked = pcm_crm_send_contact_email( 5, 'Subject', 'Body' );
check( 'a do-not-contact record refuses a send', is_wp_error( $blocked ), true );
check( 'and says why', false !== strpos( $blocked->get_error_message() , 'Asked to be removed' ), true );

$blocked_enroll = pcm_crm_enroll_contact( 5, 1 );
check( 'and refuses enrollment too', is_wp_error( $blocked_enroll ), true );

$db->contact = array( 'id' => 6, 'email' => '', 'account_id' => 0, 'do_not_contact' => '0',
	'first_name' => 'Bo', 'last_name' => 'C', 'is_deleted' => '0' );
check( 'a contact with no address cannot be mailed',
	is_wp_error( pcm_crm_send_contact_email( 6, 'S', 'B' ) ), true );

$GLOBALS['wpdb'] = $real_wpdb;

// The activity a send writes must not look like a reply and stop its own
// sequence.
check( 'the sending guard is off by default', pcm_crm_sending_sequence(), false );
pcm_crm_sending_sequence( true );
check( 'and can be raised while a sequence sends', pcm_crm_sending_sequence(), true );
pcm_crm_sending_sequence( false );

echo "\n--- schedule due dates ---\n";
$at = function( $str ) { return strtotime( $str ); };
$sched = function( $overrides = array() ) {
	return array_merge( array(
		'is_active' => 1, 'frequency' => 'daily', 'send_time' => '08:00',
		'day_of_week' => 1, 'day_of_month' => 1, 'last_sent' => null,
	), $overrides );
};

// Monday 2026-09-14.
check( 'a daily schedule is not due before its time',
	pcm_crm_schedule_is_due( $sched(), $at( '2026-09-14 07:59' ) ), false );
check( 'and is due once the time passes',
	pcm_crm_schedule_is_due( $sched(), $at( '2026-09-14 08:00' ) ), true );
check( 'a paused schedule never sends',
	pcm_crm_schedule_is_due( $sched( array( 'is_active' => 0 ) ), $at( '2026-09-14 09:00' ) ), false );
check( 'already sent today, so not again',
	pcm_crm_schedule_is_due( $sched( array( 'last_sent' => '2026-09-14 08:01:00' ) ), $at( '2026-09-14 09:00' ) ), false );
// A cron that arrives hours late must still deliver rather than skip the day.
check( 'a late run still delivers',
	pcm_crm_schedule_is_due( $sched( array( 'last_sent' => '2026-09-13 08:01:00' ) ), $at( '2026-09-14 15:00' ) ), true );

check( 'a weekly schedule waits for its weekday',
	pcm_crm_schedule_is_due( $sched( array( 'frequency' => 'weekly', 'day_of_week' => 3 ) ), $at( '2026-09-14 09:00' ) ), false );
check( 'and sends on it',
	pcm_crm_schedule_is_due( $sched( array( 'frequency' => 'weekly', 'day_of_week' => 3 ) ), $at( '2026-09-16 09:00' ) ), true );

check( 'a monthly schedule waits for its date',
	pcm_crm_schedule_is_due( $sched( array( 'frequency' => 'monthly', 'day_of_month' => 15 ) ), $at( '2026-09-14 09:00' ) ), false );
check( 'and sends on it',
	pcm_crm_schedule_is_due( $sched( array( 'frequency' => 'monthly', 'day_of_month' => 15 ) ), $at( '2026-09-15 09:00' ) ), true );
// A schedule set for the 31st would otherwise never fire in February.
check( 'the 31st lands on the last day of a short month',
	pcm_crm_schedule_is_due( $sched( array( 'frequency' => 'monthly', 'day_of_month' => 31 ) ), $at( '2026-02-28 09:00' ) ), true );
check( 'but not earlier in that month',
	pcm_crm_schedule_is_due( $sched( array( 'frequency' => 'monthly', 'day_of_month' => 31 ) ), $at( '2026-02-27 09:00' ) ), false );
check( 'a nonsense send time falls back rather than failing',
	pcm_crm_schedule_is_due( $sched( array( 'send_time' => 'lunchtime' ) ), $at( '2026-09-14 09:00' ) ), true );

echo "\n--- schedule recipients ---\n";
check( 'plain addresses come through',
	pcm_crm_schedule_recipients( 'a@b.com, c@d.com' ), array( 'a@b.com', 'c@d.com' ) );
check( 'separators can be commas, semicolons or spaces',
	pcm_crm_schedule_recipients( 'a@b.com; c@d.com  e@f.com' ), array( 'a@b.com', 'c@d.com', 'e@f.com' ) );
check( 'duplicates are collapsed',
	pcm_crm_schedule_recipients( 'a@b.com, a@b.com' ), array( 'a@b.com' ) );
check( 'an unresolvable name is dropped rather than mailed',
	pcm_crm_schedule_recipients( 'not-a-user' ), array() );

// The picker stores user ids rather than addresses, so a schedule follows
// someone who changes their email instead of going to the old one.
$GLOBALS['pcm_test_users'] = array( 4 => (object) array(
	'first_name' => 'Ada', 'last_name' => 'Lovelace', 'display_name' => 'Ada',
	'user_login' => 'ada', 'user_email' => 'ada@example.org',
) );
check( 'a user id resolves to that user’s current address',
	pcm_crm_schedule_recipients( '4' ), array( 'ada@example.org' ) );
check( 'ids and plain addresses mix freely',
	pcm_crm_schedule_recipients( '4, someone@else.com' ), array( 'ada@example.org', 'someone@else.com' ) );
check( 'a username works too', pcm_crm_schedule_recipients( 'ada' ), array( 'ada@example.org' ) );
check( 'an id with no user behind it is dropped', pcm_crm_schedule_recipients( '999' ), array() );
$GLOBALS['pcm_test_users'] = array();
check( 'nothing in, nothing out', pcm_crm_schedule_recipients( '' ), array() );

// A failure has to say which of the two problems it is.
$empty_error = '';
try { pcm_crm_send_schedule( array( 'recipients' => '', 'report_type' => 'dashboard', 'name' => 'x' ) ); }
catch ( Exception $e ) { $empty_error = $e->getMessage(); }
check( 'an empty field says so', $empty_error, 'No recipients are saved on this schedule.' );

$stale_error = '';
try { pcm_crm_send_schedule( array( 'recipients' => '999', 'report_type' => 'dashboard', 'name' => 'x' ) ); }
catch ( Exception $e ) { $stale_error = $e->getMessage(); }
check( 'and an unresolvable one names what is stored',
	false !== strpos( $stale_error, '999' ), true );

// Better to refuse the save than to discover it a week later, silently.
check( 'a schedule with no recipients cannot be saved',
	is_wp_error( pcm_crm_validate_schedule( null, 'schedule', array( 'recipients' => '' ), 0 ) ), true );
check( 'one with a real address can',
	is_wp_error( pcm_crm_validate_schedule( null, 'schedule', array( 'recipients' => 'a@b.com' ), 0 ) ), false );
check( 'other objects are not subject to the rule',
	is_wp_error( pcm_crm_validate_schedule( null, 'account', array( 'recipients' => '' ), 0 ) ), false );

echo "\n--- schedule storage ---\n";
$model = pcm_crm_schedules();
check( 'filters survive as JSON rather than being sanitised apart',
	$model->sanitize( array( 'filters' => '{"stage_name":"Proposal"}' ) )['filters'], '{"stage_name":"Proposal"}' );
check( 'last_sent cannot be set from a request',
	isset( $model->sanitize( array( 'last_sent' => '2026-01-01 00:00:00' ) )['last_sent'] ), false );
check( 'schedules are not offered to the filter builder',
	isset( ( (array) PCM_CRM_REST::schema( new WP_REST_Request() ) )['schedules'] ), false );

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
		if ( false !== strpos( $q, 'COUNT(DISTINCT h.opportunity_id)' ) ) {
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

echo "\n--- themes ---\n";
check( 'five themes ship', count( pcm_crm_themes() ), 5 );
check( 'including a neon one', isset( pcm_crm_themes()['neon'] ), true );
check( 'the house palette is the default', pcm_crm_theme(), 'pcm' );

update_option( 'pcm_crm_theme', 'neon' );
check( 'a chosen theme sticks', pcm_crm_theme(), 'neon' );
// An unknown value would put data-theme on the wrap with no rules behind it,
// which is an unstyled screen rather than a fallback.
update_option( 'pcm_crm_theme', 'nonsense' );
check( 'an unknown theme falls back rather than rendering unstyled', pcm_crm_theme(), 'pcm' );
check( 'and is refused on the way in', pcm_crm_sanitize_theme( 'nonsense' ), 'pcm' );
check( 'a real one is accepted', pcm_crm_sanitize_theme( 'dark' ), 'dark' );
delete_option( 'pcm_crm_theme' );

echo "\n--- recycle bin ---\n";
check( 'the four record objects have a bin',
	array_keys( pcm_crm_recyclable_objects() ),
	array( 'accounts', 'contacts', 'opportunities', 'activities' ) );
// Deleting a template is a configuration change, not something to fish back
// out of a bin.
check( 'machinery is not binned', isset( pcm_crm_recyclable_objects()['templates'] ), false );

$bin_where = pcm_crm_contacts()->where( array(
	'filters'         => array( 'is_deleted' => 1 ),
	'include_deleted' => true,
) );
check( 'the bin asks for deleted rows specifically',
	false !== strpos( $bin_where, 'is_deleted' ), true );
// Every other list must keep excluding them.
check( 'and a normal list still excludes them',
	false !== strpos( pcm_crm_contacts()->where( array() ), 'is_deleted = 0' ), true );

check( 'models can purge as well as delete', method_exists( 'PCM_CRM_Model', 'purge' ), true );
check( 'the whole bin can be emptied at once',
	method_exists( 'PCM_CRM_REST', 'empty_whole_bin' ), true );
// It has to cover every object with a bin, or "empty the whole bin" is a lie.
check( 'and it covers every object that has one',
	count( pcm_crm_recyclable_objects() ), 4 );
check( 'and empty a whole bin', method_exists( 'PCM_CRM_Model', 'purge_all' ), true );
check( 'purging a deal takes its stage history with it',
	has_filter( 'pcm_crm_before_purge', 'pcm_crm_purge_stage_history' ), true );

echo "\n--- orphaned stage history ---\n";
// A history row whose deal is gone is not evidence about anything, and
// conversion counts distinct deals straight out of that table — so an orphan
// inflates every rate it appears in.
class PCM_Orphan_WPDB extends FakeWPDB {
	public $last = '';
	function get_var( $q = '' ) { $this->last = $q; return 83; }
	function query( $q = '' ) { $this->last = $q; return 83; }
}
$real_wpdb = $GLOBALS['wpdb'];
$orphan_db = new PCM_Orphan_WPDB();
$GLOBALS['wpdb'] = $orphan_db;

check( 'orphans are counted', pcm_crm_orphaned_history_count(), 83 );
check( 'by looking for history with no opportunity behind it',
	false !== strpos( $orphan_db->last, 'o.id IS NULL' ), true );

pcm_crm_delete_orphaned_history();
check( 'and removed with a delete, not a select',
	0 === strpos( $orphan_db->last, 'DELETE' ), true );

$GLOBALS['wpdb'] = $real_wpdb;

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

echo "\n--- sample content ---\n";
$templates = pcm_crm_sample_templates();
check( 'six templates ship', count( $templates ), 6 );
check( 'the first reply is one of them', isset( $templates['inbound-first-reply'] ), true );
check( 'and it greets by name',
	false !== strpos( $templates['inbound-first-reply']['body'], '{{contact.first_name}}' ), true );
check( 'every template has a subject',
	count( array_filter( wp_list_pluck( $templates, 'subject' ) ) ), count( $templates ) );

$offered = array();
foreach ( pcm_crm_email_variables() as $group ) {
	$offered = array_merge( $offered, wp_list_pluck( $group['fields'], 'token' ) );
}

$unknown = array();
foreach ( $templates as $template ) {
	preg_match_all( '/\{\{[a-z_]+\.[a-z_]+\}\}/i', $template['subject'] . ' ' . $template['body'], $found );

	foreach ( $found[0] as $token ) {
		if ( ! in_array( $token, $offered, true ) ) { $unknown[] = $token; }
	}
}
check( 'no shipped template uses a variable that is not offered', array_unique( $unknown ), array() );

$sequences = pcm_crm_sample_sequences();
check( 'a sequence ships for a new inbound lead', isset( $sequences['new-inbound-lead'] ), true );
check( 'it is three steps', count( $sequences['new-inbound-lead']['steps'] ), 3 );
check( 'the first goes out immediately', $sequences['new-inbound-lead']['steps'][0]['delay_days'], 0 );

// Every step must name a template that actually ships, or the sequence stops
// itself on its first run.
$missing = array();
foreach ( $sequences as $sequence ) {
	foreach ( $sequence['steps'] as $step ) {
		if ( ! isset( $templates[ $step['template'] ] ) ) { $missing[] = $step['template']; }
	}
}
check( 'every step points at a template that exists', $missing, array() );

echo "\n--- the test data flag ---\n";
foreach ( array( 'accounts', 'contacts', 'opportunities', 'activities' ) as $slug ) {
	check( $slug . ' carry the flag', PCM_CRM_REST::model( $slug )->has_field( 'is_test' ), true );
}
check( 'it is filterable, which is the point of having it',
	in_array( 'is_test', wp_list_pluck( ( (array) PCM_CRM_REST::schema( new WP_REST_Request() ) )['contacts']['fields'], 'key' ), true ), true );
check( 'history is in scope for removal too',
	isset( pcm_crm_sample_tables()['history'] ), true );
check( 'and so are all four objects', count( pcm_crm_sample_tables() ), 5 );

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

echo "\n--- custom fields ---\n";
$defs = pcm_crm_sanitize_custom_fields( array(
	'contacts' => array(
		array( 'label' => 'LinkedIn URL', 'type' => 'url' ),
		array( 'label' => 'Renewal date', 'type' => 'date' ),
		array( 'label' => 'Tier', 'type' => 'picklist', 'options' => "Gold\nSilver" ),
		array( 'label' => 'Partner', 'type' => 'relationship', 'related' => 'accounts' ),
		array( 'label' => '', 'type' => 'text' ),
		array( 'label' => 'Bad type', 'type' => 'nonsense' ),
	),
	'nonsense_object' => array( array( 'label' => 'x' ) ),
) );

check( 'a key is derived from the label', $defs['contacts'][0]['key'], 'linkedin_url' );
check( 'unlabelled fields are dropped', count( $defs['contacts'] ), 5 );
check( 'an unknown type falls back to text', $defs['contacts'][4]['type'], 'text' );
check( 'a picklist keeps its values', $defs['contacts'][2]['options'], array( 'Gold', 'Silver' ) );
check( 'a relationship keeps its target', $defs['contacts'][3]['related'], 'accounts' );
check( 'an object that cannot carry fields is ignored', isset( $defs['nonsense_object'] ), false );

check( 'the column is prefixed so it cannot collide with a built-in',
	pcm_crm_custom_column( 'linkedin_url' ), 'cf_linkedin_url' );
check( 'and is recognisable as custom afterwards',
	pcm_crm_is_custom_column( 'cf_linkedin_url' ), true );
check( 'a built-in column is not', pcm_crm_is_custom_column( 'first_name' ), false );

check( 'the Salesforce name reads like the label',
	pcm_crm_custom_api_name( array( 'label' => 'LinkedIn URL' ) ), 'LinkedIn_URL__c' );
// A Salesforce API name cannot start with a digit.
check( 'a label starting with a number is still a valid API name',
	pcm_crm_custom_api_name( array( 'label' => '2026 Goal' ) ), 'PCM_2026_Goal__c' );
check( 'an explicit API name wins',
	pcm_crm_custom_api_name( array( 'label' => 'Anything', 'api_name' => 'Chosen__c' ) ), 'Chosen__c' );

update_option( 'pcm_crm_custom_fields', $defs );
$map = pcm_crm_custom_field_map( 'contacts' );
check( 'a url field maps to the url type', $map['cf_linkedin_url']['type'], 'url' );
check( 'a date field maps to the date type', $map['cf_renewal_date']['type'], 'date' );
check( 'a relationship stores an id', $map['cf_partner']['type'], 'id' );
check( 'a picklist carries its values into the field map', $map['cf_tier']['options'], array( 'Gold', 'Silver' ) );
check( 'every custom field is exportable', isset( $map['cf_tier']['sf'] ), true );

// The screen edits one object at a time; a save must not read as "delete
// everything on the others".
$defs['accounts'] = array( array( 'key' => 'region', 'label' => 'Region', 'type' => 'text' ) );
update_option( 'pcm_crm_custom_fields', $defs );
$partial = pcm_crm_sanitize_custom_fields( array( 'contacts' => array( array( 'label' => 'Only one', 'type' => 'text' ) ) ) );
check( 'saving one object leaves the others alone', isset( $partial['accounts'] ), true );
check( 'while replacing the one that was submitted', count( $partial['contacts'] ), 1 );

echo "\n--- page layouts ---\n";
delete_option( 'pcm_crm_custom_fields' );

$layout = pcm_crm_layout( 'contacts' );
check( 'the shipped layout has its sections', count( $layout ) >= 4, true );
check( 'the first section is the unheaded one', $layout[0]['title'], '' );
check( 'and holds the name fields', in_array( 'first_name', $layout[0]['fields'], true ), true );

// A field created after a layout was saved must not be invisible.
update_option( 'pcm_crm_custom_fields', array( 'contacts' => array(
	array( 'key' => 'tier', 'label' => 'Tier', 'type' => 'text' ),
) ) );
$with_custom = pcm_crm_layout( 'contacts' );
$last = end( $with_custom );
check( 'an unplaced custom field is appended rather than lost',
	in_array( 'cf_tier', $last['fields'], true ), true );

$saved = pcm_crm_sanitize_layouts( array( 'contacts' => array(
	array( 'title' => 'Basics', 'fields' => array( 'first_name', 'last_name', 'first_name', 'not_a_column' ) ),
) ) );
check( 'a field placed twice is only kept once',
	$saved['contacts'][0]['fields'], array( 'first_name', 'last_name' ) );
check( 'a name that is not a column is dropped',
	in_array( 'not_a_column', $saved['contacts'][0]['fields'], true ), false );

$emptied = pcm_crm_sanitize_layouts( array( 'contacts' => array() ) );
check( 'an empty layout falls back to the shipped one rather than leaving no form',
	count( $emptied['contacts'] ) >= 4, true );

check( 'available fields exclude the ones already placed',
	in_array( 'first_name', pcm_crm_layout_available_fields( 'contacts' ), true ), false );
check( 'and exclude the audit stamps, which have their own panel',
	in_array( 'created_date', pcm_crm_layout_available_fields( 'contacts' ), true ), false );

echo "\n--- custom fields on the contact form ---\n";
$targets = pcm_crm_form_field_targets();
check( 'a custom contact field can be mapped from the form',
	isset( $targets['contact.cf_tier'] ), true );
check( 'and is named for where it lands', $targets['contact.cf_tier'], 'Contact: Tier' );

update_option( 'pcm_crm_custom_fields', array( 'contacts' => array(
	array( 'key' => 'partner', 'label' => 'Partner', 'type' => 'relationship', 'related' => 'accounts' ),
) ) );
// A visitor filling in a form has no way to supply a record id.
check( 'a relationship field is not offered to the form',
	isset( pcm_crm_form_field_targets()['contact.cf_partner'] ), false );
delete_option( 'pcm_crm_custom_fields' );

echo "\n--- export headers ---\n";
// The rule: a Salesforce standard name only where the value loads as it
// stands. Anything holding one of our ids gets a PCM-prefixed custom name.
foreach ( array( 'accounts', 'contacts', 'opportunities', 'activities' ) as $slug ) {
	$offenders = array();

	foreach ( PCM_CRM_REST::model( $slug )->salesforce_map() as $field => $api ) {
		if ( preg_match( '/_id$/', $field ) && ! preg_match( '/__c$/', $api ) ) {
			$offenders[] = $field . ' -> ' . $api;
		}
	}

	check( $slug . ': no id column wears a standard name', $offenders, array() );
}

// sf_id is empty until a migration fills it, and a blank column called Id is
// the one thing Data Loader should never be handed.
check( 'the empty Salesforce id column is not exported at all',
	isset( pcm_crm_accounts()->salesforce_map()['sf_id'] ), false );
check( 'value fields keep their standard names',
	pcm_crm_opportunities()->salesforce_map()['stage_name'], 'StageName' );
check( 'and so do audit dates, which are values',
	pcm_crm_accounts()->salesforce_map()['created_date'], 'CreatedDate' );

echo "\n--- NPSP data import ---\n";
$npsp = pcm_crm_npsp_headers( false );
check( 'the file leads with our own ids for tracing back',
	array_slice( $npsp, 0, 2 ), array( 'PCM_Contact_Id__c', 'PCM_Account_Id__c' ) );
check( 'a person and their organization are on one row',
	in_array( 'Contact1_First_Name__c', $npsp, true ) && in_array( 'Account1_Name__c', $npsp, true ), true );
check( 'donations are left out unless asked for',
	in_array( 'Donation_Amount__c', $npsp, true ), false );
check( 'and included when they are',
	in_array( 'Donation_Amount__c', pcm_crm_npsp_headers( true ), true ), true );

// Which spelling an org uses depends on how NPSP was installed, so it is a
// setting rather than a guess.
check( 'no namespace by default', pcm_crm_npsp_prefix(), '' );
update_option( 'pcm_crm_npsp_namespace', '1' );
check( 'the managed-package prefix is applied to every NPSP column',
	in_array( 'npsp__Contact1_First_Name__c', pcm_crm_npsp_headers( false ), true ), true );
check( 'but not to our own id columns, which are not NPSP fields',
	in_array( 'PCM_Contact_Id__c', pcm_crm_npsp_headers( false ), true ), true );
delete_option( 'pcm_crm_npsp_namespace' );

echo "\n--- schema ---\n";
check( 'ten tables defined', count( ( new ReflectionMethod( 'PCM_CRM_Schema', 'definitions' ) )->invoke( null, '' ) ), 10 );
$defs = implode( "\n", ( new ReflectionMethod( 'PCM_CRM_Schema', 'definitions' ) )->invoke( null, '' ) );
check( 'history has an index for finding the open row',
	strpos( $defs, 'pcm_hist_open (opportunity_id,exited_date)' ) !== false, true );
check( 'opportunities can be sorted by when they entered their stage',
	strpos( $defs, 'pcm_opp_entered (stage_entered_date)' ) !== false, true );

/* ---------------------------------------------------------------------------
   The object registry
   --------------------------------------------------------------------------- */

// Four hardcoded lists became one registry. These assertions are the guard for
// that refactor: every one of them passed before it and must pass after, which
// is the only way to know a pure restructuring stayed pure.
echo "\n--- object registry ---\n";

// Order is behaviour, not presentation: the generic REST routes build their slug
// alternation from these keys.
check( 'the same nine objects, in the same order', array_keys( PCM_CRM_REST::models() ), array(
	'accounts', 'contacts', 'opportunities', 'activities', 'submissions',
	'schedules', 'templates', 'sequences', 'enrollments',
) );

check( 'the four customisable objects are unchanged',
	array_keys( pcm_crm_customisable_objects() ),
	array( 'accounts', 'contacts', 'opportunities', 'activities' ) );

check( 'and still carry their labels',
	pcm_crm_customisable_objects()['opportunities'], 'Opportunities' );

// Export order is the order a Data Loader run needs: a Contact cannot reference
// an Account that does not exist yet.
check( 'the exportable objects keep their dependency order',
	array_keys( pcm_crm_exportable_objects() ),
	array( 'accounts', 'contacts', 'opportunities', 'activities' ) );

check( 'and their Salesforce names', pcm_crm_exportable_objects()['activities']['sf'], 'Task' );

// What used to be the skip-list inside PCM_CRM_REST::schema(), inverted: an
// allow-list, so a new object is invisible to the filter builder until it asks.
check( 'the same four objects are reportable',
	array_keys( pcm_crm_objects_where( 'reportable' ) ),
	array( 'accounts', 'contacts', 'opportunities', 'activities' ) );

// The fifth list this registry replaced. Every object here is soft-deleted, so a
// deleted one is restorable rather than gone.
check( 'the same four objects are recyclable',
	array_keys( pcm_crm_objects_where( 'recyclable' ) ),
	array( 'accounts', 'contacts', 'opportunities', 'activities' ) );
check( 'and the bin still labels them',
	pcm_crm_recyclable_objects()['contacts'], 'Contacts' );

check( 'machinery is not reportable', pcm_crm_object_is( 'templates', 'reportable' ), false );
// An enrollment has an is_deleted column but is bookkeeping, not a record
// anyone would go looking for in a bin.
check( 'nor recyclable', pcm_crm_object_is( 'enrollments', 'recyclable' ), false );
check( 'nor customisable', pcm_crm_object_is( 'schedules', 'customisable' ), false );
check( 'nor exportable', pcm_crm_object_is( 'submissions', 'exportable' ), false );

// The related gate the browser reads off the same registry.
check( 'an account fetches its related lists', pcm_crm_object( 'accounts' )['related'], 'fetch' );
check( 'an activity builds them from the record in hand', pcm_crm_object( 'activities' )['related'], 'local' );
check( 'a template has none at all', pcm_crm_object( 'templates' )['related'], '' );

check( 'an unknown slug is absent rather than an error', pcm_crm_object( 'nope' ), null );
check( 'and reports no flags', pcm_crm_object_is( 'nope', 'reportable' ), false );
check( 'and resolves to no model', pcm_crm_object_model( 'nope' ), null );
check( 'a registered slug resolves to its model',
	pcm_crm_object_model( 'accounts' ) === pcm_crm_accounts(), true );

/* ---------------------------------------------------------------------------
   Related-list providers
   --------------------------------------------------------------------------- */

echo "\n--- related providers ---\n";

check( 'the same three objects have related lists',
	array_keys( pcm_crm_related_providers() ),
	array( 'accounts', 'contacts', 'opportunities' ) );

check( 'an object with no provider returns nothing rather than failing',
	pcm_crm_related_for( 'templates', 1 ), array() );

// Several providers may register against one slug and their returns merge, which
// is what lets a module add a list without touching the one that builds the
// other three.
pcm_crm_register_related( 'accounts', function ( $pcm_id ) {
	return array( 'widgets' => array( array( 'id' => $pcm_id ) ) );
} );

check( 'a second provider on the same slug is kept',
	count( pcm_crm_related_providers()['accounts'] ), 2 );

$GLOBALS['pcm_crm_related']['accounts'] = array( 'pcm_crm_related_account' );

/* ---------------------------------------------------------------------------
   Merge-token prefixes
   --------------------------------------------------------------------------- */

echo "\n--- merge prefixes ---\n";

// The picker and the substitution read one map now. Both sides are asserted
// because the whole point was that they cannot drift apart.
check( 'the prefix map is contact and account',
	pcm_crm_merge_prefixes(), array( 'contact' => 'contacts', 'account' => 'accounts' ) );

$pcm_prefixes = wp_list_pluck( pcm_crm_email_variables(), 'prefix' );
check( 'the picker offers the same prefixes, plus the composed group',
	$pcm_prefixes, array( 'contact', 'account', 'other' ) );

// Opportunity was deliberately withdrawn: a contact has many and no send path
// picks one, so the token could look right and go out with a hole in it.
check( 'no opportunity prefix is offered', in_array( 'opportunity', $pcm_prefixes, true ), false );

check( 'a value still fills',
	pcm_crm_fill_variables( 'Hi {{contact.first_name}}', array( 'contact' => array( 'first_name' => 'Dana' ) ) ),
	'Hi Dana' );

check( 'and a token with nothing behind it still blanks',
	pcm_crm_fill_variables( '[{{contact.nonsense}}]', array( 'contact' => array() ) ), '[]' );

check( 'escaping still holds',
	pcm_crm_fill_variables( '{{contact.first_name}}', array( 'contact' => array( 'first_name' => '<b>x</b>' ) ) ),
	'&lt;b&gt;x&lt;/b&gt;' );

// The seam a derived token rides: a meter or a remaining balance is not a column
// on anything, so it cannot come from the prefix loop.
pcm_test_add_filter( 'pcm_crm_merge_values', function ( $pcm_values ) {
	$pcm_values['{{project.hours_remaining}}'] = '14';

	return $pcm_values;
} );

check( 'a derived value can be added by filter',
	pcm_crm_fill_variables( '{{project.hours_remaining}} left', array() ), '14 left' );

pcm_test_reset_filters( 'pcm_crm_merge_values' );

check( 'and is gone again with the filter',
	pcm_crm_fill_variables( '[{{project.hours_remaining}}]', array() ), '[]' );

check( 'a seed context passes through untouched with no listener',
	pcm_crm_fill_context( array( 'project' => array( 'id' => 1 ) ) ),
	array( 'project' => array( 'id' => 1 ) ) );

/* ---------------------------------------------------------------------------
   Duration parsing
   --------------------------------------------------------------------------- */

echo "\n--- duration parsing ---\n";

check( 'plain decimal hours', pcm_crm_parse_hours( '1.5' ), 1.5 );
check( 'a whole number', pcm_crm_parse_hours( '8' ), 8.0 );
check( 'h:mm', pcm_crm_parse_hours( '1:30' ), 1.5 );
check( 'a quarter hour', pcm_crm_parse_hours( '0:15' ), 0.25 );
check( 'minutes only, half typed', pcm_crm_parse_hours( ':45' ), 0.75 );
check( 'minutes past sixty normalise rather than fail', pcm_crm_parse_hours( '2:75' ), 3.25 );
check( 'bare minutes', pcm_crm_parse_hours( '90m' ), 1.5 );
check( 'hours and minutes', pcm_crm_parse_hours( '1h30m' ), 1.5 );
check( 'with the m left off', pcm_crm_parse_hours( '1h30' ), 1.5 );
check( 'with a space', pcm_crm_parse_hours( '1h 30' ), 1.5 );
check( 'decimal hours with a unit', pcm_crm_parse_hours( '1.5h' ), 1.5 );
check( 'an hour on its own is not read as 1.5', pcm_crm_parse_hours( '1h' ), 1.0 );
check( 'rounded to two decimals', pcm_crm_parse_hours( '0.333' ), 0.33 );

// Null, not zero: "no time recorded" is a different statement from "no time
// taken", and a burn-down needs to tell them apart.
check( 'blank is null', pcm_crm_parse_hours( '' ), null );
check( 'null is null', pcm_crm_parse_hours( null ), null );
check( 'unreadable is null, not a guess', pcm_crm_parse_hours( 'abc' ), null );
check( 'negative time is refused rather than absolved', pcm_crm_parse_hours( '-2' ), null );
check( 'an array is null rather than a warning', pcm_crm_parse_hours( array( 1 ) ), null );

// Stricter than the decimal type on purpose: that one reads "1.2.3" as 1.2, and
// an invoice built on a guess is worse than a rejected entry.
check( 'a malformed decimal is refused, unlike the decimal type', pcm_crm_parse_hours( '1.2.3' ), null );

check( 'and back out as a timesheet reads', pcm_crm_format_hours( 1.5 ), '1:30' );
check( 'padding the minutes', pcm_crm_format_hours( 2.25 ), '2:15' );
check( 'nothing formats as nothing', pcm_crm_format_hours( null ), '' );

// The wiring, which is the part that actually breaks: the type has to be on the
// field and the parser has to be reached. A %s format here would store '1.5' and
// look correct right up until a SUM.
$pcm_hours_model = new PCM_CRM_Model( 'hours_probe', 'probe', array(
	'hours' => array( 'type' => 'hours', 'label' => 'Hours' ),
) );

check( 'the hours type reaches the parser through sanitize()',
	$pcm_hours_model->sanitize( array( 'hours' => '1:30' ) ), array( 'hours' => 1.5 ) );

// Required on the plugin's PHP 7.4 floor, a deprecation notice from 8.5 —
// so asked for only where it does something.
$pcm_reach = function ( $pcm_method ) {
	$pcm_ref = new ReflectionMethod( 'PCM_CRM_Model', $pcm_method );

	if ( PHP_VERSION_ID < 80100 ) { $pcm_ref->setAccessible( true ); }

	return $pcm_ref;
};

$pcm_formats = $pcm_reach( 'formats' );
check( 'and is stored as a float, not a string',
	$pcm_formats->invoke( $pcm_hours_model, array( 'hours' => 1.5 ) ), array( '%f' ) );

$pcm_cast = $pcm_reach( 'cast_row' );
check( 'and comes back out of the database as a float',
	$pcm_cast->invoke( $pcm_hours_model, array( 'id' => '1', 'hours' => '1.50' ) ),
	array( 'id' => 1, 'hours' => 1.5 ) );

/* ---------------------------------------------------------------------------
   The module gate
   --------------------------------------------------------------------------- */

echo "\n--- module gate ---\n";

// The suite loads the plugin with no options set, so the module is at its
// default — off. Everything below is asserted from that starting point outwards.
check( 'the pm module is registered', isset( pcm_crm_modules()['pm'] ), true );
check( 'and is off by default', pcm_crm_module_active( 'pm' ), false );

update_option( PCM_CRM_MODULES_OPTION, array( 'pm' => 1 ) );
check( 'the option switches it on', pcm_crm_module_active( 'pm' ), true );

update_option( PCM_CRM_MODULES_OPTION, array( 'pm' => 0 ) );
check( 'and off again', pcm_crm_module_active( 'pm' ), false );

pcm_test_add_filter( 'pcm_crm_module_active', function () { return true; } );
check( 'a filter overrides the option', pcm_crm_module_active( 'pm' ), true );
pcm_test_reset_filters( 'pcm_crm_module_active' );

delete_option( PCM_CRM_MODULES_OPTION );

check( 'an unregistered module is off rather than an error', pcm_crm_module_active( 'nope' ), false );

// An unchecked box posts nothing, so the sanitiser writes an explicit 0 for every
// registered module. A missing key would fall back to the default, which would
// make a module defaulting to on impossible to switch off.
check( 'saving with nothing ticked writes an explicit off',
	pcm_crm_sanitize_modules( array() ), array( 'pm' => 0 ) );
check( 'and a tick writes an explicit on',
	pcm_crm_sanitize_modules( array( 'pm' => '1' ) ), array( 'pm' => 1 ) );
// A module that has been removed should not leave a setting nothing reads.
check( 'an unknown key is dropped',
	pcm_crm_sanitize_modules( array( 'pm' => 1, 'ghost' => 1 ) ), array( 'pm' => 1 ) );

// The switched-off surface. These are what "it leaves the navigation" means in
// terms anything can check.
check( 'switched off, projects are not a registered object', pcm_crm_object( 'projects' ), null );
check( 'nor resolvable to a model', PCM_CRM_REST::model( 'projects' ), null );
check( 'nor reportable', pcm_crm_object_is( 'projects', 'reportable' ), false );
check( 'projects have no related provider',
	isset( pcm_crm_related_providers()['projects'] ), false );
check( 'and no project merge prefix is offered',
	isset( pcm_crm_merge_prefixes()['project'] ), false );

// The deliberate asymmetry, asserted so nobody tidies it away: storage is not
// gated. install() is keyed on one version option, so a table withheld here
// would never be created by a later switch-on that found the versions equal.
$pcm_defs_method = new ReflectionMethod( 'PCM_CRM_Schema', 'definitions' );

if ( PHP_VERSION_ID < 80100 ) { $pcm_defs_method->setAccessible( true ); }

// The module appends on pcm_crm_table_definitions, and the stub's apply_filters
// is a pass-through unless a hook is opted in — so opt this one in with the real
// listener, which is what the assertion is actually about.
pcm_test_add_filter( 'pcm_crm_table_definitions', 'pcm_crm_pm_definitions' );

$pcm_all_defs = $pcm_defs_method->invoke( null, '' );

// Only the module's own statements, so a rule about PM tables is never quietly
// measured against core's.
$pcm_pm_only = array();

foreach ( $pcm_all_defs as $pcm_def ) {
	if ( preg_match( '/pcm_crm_(projects|project_tasks|project_raid|project_roles|time_entries|retainer_periods|allocations|status_reports) \(/', $pcm_def ) ) {
		$pcm_pm_only[] = $pcm_def;
	}
}

$pcm_pm_defs = implode( "\n", $pcm_pm_only );

foreach ( array( 'projects', 'project_tasks', 'project_raid', 'project_roles',
	'time_entries', 'retainer_periods', 'allocations', 'status_reports' ) as $pcm_table ) {
	check( "the {$pcm_table} table is defined even with the module off",
		false !== strpos( $pcm_pm_defs, 'pcm_crm_' . $pcm_table . ' (' ), true );
}

check( 'the models load whatever the switch says',
	pcm_crm_projects() instanceof PCM_CRM_Model, true );
check( 'and so does the arithmetic', function_exists( 'pcm_crm_pm_week_start' ), true );

/* ---------------------------------------------------------------------------
   PM schema
   --------------------------------------------------------------------------- */

echo "\n--- pm schema ---\n";

check( 'the version was bumped so the type migration runs', PCM_CRM_Schema::VERSION, '1.9.0' );
check( 'ten core tables and eight of the module\'s',
	array( count( $pcm_all_defs ), count( $pcm_pm_only ) ), array( 18, 8 ) );

// dbDelta re-adds an index on every run if the KEY is not named, and wants two
// spaces after PRIMARY KEY. Both are easy to get wrong and silent when wrong.
check( 'every PM table names its primary key the way dbDelta wants',
	substr_count( $pcm_pm_defs, 'PRIMARY KEY  (id)' ), 8 );
check( 'no unnamed KEY anywhere in the PM tables',
	(bool) preg_match( '/\n\t\t(?:UNIQUE )?KEY \(/', $pcm_pm_defs ), false );

// The races these two prevent are the likeliest data bugs in the module: two
// periods for one month halves a burn-down, and a duplicate allocation reads as
// over-allocation.
check( 'one retainer period per project per start date',
	false !== strpos( $pcm_pm_defs, 'UNIQUE KEY pcm_period_unique (project_id,period_start)' ), true );
check( 'one allocation per person per project per week',
	false !== strpos( $pcm_pm_defs, 'UNIQUE KEY pcm_alloc_unique (project_id,user_id,week_start)' ), true );

// The over-allocation query's GROUP BY, so it runs index-ordered with no
// temporary table.
check( 'allocations are indexed by person and week',
	false !== strpos( $pcm_pm_defs, 'KEY pcm_alloc_person_week (user_id,week_start)' ), true );
// Every meter aggregate is a SUM over one project within a window.
check( 'time is indexed by project and date',
	false !== strpos( $pcm_pm_defs, 'KEY pcm_time_project (project_id,entry_date)' ), true );
// Burn-down as one indexed sum rather than a date-range scan.
check( 'and by the period it belongs to',
	false !== strpos( $pcm_pm_defs, 'KEY pcm_time_period (retainer_period_id)' ), true );
check( 'a RAID tab reads one index',
	false !== strpos( $pcm_pm_defs, 'KEY pcm_raid_project (project_id,raid_type,status)' ), true );

// utf8mb4's index-length ceiling. An indexed varchar past 190 fails to create on
// older MySQL, and dbDelta says nothing about it.
// Columns that actually appear inside a KEY, read off the KEY lines rather than
// guessed at by looking for the name in parentheses anywhere — which catches a
// column whose name merely occurs in some other index's definition.
$pcm_indexed = array();

if ( preg_match_all( '/KEY \w+ \(([^)]+)\)/', $pcm_pm_defs, $pcm_keys ) ) {
	foreach ( $pcm_keys[1] as $pcm_columns ) {
		foreach ( explode( ',', $pcm_columns ) as $pcm_column ) {
			$pcm_indexed[] = trim( $pcm_column );
		}
	}
}

$pcm_too_wide = array();

if ( preg_match_all( '/(\w+) varchar\((\d+)\)/', $pcm_pm_defs, $pcm_widths, PREG_SET_ORDER ) ) {
	foreach ( $pcm_widths as $pcm_match ) {
		if ( (int) $pcm_match[2] > 190 && in_array( $pcm_match[1], $pcm_indexed, true ) ) {
			$pcm_too_wide[] = $pcm_match[1];
		}
	}
}

check( 'no indexed varchar is wider than utf8mb4 allows', $pcm_too_wide, array() );

check( 'every PM table can be marked as test data',
	substr_count( $pcm_pm_defs, 'is_test tinyint(1) NOT NULL DEFAULT 0' ), 8 );

/* ---------------------------------------------------------------------------
   Project stages
   --------------------------------------------------------------------------- */

echo "\n--- project stages ---\n";

check( 'a retainer and a build do not share a lifecycle',
	pcm_crm_pm_stage_names( 'Salesforce Support Retainer' ) === pcm_crm_pm_stage_names( 'Custom Development' ),
	false );

check( 'the two retainers do share one',
	pcm_crm_pm_stage_names( 'Salesforce Support Retainer' ),
	pcm_crm_pm_stage_names( 'AI Enablement Retainer' ) );

check( 'a retainer ends or churns',
	array_slice( pcm_crm_pm_stage_names( 'AI Enablement Retainer' ), -2 ),
	array( 'Ended', 'Churned' ) );

check( 'a build launches and closes',
	in_array( 'UAT', pcm_crm_pm_stage_names( 'Custom Development' ), true ), true );

// The mistake worth catching: a stage that saves cleanly, then reads as
// closed-or-not according to a set the project was never in.
check( 'a build stage is not valid on a retainer',
	pcm_crm_pm_stage( 'AI Enablement Retainer', 'UAT' ), null );
check( 'and a retainer stage is not valid on a build',
	pcm_crm_pm_stage( 'Custom Development', 'Renewal Pending' ), null );

check( 'a closing stage says so', pcm_crm_pm_stage( 'Custom Development', 'Cancelled' )['is_closed'], 1 );
check( 'and a running one does not', pcm_crm_pm_stage( 'Custom Development', 'Build' )['is_closed'], 0 );
// Renewed is a moment rather than a state: a project passing through it returns
// to Active, so it must not read as either running or finished.
check( 'Renewed is neither active nor closed',
	array( pcm_crm_pm_stage( 'AI Enablement Retainer', 'Renewed' )['is_active'],
		pcm_crm_pm_stage( 'AI Enablement Retainer', 'Renewed' )['is_closed'] ),
	array( 0, 0 ) );

// An empty picklist makes a project unsaveable, and a type arriving from an old
// row or a filter is not the project's fault.
check( 'an unknown type still gets a usable stage list',
	count( pcm_crm_pm_stages( 'Something Else' ) ) > 0, true );

check( 'the union covers both lifecycles',
	in_array( 'Hypercare', pcm_crm_pm_all_stage_names(), true )
		&& in_array( 'Churned', pcm_crm_pm_all_stage_names(), true ), true );

check( 'closed stages are left out of the open list',
	in_array( 'Ended', pcm_crm_pm_open_stage_names( 'AI Enablement Retainer' ), true ), false );

check( 'retainers are identified from a list, not from their name',
	array( pcm_crm_pm_is_retainer( 'AI Enablement Retainer' ), pcm_crm_pm_is_retainer( 'Custom Development' ) ),
	array( true, false ) );

pcm_test_add_filter( 'pcm_crm_pm_stages', function ( $pcm_sets ) {
	$pcm_sets['custom-development'][] = array( 'name' => 'Warranty', 'order' => 85, 'is_active' => 0, 'is_closed' => 0, 'is_renewal' => 0 );

	return $pcm_sets;
} );
check( 'a filter can add a stage',
	in_array( 'Warranty', pcm_crm_pm_stage_names( 'Custom Development' ), true ), true );
pcm_test_reset_filters( 'pcm_crm_pm_stages' );

/* ---------------------------------------------------------------------------
   RAID severity
   --------------------------------------------------------------------------- */

echo "\n--- raid severity ---\n";

// The product of two ordinal weights, which is the point: a lexical sort on
// either column alone gets Medium x High and High x Low the wrong way round.
check( 'high and high is the worst', pcm_crm_pm_severity( 'High', 'High' ), 9 );
check( 'medium impact at high probability outranks high impact at low',
	pcm_crm_pm_severity( 'High', 'Medium' ) > pcm_crm_pm_severity( 'Low', 'High' ), true );
check( 'low and low is the least', pcm_crm_pm_severity( 'Low', 'Low' ), 1 );

// Zero rather than one, so an unscored risk sorts below a scored Low instead of
// level with it.
check( 'an unscored risk is zero, not one', pcm_crm_pm_severity( '', 'High' ), 0 );
check( 'and so is a nonsense level', pcm_crm_pm_severity( 'Catastrophic', 'High' ), 0 );

/* ---------------------------------------------------------------------------
   Allocation weeks
   --------------------------------------------------------------------------- */

echo "\n--- allocation weeks ---\n";

update_option( 'start_of_week', 1 );

// 2026-09-11 is a Friday; its week began on Monday the 7th.
check( 'a midweek date snaps back to its Monday', pcm_crm_pm_week_start( '2026-09-11' ), '2026-09-07' );
check( 'a Monday is its own week start', pcm_crm_pm_week_start( '2026-09-07' ), '2026-09-07' );
// The edge that a naive "subtract w days" gets wrong, because PHP's w makes
// Sunday nought.
check( 'a Sunday belongs to the week before it', pcm_crm_pm_week_start( '2026-09-13' ), '2026-09-07' );

update_option( 'start_of_week', 0 );
check( 'a Sunday-start site buckets differently', pcm_crm_pm_week_start( '2026-09-11' ), '2026-09-06' );
update_option( 'start_of_week', 1 );

check( 'a malformed date is null rather than today', pcm_crm_pm_week_start( 'soon' ), null );
check( 'and so is an empty one', pcm_crm_pm_week_start( '' ), null );

$pcm_run = pcm_crm_pm_weeks( '2026-09-09', 13 );
check( 'thirteen weeks is thirteen columns', count( $pcm_run ), 13 );
check( 'starting from the containing week', $pcm_run[0], '2026-09-07' );
// Arithmetic is done at UTC noon precisely so a zone that springs forward at
// midnight cannot produce a six-day week.
check( 'and every step is exactly seven days',
	( strtotime( $pcm_run[12] ) - strtotime( $pcm_run[0] ) ) / DAY_IN_SECONDS, 84 );

// What the date-range form writes. A Wednesday-to-Wednesday booking occupies two
// weeks and both should show.
check( 'a range covering two weeks writes two rows',
	pcm_crm_pm_expand_range( '2026-09-09', '2026-09-16' ),
	array( '2026-09-07', '2026-09-14' ) );
check( 'a range inside one week writes one',
	pcm_crm_pm_expand_range( '2026-09-08', '2026-09-10' ), array( '2026-09-07' ) );
check( 'a backwards range writes nothing',
	pcm_crm_pm_expand_range( '2026-09-16', '2026-09-09' ), array() );

/* ---------------------------------------------------------------------------
   The PM surface, with the module switched on
   --------------------------------------------------------------------------- */

// The suite loaded the plugin with the module off, so the gated files were never
// required. Switch it on and load them, which is exactly what the bootstrap does
// on a site where the box is ticked.
update_option( PCM_CRM_MODULES_OPTION, array( 'pm' => 1 ) );
pcm_crm_load_modules();

echo "\n--- pm surface ---\n";

check( 'switching it on registers the objects', pcm_crm_object( 'projects' ) !== null, true );
check( 'projects are reportable', pcm_crm_object_is( 'projects', 'reportable' ), true );
// A custom field is a real ALTER TABLE and columns are never dropped, so a cf_
// column on a project would outlive the module being switched off.
check( 'but not customisable in this release', pcm_crm_object_is( 'projects', 'customisable' ), false );
check( 'and they are exportable, because a table you cannot read out of is a liability',
	pcm_crm_object_is( 'projects', 'exportable' ), true );
// Deleting a project by accident should be survivable, the way deleting an
// account is.
check( 'a deleted project can be restored',
	pcm_crm_object_is( 'projects', 'recyclable' ), true );
check( 'and the bin lists the module\'s records too',
	in_array( 'Projects', pcm_crm_recyclable_objects(), true ), true );
check( 'but not its bookkeeping',
	pcm_crm_object_is( 'retainer_periods', 'recyclable' ), false );

check( 'the route slugs now include the module\'s',
	in_array( 'projects', array_keys( PCM_CRM_REST::models() ), true ), true );
// Registration order is route order, and core must keep its place at the front.
check( 'and core still comes first',
	array_slice( array_keys( PCM_CRM_REST::models() ), 0, 4 ),
	array( 'accounts', 'contacts', 'opportunities', 'activities' ) );

check( 'a project has related lists to fetch', pcm_crm_object( 'projects' )['related'], 'fetch' );
check( 'and accounts gained a second provider for them',
	count( pcm_crm_related_providers()['accounts'] ), 2 );
check( 'as did opportunities', count( pcm_crm_related_providers()['opportunities'] ), 2 );

echo "\n--- project merge tokens ---\n";

pcm_test_add_filter( 'pcm_crm_merge_prefixes', 'pcm_crm_pm_merge_prefix' );

check( 'the project prefix is offered once the module is on',
	array_keys( pcm_crm_merge_prefixes() ), array( 'contact', 'account', 'project' ) );

$pcm_pm_groups = pcm_crm_email_variables();
check( 'and the picker gains a Project group',
	wp_list_pluck( $pcm_pm_groups, 'prefix' ), array( 'contact', 'account', 'project', 'other' ) );

$pcm_project_group = array_values( array_filter( $pcm_pm_groups, function ( $pcm_group ) {
	return 'project' === $pcm_group['prefix'];
} ) );

$pcm_project_tokens = wp_list_pluck( $pcm_project_group[0]['fields'], 'token' );

check( 'the project name is offered', in_array( '{{project.name}}', $pcm_project_tokens, true ), true );
// Ids and flags make poor sentences — the same rule core applies to its own.
check( 'ids are not', in_array( '{{project.account_id}}', $pcm_project_tokens, true ), false );
check( 'nor are checkboxes', in_array( '{{project.retainer_rollover}}', $pcm_project_tokens, true ), false );

$pcm_malformed = array_filter( $pcm_project_tokens, function ( $pcm_token ) {
	return ! preg_match( '/^\{\{project\.[a-z_]+\}\}$/', $pcm_token );
} );
check( 'every project token is well formed', $pcm_malformed, array() );

check( 'a project value fills',
	pcm_crm_fill_variables( '{{project.name}} is {{project.stage_name}}',
		array( 'project' => array( 'name' => 'Acme retainer', 'stage_name' => 'Active' ) ) ),
	'Acme retainer is Active' );

check( 'a project name cannot inject markup',
	pcm_crm_fill_variables( '{{project.name}}', array( 'project' => array( 'name' => '<b>x</b>' ) ) ),
	'&lt;b&gt;x&lt;/b&gt;' );

check( 'a project token with nothing behind it blanks',
	pcm_crm_fill_variables( '[{{project.budget_amount}}]', array( 'project' => array() ) ), '[]' );

pcm_test_reset_filters( 'pcm_crm_merge_prefixes' );

echo "\n--- project types and archetypes ---\n";

check( 'a type is found by its key', pcm_crm_pm_type( 'custom-development' )['label'], 'Custom Development' );
// Every project stored before types had keys holds the label.
check( 'and by the label an older row stores', pcm_crm_pm_type_key( 'AI Enablement Retainer' ), 'ai-enablement-retainer' );
check( 'an unknown type is nobody', pcm_crm_pm_type( 'Consulting' ), null );
check( 'a type follows its archetype’s time rules', pcm_crm_pm_type( 'custom-development' )['time']['task_required'], 1 );
check( 'a build hides the retainer fields',
	in_array( 'retainer_hours', pcm_crm_pm_type_fields( 'custom-development' )['hidden'], true ), true );
check( 'and a retainer hides the budget',
	in_array( 'budget_amount', pcm_crm_pm_type_fields( 'salesforce-support-retainer' )['hidden'], true ), true );

update_option( PCM_CRM_PM_TYPES_OPTION, array(
	'studio-time' => array( 'label' => 'Studio Time', 'archetype' => 'tm', 'time' => array( 'description_required' => 1, 'not_a_rule' => 1 ) ),
	'side-quest'  => array( 'label' => 'Side Quest', 'archetype' => 'internal', 'active' => 0 ),
) );
check( 'saved types replace the shipped ones', pcm_crm_pm_project_types(), array( 'studio-time', 'side-quest' ) );
check( 'a type can tighten its archetype’s rules',
	array( pcm_crm_pm_type( 'studio-time' )['time']['rate_required'], pcm_crm_pm_type( 'studio-time' )['time']['description_required'] ),
	array( 1, 1 ) );
check( 'but cannot invent one', isset( pcm_crm_pm_type( 'studio-time' )['time']['not_a_rule'] ), false );
check( 'an inactive type is left out when asked', array_keys( pcm_crm_pm_types( false ) ), array( 'studio-time' ) );
check( 'a T&M type relabels the budget as a cap', pcm_crm_pm_type_fields( 'studio-time' )['labels']['budget_amount'], 'Not-to-exceed Cap' );
delete_option( PCM_CRM_PM_TYPES_OPTION );

// The migration: label-keyed stage sets fold into their types, and every
// project's type is rewritten from label to key.
class PCM_Migrate_WPDB extends FakeWPDB {
	public $queries = array();
	function query( $q ) { $this->queries[] = $q; return 1; }
}
$pcm_real_wpdb = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = new PCM_Migrate_WPDB();
update_option( PCM_CRM_PM_STAGES_OPTION, array( 'Custom Development' => array( array( 'name' => 'Only', 'order' => 1, 'is_active' => 1, 'is_closed' => 0, 'is_renewal' => 0 ) ) ) );

pcm_crm_pm_migrate_types();

check( 'the migration saves the types', array_keys( get_option( PCM_CRM_PM_TYPES_OPTION ) ), array( 'salesforce-support-retainer', 'ai-enablement-retainer', 'custom-development' ) );
check( 'folds saved stages into their type', pcm_crm_pm_stage_names( 'custom-development' ), array( 'Only' ) );
check( 'and retires the old option', get_option( PCM_CRM_PM_STAGES_OPTION, 'gone' ), 'gone' );
check( 'rewrites each project’s type from label to key',
	count( array_filter( $GLOBALS['wpdb']->queries, function ( $q ) {
		return false !== strpos( $q, "SET project_type = 'custom-development' WHERE project_type = 'Custom Development'" );
	} ) ), 1 );

$GLOBALS['wpdb']->queries = array();
pcm_crm_pm_migrate_types();
check( 'and running it again changes nothing it already changed', pcm_crm_pm_stage_names( 'custom-development' ), array( 'Only' ) );

$GLOBALS['wpdb'] = $pcm_real_wpdb;
delete_option( PCM_CRM_PM_TYPES_OPTION );

echo "\n--- project bootstrap payload ---\n";

$pcm_pm_boot = pcm_crm_pm_bootstrap( array() );

check( 'the three project types are sent, by key with their names',
	$pcm_pm_boot['projectTypes'],
	array(
		array( 'value' => 'salesforce-support-retainer', 'label' => 'Salesforce Support Retainer' ),
		array( 'value' => 'ai-enablement-retainer', 'label' => 'AI Enablement Retainer' ),
		array( 'value' => 'custom-development', 'label' => 'Custom Development' ),
	) );
// Sent as a map so the record form can narrow the stage picklist once a type is
// chosen, without a request per keystroke.
check( 'and the per-type stage sets, so the form can narrow the picklist',
	array_keys( $pcm_pm_boot['projectStageSets'] ),
	array( 'salesforce-support-retainer', 'ai-enablement-retainer', 'custom-development' ) );
check( 'the stage union covers both lifecycles',
	in_array( 'Hypercare', $pcm_pm_boot['projectStages'], true )
		&& in_array( 'Churned', $pcm_pm_boot['projectStages'], true ), true );
check( 'and which types bill against an allotment',
	$pcm_pm_boot['retainerTypes'],
	array( 'salesforce-support-retainer', 'ai-enablement-retainer' ) );
check( 'each type says what its archetype decides',
	array( $pcm_pm_boot['projectTypeDefs']['custom-development']['archetype'], $pcm_pm_boot['projectTypeDefs']['custom-development']['time']['task_required'] ),
	array( 'fixed', 1 ) );
check( 'and the archetypes are described for the chooser',
	array_keys( $pcm_pm_boot['archetypes'] ), array( 'retainer', 'fixed', 'tm', 'internal' ) );

echo "\n--- project validation ---\n";

// Called directly, because the stub's add_filter is a no-op — the same way the
// schedule validator is tested.
$pcm_valid = function ( array $pcm_row, $pcm_id = 0 ) {
	$pcm_result = pcm_crm_pm_validate_project( null, 'project', $pcm_row, $pcm_id );

	return is_wp_error( $pcm_result ) ? $pcm_result->get_error_code() : 'ok';
};

check( 'a project needs a name', $pcm_valid( array( 'name' => '' ) ), 'pcm_crm_name_required' );
check( 'a named project is fine',
	$pcm_valid( array( 'name' => 'Acme retainer', 'project_type' => '', 'stage_name' => '', 'start_date' => null, 'end_date' => null ) ),
	'ok' );
check( 'an invented type is refused',
	$pcm_valid( array( 'name' => 'X', 'project_type' => 'Consulting', 'stage_name' => '', 'start_date' => null, 'end_date' => null ) ),
	'pcm_crm_pm_unknown_type' );

// The failure worth catching: it saves cleanly, then reads as closed-or-not
// according to a stage set the project was never in.
check( 'a build stage on a retainer is refused',
	$pcm_valid( array( 'name' => 'X', 'project_type' => 'AI Enablement Retainer', 'stage_name' => 'UAT', 'start_date' => null, 'end_date' => null ) ),
	'pcm_crm_pm_wrong_stage' );
check( 'but its own stage is accepted',
	$pcm_valid( array( 'name' => 'X', 'project_type' => 'AI Enablement Retainer', 'stage_name' => 'Active', 'start_date' => null, 'end_date' => null,
		'retainer_hours' => 20, 'retainer_period' => 'monthly' ) ),
	'ok' );

// What a type's process needs, asked when a project is created.
check( 'a new retainer without an allotment is refused',
	$pcm_valid( array( 'name' => 'X', 'project_type' => 'ai-enablement-retainer', 'stage_name' => 'Active', 'start_date' => null, 'end_date' => null, 'retainer_hours' => null, 'retainer_period' => '' ) ),
	'pcm_crm_pm_type_required' );
check( 'and says what is missing',
	pcm_crm_pm_missing_for_type( 'ai-enablement-retainer', array( 'retainer_hours' => 0, 'retainer_period' => 'monthly' ) ),
	array( 'Hours per Period' ) );
check( 'a build needs a budget and an end date',
	pcm_crm_pm_missing_for_type( 'custom-development', array() ), array( 'Budget', 'Planned End Date' ) );
check( 'an end date before the start is refused',
	$pcm_valid( array( 'name' => 'X', 'project_type' => '', 'stage_name' => '', 'start_date' => '2026-06-01', 'end_date' => '2026-05-01' ) ),
	'pcm_crm_pm_bad_window' );

echo "\n--- contact role validation ---\n";

$pcm_role_valid = function ( array $pcm_row ) {
	$pcm_result = pcm_crm_pm_validate_role( null, 'project_role', array_merge( array(
		'party_type' => '', 'user_id' => 0, 'contact_id' => 0, 'partner_account_id' => 0,
	), $pcm_row ), 0 );

	return is_wp_error( $pcm_result ) ? $pcm_result->get_error_code() : 'ok';
};

check( 'a role has to say which side someone is on',
	$pcm_role_valid( array() ), 'pcm_crm_pm_unknown_party' );
// The failure this catches looks fine in a list, counts towards the team, and
// names nobody.
check( 'an internal role without a team member is refused',
	$pcm_role_valid( array( 'party_type' => 'internal' ) ), 'pcm_crm_pm_no_user' );
check( 'with one, it is fine',
	$pcm_role_valid( array( 'party_type' => 'internal', 'user_id' => 4 ) ), 'ok' );
check( 'a client role without a contact is refused — that is who a report goes to',
	$pcm_role_valid( array( 'party_type' => 'client' ) ), 'pcm_crm_pm_no_contact' );
check( 'with one, it is fine',
	$pcm_role_valid( array( 'party_type' => 'client', 'contact_id' => 7 ) ), 'ok' );
check( 'a partner role needs either a person or the firm',
	$pcm_role_valid( array( 'party_type' => 'partner' ) ), 'pcm_crm_pm_no_partner' );
// A firm can be engaged before anyone there has been named, and refusing the row
// would mean not recording the engagement at all.
check( 'the firm alone is enough',
	$pcm_role_valid( array( 'party_type' => 'partner', 'partner_account_id' => 3 ) ), 'ok' );
check( 'and so is a named person there',
	$pcm_role_valid( array( 'party_type' => 'partner', 'contact_id' => 9 ) ), 'ok' );

echo "\n--- time entry validation ---\n";

$pcm_time_valid = function ( array $pcm_row ) {
	$pcm_result = pcm_crm_pm_validate_time_entry( null, 'time_entry', array_merge( array(
		'project_id' => 12, 'hours' => 1.5,
	), $pcm_row ), 0 );

	return is_wp_error( $pcm_result ) ? $pcm_result->get_error_code() : 'ok';
};

check( 'a plain entry is fine', $pcm_time_valid( array() ), 'ok' );
check( 'time needs a project', $pcm_time_valid( array( 'project_id' => 0 ) ), 'pcm_crm_pm_no_project' );
// Null is what the parser returns for something it could not read, so this
// catches "1h3O" with a letter in it as well as a blank box.
check( 'unreadable hours are refused rather than stored as nothing',
	$pcm_time_valid( array( 'hours' => null ) ), 'pcm_crm_pm_no_hours' );
check( 'and so is no time at all', $pcm_time_valid( array( 'hours' => 0 ) ), 'pcm_crm_pm_no_hours' );
// Always a typo — usually a date typed into the hours box.
check( 'more than a day in one entry is refused',
	$pcm_time_valid( array( 'hours' => 30 ) ), 'pcm_crm_pm_too_many_hours' );
check( 'a full day is not', $pcm_time_valid( array( 'hours' => 24 ) ), 'ok' );

// What the project's type decides. A wpdb that answers get() for a project of
// each archetype, and for a task on project 12.
class PCM_Time_WPDB extends FakeWPDB {
	function get_row( $q = '', $o = null ) {
		$types = array( 12 => 'custom-development', 13 => 'salesforce-support-retainer', 14 => 'studio-time', 15 => 'side-quest' );

		if ( false !== strpos( $q, 'pcm_crm_projects' ) && preg_match( '/id = (\d+)/', $q, $m ) && isset( $types[ (int) $m[1] ] ) ) {
			return array( 'id' => (int) $m[1], 'project_type' => $types[ (int) $m[1] ], 'default_bill_rate' => 14 === (int) $m[1] ? '' : '150.00', 'default_cost_rate' => '60.00', 'name' => 'P' );
		}

		if ( false !== strpos( $q, 'pcm_crm_project_tasks' ) && preg_match( '/id = (\d+)/', $q, $m ) ) {
			return array( 'id' => (int) $m[1], 'project_id' => 77 === (int) $m[1] ? 12 : 99, 'name' => 'Build' );
		}

		return null;
	}
}

update_option( PCM_CRM_PM_TYPES_OPTION, array_merge( pcm_crm_pm_default_types(), array(
	'studio-time' => array( 'label' => 'Studio Time', 'archetype' => 'tm' ),
	'side-quest'  => array( 'label' => 'Side Quest', 'archetype' => 'internal' ),
) ) );
$pcm_real_wpdb = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = new PCM_Time_WPDB();

check( 'time on a fixed-scope build needs a task', $pcm_time_valid( array() ), 'pcm_crm_pm_task_required' );
check( 'and gets it', $pcm_time_valid( array( 'task_id' => 77 ) ), 'ok' );
check( 'but not a task from another project', $pcm_time_valid( array( 'task_id' => 78 ) ), 'pcm_crm_pm_task_elsewhere' );
check( 'a retainer does not ask for one', $pcm_time_valid( array( 'project_id' => 13 ) ), 'ok' );
check( 'billable T&M time with no rate anywhere is refused',
	$pcm_time_valid( array( 'project_id' => 14, 'is_billable' => 1 ) ), 'pcm_crm_pm_rate_required' );
check( 'internal time says what it was for', $pcm_time_valid( array( 'project_id' => 15 ) ), 'pcm_crm_pm_description_required' );

$pcm_applied = pcm_crm_pm_apply_time_rules( array( 'project_id' => 15, 'is_billable' => 1, 'hours' => 1 ), 'time_entry' );
check( 'internal time is never billable, whatever was posted', $pcm_applied['is_billable'], 0 );

$pcm_applied = pcm_crm_pm_apply_time_rules( array( 'project_id' => 12, 'hours' => 1 ), 'time_entry' );
check( 'a new entry takes its type’s billable default', $pcm_applied['is_billable'], 1 );
check( 'and the project’s rate, saying where it came from',
	array( $pcm_applied['bill_rate'], $pcm_applied['cost_rate'], $pcm_applied['rate_source'] ), array( 150.0, 60.0, 'project' ) );

$pcm_applied = pcm_crm_pm_apply_time_rules( array( 'project_id' => 12, 'hours' => 1, 'bill_rate' => 90 ), 'time_entry' );
check( 'a rate typed on the entry wins', array( $pcm_applied['bill_rate'], isset( $pcm_applied['rate_source'] ) ), array( 90, false ) );

update_option( PCM_CRM_PM_TIME_OPTION, array( 'max_hours' => 10, 'allow_future' => 0 ) );
check( 'the ceiling on one entry is a setting',
	$pcm_time_valid( array( 'project_id' => 13, 'hours' => 12 ) ), 'pcm_crm_pm_too_many_hours' );
check( 'and so is logging ahead of the day',
	$pcm_time_valid( array( 'project_id' => 13, 'entry_date' => date( 'Y-m-d', time() + 3 * DAY_IN_SECONDS ) ) ), 'pcm_crm_pm_future_time' );
delete_option( PCM_CRM_PM_TIME_OPTION );

$GLOBALS['wpdb'] = $pcm_real_wpdb;
delete_option( PCM_CRM_PM_TYPES_OPTION );

echo "\n--- project record form ---\n";

pcm_test_add_filter( 'pcm_crm_layout', 'pcm_crm_pm_layout' );

$pcm_project_layout = pcm_crm_layout( 'projects' );
$pcm_sections = wp_list_pluck( $pcm_project_layout, 'title' );

check( 'a project has a real form rather than one undifferentiated section',
	$pcm_sections, array( '', 'Health', 'Timeline', 'Budget', 'Retainer', 'Notes' ) );

$pcm_placed = array();

foreach ( $pcm_project_layout as $pcm_section ) {
	$pcm_placed = array_merge( $pcm_placed, $pcm_section['fields'] );
}

check( 'the name leads it', $pcm_placed[0], 'name' );
check( 'the opportunity it came from is on it',
	in_array( 'opportunity_id', $pcm_placed, true ), true );
// Maintained from the stage struct, so offering them invites a value that the
// before-insert filter will overwrite anyway.
check( 'but the stage stamps are not',
	in_array( 'stage_entered_date', $pcm_placed, true ), false );

// An arrangement someone made on the Fields & Layouts tab has to win, or the
// tab silently does nothing for these objects.
update_option( PCM_CRM_LAYOUTS_OPTION, array( 'projects' => array(
	array( 'title' => 'Mine', 'fields' => array( 'name' ) ),
) ) );
check( 'a saved arrangement wins over the shipped one',
	wp_list_pluck( pcm_crm_layout( 'projects' ), 'title' ), array( 'Mine' ) );
delete_option( PCM_CRM_LAYOUTS_OPTION );

pcm_test_reset_filters( 'pcm_crm_layout' );

echo "\n--- opportunity to project ---\n";

$pcm_type_map = pcm_crm_pm_opportunity_type_map();

check( 'a renewal becomes a support retainer', $pcm_type_map['Renewal'], 'salesforce-support-retainer' );
check( 'new business becomes a build', $pcm_type_map['New Business'], 'custom-development' );
// Absent rather than defaulted: the type decides which stages are legal, so a
// wrong guess offers the wrong lifecycle and nothing says so until a stage
// refuses to save.
check( 'an unmapped type is absent rather than guessed',
	isset( $pcm_type_map['Something Else'] ), false );

foreach ( $pcm_type_map as $pcm_from => $pcm_to ) {
	check( "'{$pcm_from}' maps to a type that exists",
		in_array( $pcm_to, pcm_crm_pm_project_types(), true ), true );
}

echo "\n--- sample data reaches the module ---\n";

pcm_test_add_filter( 'pcm_crm_sample_tables', 'pcm_crm_pm_sample_tables' );

$pcm_sample_tables = pcm_crm_sample_tables();

check( 'the sweep covers core and the module', count( $pcm_sample_tables ), 13 );
check( 'including projects', isset( $pcm_sample_tables['projects'] ), true );
// Seeded rows have to stay removable after the module is switched off, which is
// why this filter is registered from the always-loaded schema file.
check( 'and the bookkeeping tables, so nothing is stranded',
	isset( $pcm_sample_tables['retainer_periods'] ) && isset( $pcm_sample_tables['allocations'] ), true );

pcm_test_reset_filters( 'pcm_crm_sample_tables' );

echo "\n--- display names the browser reads ---\n";

// A column reading a name nothing produces is a blank column, and it never
// errors — which is how All Projects shipped with an empty Account column: core
// resolves an account name only for contacts and opportunities. So every
// underscore field pm.js reads is taken out of pm.js itself and asserted to
// arrive on a decorated row.
$pcm_pm_js = file_get_contents( dirname( __DIR__ ) . '/assets/pm.js' );

// What core's expand() already puts on every row with those columns.
$pcm_core_made = array( '_owner_name', '_created_by_name', '_modified_by_name' );

$pcm_js_to_php = array(
	'projects'      => 'project',
	'project_tasks' => 'project_task',
	'project_raid'  => 'project_raid',
	'project_roles' => 'project_role',
	'time_entries'  => 'time_entry',
);

$pcm_maps = array(
	'accounts'      => array( 5 => array( 'id' => 5, 'name' => 'Acme' ), 8 => array( 'id' => 8, 'name' => 'Partner Co' ) ),
	'opportunities' => array( 9 => array( 'id' => 9, 'name' => 'Acme — AI pilot' ) ),
	'projects'      => array( 12 => array( 'id' => 12, 'name' => 'Acme retainer' ) ),
	'contacts'      => array( 7 => array( 'id' => 7, 'first_name' => 'Sam', 'last_name' => 'Lee', 'email' => '', 'account_id' => 5 ) ),
);

$pcm_users = function ( $pcm_id ) { return 4 === $pcm_id ? 'Dana Reyes' : ''; };

// One row carrying every parent column any PM object has, so whatever a block
// reads has something to resolve from.
$pcm_full_row = array(
	'id' => 1, 'account_id' => 5, 'opportunity_id' => 9, 'project_id' => 12, 'user_id' => 4,
	'assignee_user_id' => 4, 'owner_contact_id' => 7, 'contact_id' => 7, 'partner_account_id' => 0,
	'party_type' => 'client',
);

foreach ( $pcm_js_to_php as $pcm_slug => $pcm_php_object ) {
	$pcm_start = strpos( $pcm_pm_js, "registerObject('{$pcm_slug}'" );
	$pcm_end   = strpos( $pcm_pm_js, 'app.register', $pcm_start + 10 );
	$pcm_block = substr( $pcm_pm_js, $pcm_start, false === $pcm_end ? null : $pcm_end - $pcm_start );

	preg_match_all( '/(?:row\._|key: \'_)([a-z_]+)/', $pcm_block, $pcm_reads );

	$pcm_wanted = array_diff( array_unique( array_map( function ( $pcm_k ) { return '_' . $pcm_k; }, $pcm_reads[1] ) ), $pcm_core_made );

	$pcm_decorated = pcm_crm_pm_decorate( $pcm_php_object, array( $pcm_full_row ), $pcm_maps, $pcm_users );

	foreach ( $pcm_wanted as $pcm_field ) {
		check( "{$pcm_slug}: pm.js reads {$pcm_field}, and the server provides it",
			array_key_exists( $pcm_field, $pcm_decorated[0] ), true );
	}
}

$pcm_named = function ( array $pcm_row ) use ( $pcm_maps, $pcm_users ) {
	$pcm_out = pcm_crm_pm_decorate( 'project_role', array( array_merge( array(
		'user_id' => 0, 'contact_id' => 0, 'partner_account_id' => 0, 'project_id' => 12,
	), $pcm_row ) ), $pcm_maps, $pcm_users );

	return array( $pcm_out[0]['_person_name'], $pcm_out[0]['_party_label'], $pcm_out[0]['_org_name'] );
};

check( 'an account name actually resolves on a project, not just the key',
	pcm_crm_pm_decorate( 'project', array( array( 'account_id' => 5 ) ), $pcm_maps, $pcm_users )[0]['_account_name'],
	'Acme' );

// Who a role names depends on the side they are on — the reason party_type exists.
check( 'an internal role names the team member', $pcm_named( array( 'party_type' => 'internal', 'user_id' => 4 ) )[0], 'Dana Reyes' );
check( 'a client role names the contact, at their own organisation',
	$pcm_named( array( 'party_type' => 'client', 'contact_id' => 7 ) ), array( 'Sam Lee', 'Client', 'Acme' ) );
// A firm can be engaged before anyone there is named, and the row still has to
// read as someone rather than a dash.
check( 'a partner firm with no named person reads as the firm',
	$pcm_named( array( 'party_type' => 'partner', 'partner_account_id' => 8 ) ), array( 'Partner Co', 'Partner', 'Partner Co' ) );
check( 'a missing parent comes out empty rather than as a notice',
	pcm_crm_pm_decorate( 'time_entry', array( array( 'project_id' => 999, 'user_id' => 0 ) ), $pcm_maps, $pcm_users )[0]['_project_name'],
	'' );

delete_option( PCM_CRM_MODULES_OPTION );

echo "\n--- setup registry ---\n";
$pcm_nav = pcm_crm_setup_nav();
check( 'every core group has pages', array_values( array_diff( array_keys( $pcm_nav ), array( 'projects' ) ) ), array( 'crm', 'automation', 'data', 'platform' ) );
// The suite has loaded the module's files by now, as the bootstrap does when it
// is switched on — and a module registers its own group.
check( 'the Projects module brings its own Setup pages', array_keys( $pcm_nav['projects'] ), array( 'project-types', 'time-entry', 'opportunity-mapping', 'project-picklists' ) );

echo "\n--- project type settings ---\n";

$pcm_clean = pcm_crm_pm_clean_type( array(
	'label'     => ' Studio Time ',
	'archetype' => 'tm',
	'active'    => '1',
	'stages'    => array(
		array( 'name' => 'Booked', 'is_active' => '1' ),
		array( 'name' => 'booked' ),
		array( 'name' => '' ),
		array( 'name' => 'Billed', 'is_closed' => '1' ),
	),
	'time'      => array( 'rate_required' => '1', 'billable_default' => '1', 'description_required' => '1' ),
	'defaults'  => array( 'default_bill_rate' => '140', 'default_cost_rate' => '' ),
), '' );
check( 'a posted type is trimmed and kept', array( $pcm_clean['label'], $pcm_clean['archetype'], $pcm_clean['active'] ), array( 'Studio Time', 'tm', 1 ) );
check( 'blank and repeated stages are dropped, and the rest numbered in order',
	array( wp_list_pluck( $pcm_clean['stages'], 'name' ), wp_list_pluck( $pcm_clean['stages'], 'order' ) ), array( array( 'Booked', 'Billed' ), array( 10, 20 ) ) );
check( 'only rules that differ from the process are stored', $pcm_clean['time'], array( 'description_required' => 1 ) );
check( 'and only defaults that were given', $pcm_clean['defaults'], array( 'default_bill_rate' => 140.0 ) );
check( 'a type needs a name',
	pcm_crm_pm_clean_type( array( 'label' => '', 'archetype' => 'tm' ), '' )->get_error_code(), 'pcm_crm_pm_type_label' );
check( 'and a process that exists',
	pcm_crm_pm_clean_type( array( 'label' => 'X', 'archetype' => 'barter' ), '' )->get_error_code(), 'pcm_crm_pm_type_archetype' );
check( 'stages that never close are refused',
	pcm_crm_pm_clean_type( array( 'label' => 'X', 'archetype' => 'tm', 'stages' => array( array( 'name' => 'Forever' ) ) ), '' )->get_error_code(), 'pcm_crm_pm_type_closing' );
check( 'the mapping keeps types and drops Ask',
	pcm_crm_pm_sanitize_mapping( array( 'Renewal' => 'Custom Development', 'New Business' => '' ) ), array( 'Renewal' => 'custom-development' ) );
check( 'the time settings clamp the ceiling to a day',
	pcm_crm_pm_sanitize_time_settings( array( 'max_hours' => 40, 'increment' => '0.25' ) )['max_hours'], 24.0 );
check( 'an emptied picklist falls back rather than saving nothing',
	pcm_crm_pm_sanitize_picklists( array( 'roles' => "  \n", 'raid_types' => "Risk\nIssue\nRisk" ) ), array( 'raid_types' => array( 'Risk', 'Issue' ) ) );

ob_start();
pcm_crm_pm_render_types_page();
$pcm_html = ob_get_clean();
check( 'the types page lists every type', substr_count( $pcm_html, 'type=' ) >= 4, true );

$_GET['type'] = 'custom-development';
ob_start();
pcm_crm_pm_render_types_page();
$pcm_html = ob_get_clean();
unset( $_GET['type'] );
check( 'a type opens to its stage editor', false !== strpos( $pcm_html, 'stages[0][name]' ), true );
check( 'with a row for each stage', substr_count( $pcm_html, '[is_closed]' ), 9 + 1 );
check( 'an old tab link still names its page', isset( pcm_crm_settings_tabs()['fields'] ), true );
check( 'the app-backed pages are not tabs', isset( pcm_crm_settings_tabs()['templates'] ), false );
check( 'a tab page links through the Setup screen', pcm_crm_setup_url( 'fields' ), 'https://example.com/wp-admin/admin.php?page=pcm-crm-settings&tab=fields' );
// Emails and drill-downs already link to this slug.
check( 'an app-backed page keeps its own slug', pcm_crm_setup_url( 'recycle' ), 'https://example.com/wp-admin/admin.php?page=pcm-crm-recycle-bin' );
check( 'the bin is recognised as Setup under a renamed parent', pcm_crm_is_setup_screen( 'setup_page_pcm-crm-recycle-bin' ), true );
check( 'a record screen is not Setup', pcm_crm_is_setup_screen( 'crm_page_pcm-crm-contacts' ), false );
$_GET['tab'] = 'nonsense';
check( 'an unknown tab falls back to Home', pcm_crm_current_setup_key(), 'home' );
$_GET['tab'] = 'theme';
check( 'a known tab is the current page', pcm_crm_current_setup_key(), 'theme' );
check( 'an app-backed slug resolves to its page', pcm_crm_current_setup_key( 'pcm-crm-schedules' ), 'schedules' );
unset( $_GET['tab'] );

ob_start();
pcm_crm_render_settings();
$pcm_html = ob_get_clean();
check( 'Home renders the frame', false !== strpos( $pcm_html, 'class="wrap pcm-crm pcm-setup"' ), true );
check( 'Home says the Projects module is off', false !== strpos( $pcm_html, 'This module is switched off.' ), true );
check( 'Home closes every div it opens', substr_count( $pcm_html, '<div' ), substr_count( $pcm_html, '</div>' ) );

ob_start();
pcm_crm_screen( 'templates', 'Email Templates', '', array( 'setup' => 'templates' ) );
$pcm_html = ob_get_clean();
check( 'an app screen inside Setup still mounts the app', false !== strpos( $pcm_html, 'data-view="templates"' ), true );
check( 'and draws no app bar', false !== strpos( $pcm_html, 'pcm-crm-appbar' ), false );
check( 'and closes every div', substr_count( $pcm_html, '<div' ), substr_count( $pcm_html, '</div>' ) );

ob_start();
pcm_crm_screen( 'contacts', 'Contacts' );
$pcm_html = ob_get_clean();
check( 'a work screen carries the app bar', false !== strpos( $pcm_html, 'class="pcm-crm-appbar"' ), true );
check( 'with its own screen marked current', (bool) preg_match( '/page=pcm-crm-contacts"\s+class="is-active"/', $pcm_html ), true );

echo "\n" . ( $fail ? "$fail FAILED\n" : "All checks passed\n" );
exit( $fail ? 1 : 0 );
