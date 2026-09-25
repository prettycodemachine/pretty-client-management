<?php
/**
 * Sample projects, and everything hanging off them.
 *
 * Seeded from the accounts and opportunities core has just created rather than
 * from thin air, so the set is coherent: a project belongs to an account that
 * exists, and most of them came from a deal that was actually won. Two piles of
 * unrelated demo data teach nothing about how the two halves join up.
 *
 * Every row carries is_test = 1, which is what makes removal "delete the rows
 * that say they are samples" rather than a list of ids in an option.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class PCM_CRM_PM_Sample_Data {

	private $owners = array();

	/**
	 * Build the project set on top of core's.
	 *
	 * @param array $pcm_context accounts, contacts, opportunities and owners as
	 *                           core's seeder created them.
	 */
	public function create( array $pcm_context ) {
		// A second fixed seed rather than sharing core's: the two run in
		// sequence, so a change to how many deals core makes would otherwise
		// shift every project as well and make a diff unreadable.
		mt_srand( 20260913 );

		$this->owners = ! empty( $pcm_context['owners'] ) ? $pcm_context['owners'] : array( get_current_user_id() );

		// The retainer lifecycle would otherwise open periods the moment each
		// project is inserted — before stamp() flags it a sample, so its
		// periods would survive removal — and close them before any time
		// existed to carry. Paused until the time is in; seed_periods() then
		// runs it once, the way a real retainer's history would have built up.
		pcm_crm_retainer_paused( true );

		$projects = $this->seed_projects( $pcm_context );

		$out = array(
			'projects'         => count( $projects ),
			'project_tasks'    => $this->seed_tasks( $projects ),
			'project_milestones' => $this->seed_milestones( $projects ),
			'project_raid'     => $this->seed_raid( $projects ),
			'project_roles'    => $this->seed_roles( $projects, $pcm_context ),
			'time_entries'     => $this->seed_time( $projects ),
		);

		pcm_crm_retainer_paused( false );

		$out['retainer_periods'] = $this->seed_periods( $projects );
		$out['allocations']      = $this->seed_allocations( $projects );

		return $out;
	}

	/* -------------------------------------------------------------------
	   Helpers
	   ------------------------------------------------------------------- */

	private function pick( array $list ) {
		return $list ? $list[ mt_rand( 0, count( $list ) - 1 ) ] : null;
	}

	private function days( $offset, $format = 'Y-m-d H:i:s' ) {
		return gmdate( $format, strtotime( $offset . ' days', current_time( 'timestamp' ) ) );
	}

	/**
	 * Backdate a row's audit stamps and flag it as a sample.
	 *
	 * insert() stamps created_date with now, which is right for a real record
	 * and useless for a burn-down: every sample row would land in this month.
	 * Written straight to the table because those columns are readonly to the
	 * model, which is the behaviour wanted everywhere else.
	 */
	private function stamp( $pcm_table, $pcm_id, $pcm_created ) {
		global $wpdb;

		$wpdb->update(
			$pcm_table,
			array( 'created_date' => $pcm_created, 'last_modified_date' => $pcm_created, 'is_test' => 1 ),
			array( 'id' => $pcm_id ),
			array( '%s', '%s', '%d' ),
			array( '%d' )
		);
	}

	/* -------------------------------------------------------------------
	   Projects
	   ------------------------------------------------------------------- */

	private function seed_projects( array $pcm_context ) {
		$accounts = ! empty( $pcm_context['accounts'] ) ? $pcm_context['accounts'] : array();
		$won      = $this->won_opportunities( $pcm_context );

		// Weighted so the retainer type dominates, which is what the real
		// business looks like, and so the board has something in every column.
		$plan = array(
			array( 'Support Retainer', 'Active' ),
			array( 'Support Retainer', 'Active' ),
			array( 'Support Retainer', 'At Risk' ),
			array( 'Support Retainer', 'Renewal Pending' ),
			array( 'Support Retainer', 'Onboarding' ),
			array( 'Support Retainer', 'Churned' ),
			array( 'Support Retainer', 'Active' ),
			array( 'Support Retainer', 'Active' ),
			array( 'Support Retainer', 'Onboarding' ),
			array( 'Support Retainer', 'Ended' ),
			array( 'Custom Development', 'Build' ),
			array( 'Custom Development', 'Discovery' ),
			array( 'Custom Development', 'UAT' ),
			array( 'Custom Development', 'Design' ),
			array( 'Custom Development', 'Hypercare' ),
			array( 'Custom Development', 'Closed' ),
			array( 'Custom Development', 'Initiation' ),
			array( 'Custom Development', 'Cancelled' ),
		);

		$names = array(
			'Support Retainer'   => array( 'Managed Support', 'Admin Retainer', 'Ongoing Support', 'Platform Care', 'AI Advisory', 'Copilot Rollout' ),
			'Custom Development' => array( 'Volunteer Portal', 'Grants Module', 'Data Migration', 'Reporting Rebuild', 'Integration Build', 'Donor Portal' ),
		);

		$model    = pcm_crm_projects();
		$projects = array();
		$index    = 0;

		foreach ( $plan as $entry ) {
			list( $type, $stage ) = $entry;

			$stage_struct = pcm_crm_pm_stage( $type, $stage );
			$is_closed    = ! empty( $stage_struct['is_closed'] );
			$is_retainer  = pcm_crm_pm_is_retainer( $type );

			// Most projects come from a won deal, a few do not — internal work
			// and referrals exist, and the related lists should have both.
			$deal       = ( $won && $index % 4 !== 3 ) ? $this->pick( $won ) : null;
			$account_id = $deal ? $deal['account_id'] : (int) $this->pick( $accounts );

			// Spread across roughly eighteen months so the dashboard has a
			// trend rather than a spike, and closed work sits behind live work.
			$start_offset = $is_closed ? mt_rand( -540, -220 ) : mt_rand( -300, -20 );
			$length       = $is_retainer ? mt_rand( 180, 365 ) : mt_rand( 60, 200 );
			$end_offset   = $start_offset + $length;

			$account_name = $this->account_name( $account_id );

			$fields = array(
				'name'           => trim( $account_name . ' — ' . $this->pick( $names[ $type ] ) ),
				'project_code'   => sprintf( 'PCM-%04d', 1000 + $index ),
				'account_id'     => $account_id,
				'opportunity_id' => $deal ? $deal['id'] : 0,
				'project_type'   => $type,
				'stage_name'     => $stage,
				'owner_id'       => (int) $this->pick( $this->owners ),
				'start_date'     => $this->days( $start_offset, 'Y-m-d' ),
				'end_date'       => $this->days( $end_offset, 'Y-m-d' ),
				'health'         => $this->health_for( $stage ),
				'description'    => 'Sample project for demonstrating the project screens.',
			);

			if ( $is_closed ) {
				$fields['actual_end_date'] = $this->days( $end_offset + mt_rand( -10, 25 ), 'Y-m-d' );
			}

			if ( $is_retainer ) {
				$fields['retainer_hours']        = $this->pick( array( 10, 16, 20, 24, 40 ) );
				$fields['retainer_period']       = 'monthly';
				$fields['retainer_rollover']     = mt_rand( 0, 1 );
				$fields['retainer_rollover_cap'] = $this->pick( array( 4, 8, 10 ) );
				$fields['retainer_start_date']   = $this->days( $start_offset, 'Y-m-d' );
				$fields['default_bill_rate']     = $this->pick( array( 165, 185, 195 ) );
				$fields['default_cost_rate']     = $this->pick( array( 85, 95, 110 ) );
			} else {
				$fields['budget_amount']     = $this->pick( array( 18000, 24000, 36000, 48000, 62000 ) );
				$fields['budget_hours']      = $this->pick( array( 120, 160, 220, 300 ) );
				$fields['default_bill_rate'] = $this->pick( array( 175, 195, 210 ) );
				$fields['default_cost_rate'] = $this->pick( array( 90, 100, 115 ) );
			}

			$id = $model->insert( $fields );

			if ( is_wp_error( $id ) ) {
				continue;
			}

			$this->stamp( pcm_crm_pm_projects_table(), $id, $this->days( $start_offset ) );

			$projects[] = array(
				'id'           => (int) $id,
				'account_id'   => $account_id,
				'type'         => $type,
				'stage'        => $stage,
				'is_closed'    => $is_closed,
				'is_retainer'  => $is_retainer,
				'start_offset' => $start_offset,
				'end_offset'   => $end_offset,
				'owner_id'     => $fields['owner_id'],
				'bill_rate'    => $fields['default_bill_rate'],
				'cost_rate'    => $fields['default_cost_rate'],
				'retainer'     => $is_retainer ? (float) $fields['retainer_hours'] : 0.0,
			);

			$index++;
		}

		return $projects;
	}

	/**
	 * The deals that actually closed won, so a project can point at one.
	 */
	private function won_opportunities( array $pcm_context ) {
		if ( empty( $pcm_context['opportunities'] ) ) {
			return array();
		}

		$ids = wp_list_pluck( $pcm_context['opportunities'], 'id' );
		$won = pcm_crm_opportunities()->find( array(
			'filters'  => array( 'ids' => $ids, 'is_won' => 1 ),
			'per_page' => 0,
		) );

		$out = array();

		foreach ( $won as $row ) {
			$out[] = array( 'id' => (int) $row['id'], 'account_id' => (int) $row['account_id'] );
		}

		return $out;
	}

	private function account_name( $pcm_account_id ) {
		$account = $pcm_account_id ? pcm_crm_accounts()->get( $pcm_account_id ) : null;

		return $account ? $account['name'] : 'Internal';
	}

	/**
	 * Health follows the stage, so the meters and the pill agree with the board.
	 */
	private function health_for( $pcm_stage ) {
		if ( 'At Risk' === $pcm_stage ) {
			return 'Red';
		}

		if ( in_array( $pcm_stage, array( 'Renewal Pending', 'UAT', 'Hypercare' ), true ) ) {
			return 'Amber';
		}

		return 'Green';
	}

	/* -------------------------------------------------------------------
	   Tasks
	   ------------------------------------------------------------------- */

	private function seed_tasks( array $pcm_projects ) {
		$delivery = array(
			'Kickoff workshop', 'Requirements review', 'Solution design', 'Build sprint one',
			'Build sprint two', 'Data migration dry run', 'UAT script walkthrough',
			'Go-live readiness check', 'Training session', 'Hypercare handover',
		);

		$retainer = array(
			'Monthly health check', 'Backlog grooming', 'Release regression pass',
			'Permission audit', 'Sandbox refresh', 'Stakeholder check-in',
		);

		$model = pcm_crm_project_tasks();
		$count = 0;

		foreach ( $pcm_projects as $project ) {
			$pool  = $project['is_retainer'] ? $retainer : $delivery;
			$total = mt_rand( 4, min( 9, count( $pool ) ) );
			$names = (array) array_slice( $pool, 0, $total );

			foreach ( $names as $position => $name ) {
				// Spread across the project's own window, so a task's dates sit
				// inside the project rather than beside it.
				$span   = max( 1, $project['end_offset'] - $project['start_offset'] );
				$due    = $project['start_offset'] + (int) round( $span * ( ( $position + 1 ) / ( $total + 1 ) ) );
				$status = $this->task_status( $due, $project['is_closed'] );

				$id = $model->insert( array(
					'project_id'       => $project['id'],
					'name'             => $name,
					'status'           => $status,
					'assignee_user_id' => (int) $this->pick( $this->owners ),
					'start_date'       => $this->days( $due - mt_rand( 3, 12 ), 'Y-m-d' ),
					'due_date'         => $this->days( $due, 'Y-m-d' ),
					'estimated_hours'  => $this->pick( array( 4, 6, 8, 12, 16, 24 ) ),
					'sort_order'       => $position,
				) );

				if ( is_wp_error( $id ) ) {
					continue;
				}

				$this->stamp( pcm_crm_pm_tasks_table(), $id, $this->days( $project['start_offset'] ) );
				$count++;
			}

		}

		return $count;
	}

	/**
	 * One or two milestones per project — its own table, its own seeder,
	 * since a milestone is no longer a project_task row (see
	 * model-project-milestone.php).
	 */
	private function seed_milestones( array $pcm_projects ) {
		$milestones = array( 'Design sign-off', 'Go live', 'Phase one complete', 'Contract renewal' );
		$model      = pcm_crm_project_milestones();
		$count      = 0;

		foreach ( $pcm_projects as $project ) {
			foreach ( (array) array_slice( $milestones, 0, mt_rand( 1, 2 ) ) as $position => $name ) {
				$due = $project['end_offset'] - ( $position * mt_rand( 20, 45 ) );

				$id = $model->insert( array(
					'project_id' => $project['id'],
					'name'       => $name,
					'status'     => $project['is_closed'] || $due < ( 0 - $project['start_offset'] ) ? 'Done' : 'Planned',
					'due_date'   => $this->days( $due, 'Y-m-d' ),
				) );

				if ( is_wp_error( $id ) ) {
					continue;
				}

				$this->stamp( pcm_crm_pm_milestones_table(), $id, $this->days( $project['start_offset'] ) );
				$count++;
			}
		}

		return $count;
	}

	private function task_status( $pcm_due_offset, $pcm_project_closed ) {
		if ( $pcm_project_closed ) {
			return 'Done';
		}

		if ( $pcm_due_offset < -20 ) {
			return 'Done';
		}

		if ( $pcm_due_offset < 0 ) {
			// Some overdue work is genuinely stuck rather than merely late, and
			// a board where nothing is ever blocked teaches nothing.
			return mt_rand( 0, 3 ) ? 'In Progress' : 'Blocked';
		}

		return 'Not Started';
	}

	/* -------------------------------------------------------------------
	   RAID
	   ------------------------------------------------------------------- */

	private function seed_raid( array $pcm_projects ) {
		// [ type, title, mitigation, detail ] — detail is the fuller "what this
		// actually is" write-up (the RAID log's description field), kept
		// distinct from mitigation, which is what's being done about it.
		$entries = array(
			array( 'Risk', 'Key admin is on leave during go-live', 'Cover agreed with the client’s second admin.',
				'The client’s primary Salesforce admin is out for two weeks spanning the planned cutover, and they are the only one who has previously run a production deploy for this org.' ),
			array( 'Risk', 'Legacy data quality is worse than sampled', 'Extra cleansing pass scheduled before migration.',
				'The initial 5% sample looked clean, but a fuller pass turned up duplicate contact records and inconsistent picklist values across roughly 15% of accounts.' ),
			array( 'Risk', 'Third-party API rate limits may throttle the sync', 'Batching and retry logic in the integration design.',
				'The vendor’s published rate limit is lower than our expected peak sync volume during month-end, which could delay records reaching Salesforce by several hours.' ),
			array( 'Issue', 'Sandbox refresh wiped test configuration', 'Rebuilt from the deployment package; now scripted.',
				'A routine sandbox refresh removed custom metadata and flow activations that had been configured by hand, costing the team roughly a day of rework.' ),
			array( 'Issue', 'Reports returning duplicates after the merge', 'Root cause traced to the matching rule.',
				'Several standard reports began showing duplicate rows after the account merge went live; affected users have been told results may be off until this is resolved.' ),
			array( 'Assumption', 'Client provides content for the portal pages', 'Confirmed in the kickoff; dates in the plan.',
				'The build assumes the client’s marketing team supplies final copy and images for each portal page rather than the project team drafting placeholder content.' ),
			array( 'Assumption', 'No changes to the approval process mid-project', 'Reviewed at each steering call.',
				'The approval workflow being automated is assumed to stay as documented in discovery; a policy change mid-build would mean re-mapping the flow logic.' ),
			array( 'Dependency', 'Security review sign-off from the client’s IT', 'Booked for the week before UAT.',
				'The client’s internal security team must review the integration’s auth flow and data handling before UAT can start; the review has not yet been scheduled.' ),
			array( 'Dependency', 'Data extract from the legacy finance system', 'Owner named; weekly chase in the status report.',
				'Migration cannot begin until the client’s finance system owner exports a full historical extract; the project has no access to that system directly.' ),
		);

		$model = pcm_crm_project_raid();
		$count = 0;

		foreach ( $pcm_projects as $project ) {
			$total = mt_rand( 2, 5 );

			for ( $i = 0; $i < $total; $i++ ) {
				$entry  = $this->pick( $entries );
				$raised = mt_rand( $project['start_offset'], min( -1, $project['start_offset'] + 60 ) );

				// A closed project has closed risks: an open RAID log on
				// finished work is exactly the thing the log exists to prevent.
				$status = $project['is_closed']
					? $this->pick( array( 'Closed', 'Mitigated', 'Accepted' ) )
					: $this->pick( array( 'Open', 'Open', 'Monitoring', 'Mitigated' ) );

				$id = $model->insert( array(
					'project_id'  => $project['id'],
					'raid_type'   => $entry[0],
					'title'       => $entry[1],
					'status'      => $status,
					'probability' => $this->pick( array( 'Low', 'Medium', 'Medium', 'High' ) ),
					'impact'      => $this->pick( array( 'Low', 'Medium', 'High', 'High' ) ),
					'raised_date' => $this->days( $raised, 'Y-m-d' ),
					'due_date'    => $this->days( $raised + mt_rand( 14, 60 ), 'Y-m-d' ),
					'mitigation'  => $entry[2],
					'description' => $entry[3],
					'owner_id'    => $project['owner_id'],
				) );

				if ( is_wp_error( $id ) ) {
					continue;
				}

				$this->stamp( pcm_crm_pm_raid_table(), $id, $this->days( $raised ) );
				$count++;
			}
		}

		return $count;
	}

	/* -------------------------------------------------------------------
	   Who is on the project
	   ------------------------------------------------------------------- */

	private function seed_roles( array $pcm_projects, array $pcm_context ) {
		$model = pcm_crm_project_roles();
		$count = 0;

		// A partner firm to hang partner roles off, picked from the accounts
		// core seeded rather than invented, so the lookup resolves to a real row.
		$partners = pcm_crm_accounts()->find( array(
			'filters'  => array( 'type' => 'Partner' ),
			'per_page' => 5,
		) );

		foreach ( $pcm_projects as $project ) {
			$rows = array();

			// Internal: always at least a lead, sometimes a second pair of hands.
			$rows[] = array(
				'party_type' => 'internal',
				'user_id'    => $project['owner_id'],
				'role'       => $project['is_retainer'] ? 'Engagement Lead' : 'Solution Architect',
				'is_primary' => 1,
				'bill_rate'  => $project['bill_rate'],
				'cost_rate'  => $project['cost_rate'],
			);

			$second = (int) $this->pick( $this->owners );

			if ( $second && $second !== $project['owner_id'] ) {
				$rows[] = array(
					'party_type' => 'internal',
					'user_id'    => $second,
					'role'       => $this->pick( array( 'Developer', 'Consultant', 'Admin' ) ),
					'bill_rate'  => $project['bill_rate'] - 20,
					'cost_rate'  => $project['cost_rate'] - 15,
				);
			}

			// Client side: whoever is at the account the project is for.
			$contacts = $project['account_id']
				? pcm_crm_contacts()->find( array( 'filters' => array( 'account_id' => $project['account_id'] ), 'per_page' => 3 ) )
				: array();

			foreach ( array_slice( $contacts, 0, mt_rand( 1, 2 ) ) as $position => $contact ) {
				$rows[] = array(
					'party_type' => 'client',
					'contact_id' => (int) $contact['id'],
					'role'       => 0 === $position ? 'Business Owner' : 'Subject Matter Expert',
					'is_primary' => 0 === $position ? 1 : 0,
				);
			}

			// A partner on roughly one project in three, so the three party
			// types are all represented somewhere in the set.
			if ( $partners && 0 === mt_rand( 0, 2 ) ) {
				$partner = $this->pick( $partners );

				$rows[] = array(
					'party_type'         => 'partner',
					'partner_account_id' => (int) $partner['id'],
					'role'               => $this->pick( array( 'Developer', 'Consultant', 'QA' ) ),
					'cost_rate'          => $this->pick( array( 70, 80, 95 ) ),
				);
			}

			foreach ( $rows as $row ) {
				$id = $model->insert( array_merge( array(
					'project_id' => $project['id'],
					'start_date' => $this->days( $project['start_offset'], 'Y-m-d' ),
				), $row ) );

				if ( is_wp_error( $id ) ) {
					continue;
				}

				$this->stamp( pcm_crm_pm_roles_table(), $id, $this->days( $project['start_offset'] ) );
				$count++;
			}
		}

		return $count;
	}

	/* -------------------------------------------------------------------
	   Retainer periods
	   ------------------------------------------------------------------- */

	private function seed_periods( array $pcm_projects ) {
		global $wpdb;

		$table = pcm_crm_pm_periods_table();
		$count = 0;

		foreach ( $pcm_projects as $project ) {
			if ( ! $project['is_retainer'] ) {
				continue;
			}

			// The real lifecycle, not a copy of it: every period from the
			// retainer's start, the seeded time filed under them, and each
			// carry-in worked from what was actually logged.
			pcm_crm_retainer_ensure( $project['id'], true );

			// Backdated like every other sample row, so the Burn-down reads as
			// history rather than as twelve periods all opened this morning.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$table}
				 SET created_date = CONCAT(period_start, ' 09:00:00'),
				     last_modified_date = CONCAT(period_start, ' 09:00:00'),
				     closed_date = IF(is_closed = 1, CONCAT(DATE_ADD(period_end, INTERVAL 1 DAY), ' 00:15:00'), closed_date),
				     is_test = 1
				 WHERE project_id = %d",
				$project['id']
			) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal
			$count += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE project_id = %d", $project['id'] ) );
		}

		return $count;
	}

	/* -------------------------------------------------------------------
	   Time
	   ------------------------------------------------------------------- */

	private function seed_time( array $pcm_projects ) {
		$notes = array(
			'Config changes and a short call', 'Backlog triage', 'Build work on the current story',
			'Pairing on the integration', 'Data checks after the load', 'Client call and follow-up notes',
			'Release prep', 'Bug fix and regression check', 'Documentation pass', 'Sprint planning',
		);

		$model = pcm_crm_time_entries();
		$count = 0;

		foreach ( $pcm_projects as $project ) {
			$tasks = pcm_crm_project_tasks()->find( array(
				'filters'  => array( 'project_id' => $project['id'] ),
				'per_page' => 20,
			) );

			// Weekly rhythm across the live part of the project, which is what
			// makes a burn-down look like work happening rather than a spike.
			$from = max( $project['start_offset'], -180 );
			$to   = min( $project['end_offset'], 0 );

			for ( $day = $from; $day <= $to; $day++ ) {
				$weekday = (int) gmdate( 'N', strtotime( $this->days( $day, 'Y-m-d' ) ) );

				// Weekends mostly empty, but not entirely — go-live happens.
				if ( $weekday > 5 && mt_rand( 0, 11 ) ) {
					continue;
				}

				if ( mt_rand( 0, 2 ) ) {
					continue;
				}

				$task = $tasks ? $this->pick( $tasks ) : null;

				$id = $model->insert( array(
					'project_id'  => $project['id'],
					'task_id'     => $task ? (int) $task['id'] : 0,
					'user_id'     => (int) $this->pick( $this->owners ),
					'entry_date'  => $this->days( $day, 'Y-m-d' ),
					'hours'       => $this->pick( array( 0.5, 1, 1.5, 2, 2.5, 3, 4, 6 ) ),
					'is_billable' => mt_rand( 0, 5 ) ? 1 : 0,
					'bill_rate'   => $project['bill_rate'],
					'cost_rate'   => $project['cost_rate'],
					'rate_source' => 'sample',
					'description' => $this->pick( $notes ),
				) );

				if ( is_wp_error( $id ) ) {
					continue;
				}

				$this->stamp( pcm_crm_pm_time_table(), $id, $this->days( $day ) );
				$count++;
			}
		}

		return $count;
	}

	/* -------------------------------------------------------------------
	   Allocations
	   ------------------------------------------------------------------- */

	private function seed_allocations( array $pcm_projects ) {
		$model = pcm_crm_allocations();
		$count = 0;

		// Thirteen weeks forward from four weeks back, which is the window the
		// resourcing board opens on.
		$weeks = pcm_crm_pm_weeks( $this->days( -28, 'Y-m-d' ), 17 );

		foreach ( $pcm_projects as $project ) {
			if ( $project['is_closed'] ) {
				continue;
			}

			$people = pcm_crm_project_roles()->find( array(
				'filters'  => array( 'project_id' => $project['id'], 'party_type' => 'internal' ),
				'per_page' => 5,
			) );

			foreach ( $people as $person ) {
				if ( empty( $person['user_id'] ) ) {
					continue;
				}

				// A contiguous run rather than every week, so a bar on the board
				// is a booking with a start and an end, and a gap reads as a gap.
				$offset = mt_rand( 0, 5 );
				$length = mt_rand( 3, 11 );

				foreach ( array_slice( $weeks, $offset, $length ) as $week ) {
					$id = $model->insert( array(
						'project_id'    => $project['id'],
						'user_id'       => (int) $person['user_id'],
						'week_start'    => $week,
						'planned_hours' => $this->pick( array( 4, 6, 8, 10, 12, 16, 20 ) ),
						'role'          => $person['role'],
					) );

					if ( is_wp_error( $id ) ) {
						continue;
					}

					$this->stamp( pcm_crm_pm_allocations_table(), $id, $this->days( -30 ) );
					$count++;
				}
			}
		}

		return $count;
	}
}

/**
 * Seed the project set after core has seeded its own.
 */
function pcm_crm_pm_sample_data( $pcm_counts, $pcm_context ) {
	$pcm_seeder = new PCM_CRM_PM_Sample_Data();

	return array_merge( $pcm_counts, $pcm_seeder->create( (array) $pcm_context ) );
}
add_filter( 'pcm_crm_sample_data_created', 'pcm_crm_pm_sample_data', 10, 2 );
