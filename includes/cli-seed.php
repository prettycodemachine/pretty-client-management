<?php
/**
 * Demo data, for non-production environments only.
 *
 *   wp pcm-crm seed          create the sample set
 *   wp pcm-crm seed --reset  remove a previous set, then create a fresh one
 *   wp pcm-crm unseed        remove everything this command created
 *
 * Two things keep this out of production. The command is only registered when
 * WP-CLI is running, so it has no web-facing surface at all; and it refuses to
 * run unless the host looks like a staging or local site. The override exists
 * for an environment whose hostname does not say so, and has to be set
 * deliberately in wp-config.php:
 *
 *   define( 'PCM_CRM_ALLOW_SEED', true );
 *
 * Every id created is recorded in the pcm_crm_seed_ids option, so unseed
 * removes exactly what was made and cannot touch a real record typed in
 * alongside it. Removal is a hard DELETE rather than the soft delete the UI
 * uses — demo rows should leave nothing behind in the recycle bin.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

define( 'PCM_CRM_SEED_OPTION', 'pcm_crm_seed_ids' );

/**
 * Is this a environment where fake data is acceptable?
 *
 * Deny by default: an unrecognised host is treated as production, because the
 * failure mode of guessing wrong is 400 fake records in a real CRM.
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

class PCM_CRM_Seed_Command {

	/** Collected as records are created, then stored for unseed. */
	private $created = array();

	/**
	 * Create a sample CRM: accounts, contacts, opportunities and activities.
	 *
	 * ## OPTIONS
	 *
	 * [--reset]
	 * : Remove a previous sample set before creating a new one.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pcm-crm seed
	 *     wp pcm-crm seed --reset
	 */
	public function seed( $args, $assoc_args ) {
		$this->guard();

		if ( ! empty( $assoc_args['reset'] ) ) {
			$this->unseed( array(), array() );
		}

		if ( get_option( PCM_CRM_SEED_OPTION, array() ) ) {
			WP_CLI::warning( 'A sample set already exists. Use --reset to replace it, or `wp pcm-crm unseed` first.' );
			return;
		}

		// Fixed seed: the same command twice gives the same data, so a bug
		// found while filtering can be reproduced rather than re-hunted.
		mt_srand( 20260908 );

		$owners = $this->owners();

		WP_CLI::log( 'Creating accounts…' );
		$accounts = $this->seed_accounts( $owners );

		WP_CLI::log( 'Creating contacts…' );
		$contacts = $this->seed_contacts( $accounts, $owners );

		WP_CLI::log( 'Creating opportunities…' );
		$opportunities = $this->seed_opportunities( $accounts, $contacts, $owners );

		WP_CLI::log( 'Creating activities…' );
		$activities = $this->seed_activities( $accounts, $contacts, $opportunities, $owners );

		update_option( PCM_CRM_SEED_OPTION, $this->created );

		WP_CLI::success( sprintf(
			'%d accounts, %d contacts, %d opportunities, %d activities.',
			count( $accounts ),
			count( $contacts ),
			count( $opportunities ),
			count( $activities )
		) );
		WP_CLI::log( 'Remove it all again with: wp pcm-crm unseed' );
	}

	/**
	 * Remove every record the seeder created.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pcm-crm unseed
	 */
	public function unseed( $args, $assoc_args ) {
		global $wpdb;

		$this->guard();

		$stored = get_option( PCM_CRM_SEED_OPTION, array() );

		if ( ! $stored ) {
			WP_CLI::log( 'Nothing to remove — no sample set is recorded.' );
			return;
		}

		$tables = array(
			'accounts'      => PCM_CRM_Schema::accounts(),
			'contacts'      => PCM_CRM_Schema::contacts(),
			'opportunities' => PCM_CRM_Schema::opportunities(),
			'activities'    => PCM_CRM_Schema::activities(),
		);

		$total = 0;

		foreach ( $tables as $key => $table ) {
			$ids = isset( $stored[ $key ] ) ? array_filter( array_map( 'absint', $stored[ $key ] ) ) : array();

			if ( ! $ids ) {
				continue;
			}

			// Deleted by explicit id only. Nothing here matches on a name or a
			// date, so a real record created alongside the demo set is safe
			// however closely it resembles one.
			$in = implode( ',', $ids );

			// phpcs:ignore WordPress.DB.PreparedSQL -- ids are cast to int above
			$total += (int) $wpdb->query( "DELETE FROM {$table} WHERE id IN ({$in})" );
		}

		delete_option( PCM_CRM_SEED_OPTION );

		WP_CLI::success( sprintf( 'Removed %d sample records.', $total ) );
	}

	/* -------------------------------------------------------------------
	   Guards and helpers
	   ------------------------------------------------------------------- */

	private function guard() {
		if ( pcm_crm_seed_allowed() ) {
			return;
		}

		WP_CLI::error(
			'This looks like production (' . wp_parse_url( home_url(), PHP_URL_HOST ) . '). ' .
			'Refusing to touch sample data. Set PCM_CRM_ALLOW_SEED in wp-config.php if this really is a test site.'
		);
	}

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

		$wpdb->update(
			$table,
			array( 'created_date' => $created, 'last_modified_date' => $created ),
			array( 'id' => $id ),
			array( '%s', '%s' ),
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
				WP_CLI::warning( 'Account failed: ' . $row[0] );
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
					WP_CLI::warning( 'Contact failed: ' . $id->get_error_message() );
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

			$id = pcm_crm_opportunities()->insert( array(
				'account_id'         => $account_id,
				'primary_contact_id' => $contact ? $contact['id'] : 0,
				'name'               => $name,
				'stage_name'         => $stage_name,
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

			$opportunities[] = array( 'id' => (int) $id, 'account_id' => $account_id, 'contact_id' => $contact ? $contact['id'] : 0 );
		}

		return $opportunities;
	}

	private function seed_activities( $accounts, $contacts, $opportunities, $owners ) {
		$subjects = array(
			'Call'     => array( 'Intro call', 'Check-in call', 'Scoping call', 'Follow-up call', 'Renewal conversation' ),
			'Email'    => array( 'Sent proposal', 'Shared pricing sheet', 'Answered admin question', 'Sent meeting notes', 'Scheduling emails' ),
			'Meeting'  => array( 'Discovery workshop', 'Requirements review', 'Quarterly review', 'Board presentation', 'Training session' ),
			'Task'     => array( 'Draft scope of work', 'Prepare dashboard mockups', 'Audit their org', 'Write migration plan', 'Review sandbox config' ),
			'Note'     => array( 'Budget cycle ends in June', 'Prefers async updates', 'Two admins on staff', 'Currently on Classic', 'Grant-funded project' ),
			'Web Form' => array( 'Web enquiry — Salesforce Managed Support', 'Web enquiry — AI Enablement', 'Web enquiry — Custom Development' ),
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

		// And off contacts directly, including the web enquiries that would
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

WP_CLI::add_command( 'pcm-crm', 'PCM_CRM_Seed_Command' );
