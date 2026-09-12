<?php
/**
 * Sample data: generating it, counting it, and removing it again.
 *
 * Every row it creates carries is_test = 1. That flag is what makes the whole
 * thing safe to offer from a settings screen rather than only from the command
 * line: removal is "delete the rows that say they are samples", not a list of
 * ids kept in an option that can be lost, and a real record typed in alongside
 * the sample set is never in scope however closely it resembles one.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class PCM_CRM_Sample_Data {

	/** Ids collected as records are created, for wiring them together. */
	private $created = array();

	/**
	 * Build the sample set.
	 */
	public function create() {
		// Fixed seed: the same call twice gives the same data, so a bug found
		// while filtering can be reproduced rather than re-hunted.
		mt_srand( 20260908 );

		$owners = $this->owners();

		$accounts      = $this->seed_accounts( $owners );
		$contacts      = $this->seed_contacts( $accounts, $owners );
		$opportunities = $this->seed_opportunities( $accounts, $contacts, $owners );
		$activities    = $this->seed_activities( $accounts, $contacts, $opportunities, $owners );

		return array(
			'accounts'      => count( $accounts ),
			'contacts'      => count( $contacts ),
			'opportunities' => count( $opportunities ),
			'activities'    => count( $activities ),
		);
	}

	/* -------------------------------------------------------------------
	   Guards and helpers
	   ------------------------------------------------------------------- */

	private function remember( $object, $id ) {
		if ( $id ) {
			$this->created[ $object ][] = (int) $id;
		}

		return $id;
	}

	/**
	 * The users records are spread across, so the owner filter has something
	 * to do. Falls back to whoever exists if the site has only one user.
	 */
	private function owners() {
		$users = get_users( array( 'role__in' => array( 'administrator', 'editor' ), 'fields' => 'ID' ) );

		if ( ! $users ) {
			$users = get_users( array( 'number' => 5, 'fields' => 'ID' ) );
		}

		return $users ? array_map( 'absint', $users ) : array( 0 );
	}

	private function pick( array $list ) {
		return $list[ mt_rand( 0, count( $list ) - 1 ) ];
	}

	/**
	 * A date N days from now, as MySQL datetime.
	 *
	 * Everything is relative to today so the dashboard's rolling twelve-month
	 * window is populated whenever the command is run, rather than only in the
	 * month it was written.
	 */
	private function days( $offset, $format = 'Y-m-d H:i:s' ) {
		return gmdate( $format, strtotime( $offset . ' days', current_time( 'timestamp' ) ) );
	}

	/**
	 * Backdate a record's audit stamps.
	 *
	 * insert() stamps created_date with now, which is correct for real records
	 * and useless for a trend chart — every sample row would land in the
	 * current month. Written straight to the table because those columns are
	 * readonly to the model, which is the behaviour we want everywhere else.
	 */
	private function backdate( $table, $id, $created ) {
		global $wpdb;

		// is_test rides along with the backdating rather than going through the
		// model: it is set on every row this class writes without exception,
		// and one place doing it is one place to get it wrong.
		$wpdb->update(
			$table,
			array( 'created_date' => $created, 'last_modified_date' => $created, 'is_test' => 1 ),
			array( 'id' => $id ),
			array( '%s', '%s', '%d' ),
			array( '%d' )
		);
	}

	/* -------------------------------------------------------------------
	   The data
	   ------------------------------------------------------------------- */

	private function seed_accounts( $owners ) {
		$names = array(
			array( 'Green Mountain Literacy Council', 'Nonprofit', 'Nonprofit' ),
			array( 'Champlain Valley Food Bank', 'Customer', 'Nonprofit' ),
			array( 'Burlington Youth Orchestra', 'Nonprofit', 'Nonprofit' ),
			array( 'Riverside Community Health', 'Customer', 'Healthcare' ),
			array( 'Northfield Historical Society', 'Prospect', 'Nonprofit' ),
			array( 'Vermont Trails Alliance', 'Customer', 'Nonprofit' ),
			array( 'Lakeside Montessori School', 'Customer', 'Education' ),
			array( 'Granite State Legal Aid', 'Prospect', 'Nonprofit' ),
			array( 'Maple Ridge Veterinary', 'Customer', 'Healthcare' ),
			array( 'Otter Creek Engineering', 'Customer', 'Professional Services' ),
			array( 'Stonewall Architects', 'Prospect', 'Professional Services' ),
			array( 'Bright Harbor Consulting', 'Partner', 'Professional Services' ),
			array( 'Cedar & Sons Woodworking', 'Customer', 'Manufacturing' ),
			array( 'Winooski Bicycle Works', 'Prospect', 'Retail' ),
			array( 'Half Moon Coffee Roasters', 'Customer', 'Retail' ),
			array( 'Silver Birch Credit Union', 'Prospect', 'Finance' ),
			array( 'Ashfield Insurance Group', 'Customer', 'Finance' ),
			array( 'Meridian Data Partners', 'Partner', 'Technology' ),
			array( 'Bluebird Software Collective', 'Prospect', 'Technology' ),
			array( 'Kestrel Analytics', 'Customer', 'Technology' ),
			array( 'Town of Fair Haven', 'Customer', 'Government' ),
			array( 'Rutland Regional Planning', 'Prospect', 'Government' ),
			array( 'Sugarbush Adult Education', 'Customer', 'Education' ),
			array( 'Quarry Hill Preparatory', 'Prospect', 'Education' ),
			array( 'Second Chance Animal Rescue', 'Nonprofit', 'Nonprofit' ),
			array( 'Harborview Senior Living', 'Customer', 'Healthcare' ),
			array( 'Pinnacle Manufacturing Co', 'Former Customer', 'Manufacturing' ),
			array( 'Deerfield Print & Design', 'Former Customer', 'Professional Services' ),
		);

		$cities = array(
			array( 'Burlington', 'VT', '05401' ), array( 'Montpelier', 'VT', '05602' ),
			array( 'Rutland', 'VT', '05701' ), array( 'Brattleboro', 'VT', '05301' ),
			array( 'Concord', 'NH', '03301' ), array( 'Portsmouth', 'NH', '03801' ),
			array( 'Albany', 'NY', '12207' ), array( 'Northampton', 'MA', '01060' ),
		);

		$accounts = array();

		foreach ( $names as $i => $row ) {
			$city = $this->pick( $cities );
			$slug = strtolower( preg_replace( '/[^a-z0-9]+/i', '', substr( $row[0], 0, 14 ) ) );

			$id = pcm_crm_accounts()->insert( array(
				'name'                => $row[0],
				'type'                => $row[1],
				'industry'            => $row[2],
				'website'             => 'https://' . $slug . '.example.org',
				'phone'               => sprintf( '(802) %03d-%04d', mt_rand( 200, 899 ), mt_rand( 1000, 9999 ) ),
				'billing_street'      => mt_rand( 12, 890 ) . ' ' . $this->pick( array( 'Main St', 'Elm St', 'Pine Ridge Rd', 'Church St', 'Mill Lane' ) ),
				'billing_city'        => $city[0],
				'billing_state'       => $city[1],
				'billing_postal_code' => $city[2],
				'billing_country'     => 'USA',
				'annual_revenue'      => mt_rand( 3, 180 ) * 100000,
				'number_of_employees' => mt_rand( 4, 240 ),
				'description'         => 'Sample record for testing filters and reports.',
				'owner_id'            => $this->pick( $owners ),
			) );

			if ( is_wp_error( $id ) ) {
				continue;
				continue;
			}

			// Spread creation over roughly two years so the account list has a
			// real history to sort and filter by.
			$this->backdate( PCM_CRM_Schema::accounts(), $id, $this->days( -mt_rand( 20, 700 ) ) );
			$this->remember( 'accounts', $id );

			$accounts[] = (int) $id;
		}

		return $accounts;
	}

	private function seed_contacts( $accounts, $owners ) {
		$first = array(
			'Ada', 'Marcus', 'Priya', 'Devon', 'Ingrid', 'Tomas', 'Renee', 'Oscar', 'Lila', 'Farid',
			'Bethany', 'Cormac', 'Yuki', 'Nadia', 'Peter', 'Simone', 'Hector', 'Joanne', 'Emeka', 'Cara',
			'Wesley', 'Antonia', 'Rashid', 'Maeve', 'Julian', 'Delia', 'Soren', 'Rosalind', 'Amos', 'Kiran',
		);
		$last = array(
			'Whitfield', 'Okafor', 'Brennan', 'Castillo', 'Lindqvist', 'Moreau', 'Adeyemi', 'Falk', 'Nakamura',
			'Rivera', 'Donnelly', 'Haddad', 'Petrov', 'Guzman', 'Sinclair', 'Achebe', 'Barlow', 'Ferreira',
			'Kowalski', 'Mbeki', 'Thornton', 'Vasquez', 'Larsen', 'Ibrahim', 'Croft',
		);
		$titles = array(
			'Executive Director', 'Development Director', 'Operations Manager', 'IT Coordinator',
			'Program Manager', 'Chief of Staff', 'Database Administrator', 'Finance Director',
			'Marketing Lead', 'Volunteer Coordinator', 'Board Chair', 'Office Manager',
		);
		// Weighted rather than uniform: Contact Form is what the site's own
		// intake stamps, so it should dominate the way it does in reality.
		$sources = array_merge(
			array_fill( 0, 5, PCM_CRM_FORM_SOURCE ),
			array_fill( 0, 3, 'Referral' ),
			array( 'Web', 'Partner', 'Event', 'Outbound', 'Other' )
		);

		$contacts = array();
		$used     = array();

		// Two or three people per account, so an Account's related list has
		// something in it and the account filter visibly narrows the view.
		foreach ( $accounts as $account_id ) {
			$count = mt_rand( 1, 4 );

			for ( $n = 0; $n < $count; $n++ ) {
				$fn = $this->pick( $first );
				$ln = $this->pick( $last );

				$email = strtolower( $fn . '.' . $ln ) . '@example.org';

				// Emails are the CRM's match key, so a duplicate here would
				// quietly test the upsert instead of creating a new person.
				if ( isset( $used[ $email ] ) ) {
					$email = strtolower( $fn . '.' . $ln ) . count( $used ) . '@example.org';
				}
				$used[ $email ] = true;

				// A flagged contact must carry a reason or the model refuses
				// the write — the same rule the UI enforces.
				$dnc = mt_rand( 1, 12 ) === 1;

				$id = pcm_crm_contacts()->insert( array(
					'account_id'  => $account_id,
					'first_name'  => $fn,
					'last_name'   => $ln,
					'title'       => $this->pick( $titles ),
					'email'       => $email,
					'phone'       => sprintf( '(802) %03d-%04d', mt_rand( 200, 899 ), mt_rand( 1000, 9999 ) ),
					'mobile_phone' => mt_rand( 0, 1 ) ? sprintf( '(802) %03d-%04d', mt_rand( 200, 899 ), mt_rand( 1000, 9999 ) ) : '',
					'lead_source' => $this->pick( $sources ),
					'service_interest' => $this->pick( pcm_crm_interest_labels() ),
					'do_not_contact' => $dnc ? 1 : 0,
					'do_not_contact_reason' => $dnc ? $this->pick( array(
						'Asked to be removed from all mailings.',
						'Left the organization — bounced twice.',
						'Requested contact through their director only.',
						'Unsubscribed after the 2025 newsletter.',
						'Legal hold — route through counsel.',
					) ) : '',
					'owner_id'    => $this->pick( $owners ),
				) );

				if ( is_wp_error( $id ) ) {
					
					continue;
				}

				$this->backdate( PCM_CRM_Schema::contacts(), $id, $this->days( -mt_rand( 5, 690 ) ) );
				$this->remember( 'contacts', $id );

				$contacts[] = array( 'id' => (int) $id, 'account_id' => $account_id );
			}
		}

		return $contacts;
	}

	private function seed_opportunities( $accounts, $contacts, $owners ) {
		$stages = pcm_crm_stages();
		$types  = pcm_crm_opportunity_types();
		$srcs   = pcm_crm_lead_sources();

		$work = array(
			'Salesforce Managed Support', 'NPSP Implementation', 'AI Enablement Pilot',
			'Data Migration', 'Reporting & Dashboards', 'Custom Portal Build',
			'Workflow Automation', 'Integration Project', 'Admin Retainer',
			'Volunteer Portal', 'Grants Module Rollout', 'Website & CRM Integration',
		);

		$opportunities = array();

		// Weighted so the funnel tapers and the win rate is a real number:
		// plenty of early-stage work, fewer late, and a closed history behind
		// it. A uniform spread would make every chart look identical.
		$distribution = array_merge(
			array_fill( 0, 16, 'Qualification' ),
			array_fill( 0, 14, 'Discovery' ),
			array_fill( 0, 10, 'Proposal' ),
			array_fill( 0, 6, 'Negotiation' ),
			array_fill( 0, 24, 'Closed Won' ),
			array_fill( 0, 13, 'Closed Lost' )
		);

		foreach ( $distribution as $stage_name ) {
			$account_id = $this->pick( $accounts );

			// Prefer a contact at the same account, so the primary contact on
			// a deal is someone who actually works there.
			$candidates = array_values( array_filter( $contacts, function ( $c ) use ( $account_id ) {
				return $c['account_id'] === $account_id;
			} ) );

			$contact = $candidates ? $this->pick( $candidates ) : null;

			$stage    = pcm_crm_stage( $stage_name );
			$is_closed = ! empty( $stage['is_closed'] );

			$account = pcm_crm_accounts()->get( $account_id );
			$name    = ( $account ? $account['name'] : 'Account' ) . ' — ' . $this->pick( $work );

			// Closed deals sit in the past and open ones in the future, which
			// is what makes the close-date filter and the month chart mean
			// anything.
			$close_offset = $is_closed ? -mt_rand( 5, 360 ) : mt_rand( 3, 180 );

			// A losing stage needs its reason or the model refuses the write —
			// the same rule the board and the form enforce.
			$lost_reason = pcm_crm_stage_is_lost( $stage_name ) ? $this->pick( array(
				'Went with an in-house hire.',
				'Budget pulled for the fiscal year.',
				'Chose a lower-cost implementation partner.',
				'Project postponed indefinitely.',
				'No response after the proposal.',
			) ) : '';

			$id = pcm_crm_opportunities()->insert( array(
				'account_id'         => $account_id,
				'primary_contact_id' => $contact ? $contact['id'] : 0,
				'name'               => $name,
				'stage_name'         => $stage_name,
				'closed_lost_reason' => $lost_reason,
				'service_interest'   => $this->pick( pcm_crm_interest_labels() ),
				'amount'             => mt_rand( 3, 90 ) * 500,
				'close_date'         => $this->days( $close_offset, 'Y-m-d' ),
				'type'               => $this->pick( $types ),
				'lead_source'        => $this->pick( $srcs ),
				'next_step'          => $is_closed ? '' : $this->pick( array(
					'Send revised scope', 'Book discovery call', 'Follow up on proposal',
					'Confirm budget cycle', 'Introduce to their IT lead', 'Await board approval',
				) ),
				'description'        => 'Sample record for testing filters and reports.',
				'owner_id'           => $this->pick( $owners ),
			) );

			if ( is_wp_error( $id ) ) {
				
				continue;
			}

			// Created before it closed, and always inside the last two years.
			$created_offset = $close_offset - mt_rand( 20, 150 );
			$this->backdate( PCM_CRM_Schema::opportunities(), $id, $this->days( $created_offset ) );
			$this->remember( 'opportunities', $id );

			$this->seed_stage_history( $id, $stage_name, $created_offset, $close_offset );

			$opportunities[] = array( 'id' => (int) $id, 'account_id' => $account_id, 'contact_id' => $contact ? $contact['id'] : 0 );
		}

		return $opportunities;
	}

	/**
	 * Walk a deal through the stages it must have passed on the way.
	 *
	 * The recorder that runs on a real save writes one row at the moment of
	 * the change, which for seeded data would put every stage change at the
	 * instant the command ran — every deal a day old, every average zero. So
	 * the rows are written directly, spread across the deal's real lifetime.
	 *
	 * Roughly a quarter of open deals are left sitting well past the stall
	 * threshold, because a board where nothing is ever stuck teaches nothing
	 * about a feature for spotting stuck deals.
	 */
	private function seed_stage_history( $opportunity_id, $stage_name, $created_offset, $close_offset ) {
		global $wpdb;

		$stages = pcm_crm_stages();
		$path   = array();

		// Every stage up to and including the one it reached: a deal in
		// Negotiation went through Qualification, Discovery and Proposal, and
		// conversion rates are meaningless without that trail.
		foreach ( $stages as $stage ) {
			if ( ! empty( $stage['is_closed'] ) && $stage['name'] !== $stage_name ) {
				continue;
			}

			$path[] = $stage['name'];

			if ( $stage['name'] === $stage_name ) {
				break;
			}
		}

		$is_closed = pcm_crm_stage( $stage_name ) && ! empty( pcm_crm_stage( $stage_name )['is_closed'] );
		$ends_at   = $is_closed ? $close_offset : 0;
		$span      = max( 1, $ends_at - $created_offset );

		$cursor = $created_offset;
		$count  = count( $path );

		// A quarter of open deals stall in their final stage.
		$stalled = ! $is_closed && mt_rand( 1, 4 ) === 1;

		foreach ( $path as $index => $stage ) {
			$last = ( $index === $count - 1 );

			if ( $last ) {
				$entered = $stalled ? -mt_rand( 45, 120 ) : $cursor;
				$exited  = null;

				if ( $is_closed ) {
					$entered = $ends_at;
				}
			} else {
				$entered = $cursor;
				// Spread the earlier stages across the time before the last
				// one, so no deal moves through four stages in an afternoon.
				$cursor  = min( $ends_at, $cursor + max( 1, (int) round( $span / $count ) ) + mt_rand( -3, 8 ) );
				$exited  = $cursor;
			}

			$definition = pcm_crm_stage( $stage );

			$wpdb->insert(
				PCM_CRM_Schema::history(),
				array(
					'opportunity_id' => $opportunity_id,
					'stage_name'     => $stage,
					'previous_stage' => $index ? $path[ $index - 1 ] : '',
					'entered_date'   => $this->days( $entered ),
					'exited_date'    => null === $exited ? null : $this->days( $exited ),
					'days_in_stage'  => null === $exited ? null : max( 0, $exited - $entered ),
					'is_closed'      => $definition ? (int) $definition['is_closed'] : 0,
					'is_won'         => $definition ? (int) $definition['is_won'] : 0,
					'created_date'   => $this->days( $entered ),
					'is_test'        => 1,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d' )
			);

			if ( $last ) {
				$wpdb->update(
					PCM_CRM_Schema::opportunities(),
					array(
						'stage_entered_date' => $this->days( $entered ),
						'closed_date'        => $is_closed ? $this->days( $ends_at ) : '0000-00-00 00:00:00',
					),
					array( 'id' => $opportunity_id ),
					array( '%s', '%s' ),
					array( '%d' )
				);
			}
		}
	}

	private function seed_activities( $accounts, $contacts, $opportunities, $owners ) {
		$subjects = array(
			'Call'     => array( 'Intro call', 'Check-in call', 'Scoping call', 'Follow-up call', 'Renewal conversation' ),
			'Email'    => array( 'Sent proposal', 'Shared pricing sheet', 'Answered admin question', 'Sent meeting notes', 'Scheduling emails' ),
			'Meeting'  => array( 'Discovery workshop', 'Requirements review', 'Quarterly review', 'Board presentation', 'Training session' ),
			'Task'     => array( 'Draft scope of work', 'Prepare dashboard mockups', 'Audit their org', 'Write migration plan', 'Review sandbox config' ),
			'Note'     => array( 'Budget cycle ends in June', 'Prefers async updates', 'Two admins on staff', 'Currently on Classic', 'Grant-funded project' ),
			'Web Form' => array( 'Web inquiry — Salesforce Managed Support', 'Web inquiry — AI Enablement', 'Web inquiry — Custom Development' ),
		);

		$activities = array();

		// Hung off opportunities, so a deal's timeline tells its story.
		foreach ( $opportunities as $opp ) {
			$count = mt_rand( 1, 4 );

			for ( $n = 0; $n < $count; $n++ ) {
				$type = $this->pick( array( 'Call', 'Email', 'Meeting', 'Task', 'Note' ) );
				$id   = $this->activity( $type, $subjects, $opp['contact_id'], $opp['id'], 'opportunity', $owners );

				if ( $id ) { $activities[] = $id; }
			}
		}

		// And off contacts directly, including the web inquiries that would
		// have come through the contact form.
		foreach ( $contacts as $contact ) {
			if ( mt_rand( 1, 3 ) === 1 ) {
				continue;
			}

			$type = mt_rand( 1, 4 ) === 1 ? 'Web Form' : $this->pick( array( 'Call', 'Email', 'Meeting', 'Task' ) );
			$id   = $this->activity( $type, $subjects, $contact['id'], $contact['account_id'], 'account', $owners );

			if ( $id ) { $activities[] = $id; }
		}

		return $activities;
	}

	private function activity( $type, $subjects, $who_id, $what_id, $what_type, $owners ) {
		$statuses = pcm_crm_activity_statuses();

		// Roughly a fifth stay open, and some of those are already past due —
		// otherwise the overdue tile reads zero and there is nothing to test.
		$open      = mt_rand( 1, 5 ) === 1;
		$status    = $open ? $this->pick( array( 'Not Started', 'In Progress', 'Waiting' ) ) : 'Completed';
		$logged    = -mt_rand( 1, 400 );
		$due_offset = $open ? mt_rand( -30, 45 ) : $logged;

		$id = pcm_crm_activities()->insert( array(
			'subject'       => $this->pick( $subjects[ $type ] ),
			'activity_type' => $type,
			'status'        => $status,
			'priority'      => $this->pick( pcm_crm_priorities() ),
			'activity_date' => $this->days( $logged ),
			'due_date'      => $this->days( $due_offset, 'Y-m-d' ),
			'who_id'        => $who_id,
			'what_id'       => $what_id,
			'what_type'     => $what_type,
			'description'   => 'Sample record for testing filters and reports.',
			'owner_id'      => $this->pick( $owners ),
		) );

		if ( is_wp_error( $id ) ) {
			return 0;
		}

		$this->backdate( PCM_CRM_Schema::activities(), $id, $this->days( $logged ) );
		$this->remember( 'activities', $id );

		return (int) $id;
	}
}

/* ---------------------------------------------------------------------------
   Entry points
   --------------------------------------------------------------------------- */

/**
 * Is this an environment where fake data is acceptable?
 *
 * Deny by default: an unrecognised host is treated as production, because the
 * failure mode of guessing wrong is hundreds of fake records in a real CRM.
 * They are flagged and removable now, which makes the mistake recoverable —
 * not one worth making.
 */
function pcm_crm_seed_allowed() {
	if ( defined( 'PCM_CRM_ALLOW_SEED' ) && PCM_CRM_ALLOW_SEED ) {
		return true;
	}

	$pcm_host = wp_parse_url( home_url(), PHP_URL_HOST );

	if ( ! $pcm_host ) {
		return false;
	}

	foreach ( array( 'staging', 'localhost', '.local', '.test', 'dev.' ) as $pcm_marker ) {
		if ( false !== strpos( $pcm_host, $pcm_marker ) ) {
			return true;
		}
	}

	return false;
}

/**
 * The tables sample data can live in.
 *
 * History is included because its rows hang off sample opportunities; leaving
 * them behind after a delete would skew every stage average with deals that no
 * longer exist.
 */
function pcm_crm_sample_tables() {
	return array(
		'accounts'      => PCM_CRM_Schema::accounts(),
		'contacts'      => PCM_CRM_Schema::contacts(),
		'opportunities' => PCM_CRM_Schema::opportunities(),
		'activities'    => PCM_CRM_Schema::activities(),
		'history'       => PCM_CRM_Schema::history(),
	);
}

/**
 * How much sample data exists, and how much real data sits beside it.
 */
function pcm_crm_sample_data_counts() {
	global $wpdb;

	$pcm_counts = array();

	foreach ( pcm_crm_sample_tables() as $pcm_key => $pcm_table ) {
		// phpcs:ignore WordPress.DB.PreparedSQL -- table names are internal
		$pcm_counts[ $pcm_key ] = array(
			'test' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pcm_table} WHERE is_test = 1" ),
			'real' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pcm_table} WHERE is_test = 0" ),
		);
	}

	return $pcm_counts;
}

function pcm_crm_has_sample_data() {
	foreach ( pcm_crm_sample_data_counts() as $pcm_count ) {
		if ( $pcm_count['test'] > 0 ) {
			return true;
		}
	}

	return false;
}

function pcm_crm_create_sample_data() {
	$pcm_seeder = new PCM_CRM_Sample_Data();

	return $pcm_seeder->create();
}

/**
 * Remove every row that says it is a sample.
 *
 * A hard delete rather than the soft one the UI uses: sample data should leave
 * nothing behind, including in the recycle bin. Scoped entirely by the flag,
 * so nothing real is ever in range.
 */
function pcm_crm_delete_sample_data() {
	global $wpdb;

	$pcm_removed = 0;

	foreach ( pcm_crm_sample_tables() as $pcm_table ) {
		// phpcs:ignore WordPress.DB.PreparedSQL -- table names are internal
		$pcm_removed += (int) $wpdb->query( "DELETE FROM {$pcm_table} WHERE is_test = 1" );
	}

	// The old CLI seeder's bookkeeping goes with it; the flag is the record now.
	delete_option( 'pcm_crm_seed_ids' );

	return $pcm_removed;
}
