<?php
namespace EMS\Data;

class Database_Seeder {
	/**
	 * Run the seeder logic.
	 *
	 * @param callable|null $log_callback Callback for logging progress messages.
	 * @return array{participant_count: int, expedition_count: int, volunteer_count?: int}
	 * @throws \Exception If no explorers are found in the database.
	 */
	public function seed( ?callable $log_callback = null ): array {
		global $wpdb;

		$participant_table = $wpdb->prefix . 'ems_participant_signups';
		$expedition_table  = $wpdb->prefix . 'ems_expedition_signups';
		$explorers_table   = $wpdb->prefix . 'ems_osm_explorers';
		$submissions_table = $wpdb->prefix . 'fluentform_submissions';
		$volunteers_table  = $wpdb->prefix . 'ems_volunteers';
		$avail_table       = $wpdb->prefix . 'ems_volunteer_availability';

		if ( $log_callback ) {
			call_user_func( $log_callback, 'Cleaning up old seeder data...' );
		}

		// 1. Truncate custom EMS signup and volunteer tables
		$wpdb->query( "TRUNCATE TABLE {$participant_table}" );
		$wpdb->query( "TRUNCATE TABLE {$expedition_table}" );
		$wpdb->query( "TRUNCATE TABLE {$volunteers_table}" );
		$wpdb->query( "TRUNCATE TABLE {$avail_table}" );

		// 2. Delete CPT expeditions and teams
		$posts = get_posts(
			array(
				'post_type'   => array( 'expedition', 'team' ),
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);
		foreach ( $posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		if ( $log_callback ) {
			call_user_func( $log_callback, 'Deleted old expedition and team posts.' );
		}

		// 3. Delete old Fluent Form submissions
		$p_form_id = (int) get_option( 'ems_fluent_participant_form_id', 6 );
		$e_form_id = (int) get_option( 'ems_fluent_expedition_form_id', 7 );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$submissions_table} WHERE form_id IN (%d, %d)", $p_form_id, $e_form_id ) );
		if ( $log_callback ) {
			call_user_func( $log_callback, "Deleted old Fluent Forms submissions for form IDs {$p_form_id} and {$e_form_id}." );
		}

		if ( $log_callback ) {
			call_user_func( $log_callback, 'Creating expeditions...' );
		}

		// Create expeditions and capture IDs
		$expedition_ids = array();
		$expedition_ids['H-T1'] = $this->create_test_expedition( 'Combined 1-day Training (Hill)', 'H-T1', 'training', 'hillwalking', 'silver', '2026-08-15', '2026-08-15', '09:00', '17:00' );
		$expedition_ids['H-T2'] = $this->create_test_expedition( 'Combined 2-day Training (Hill)', 'H-T2', 'training', 'hillwalking', 'silver', '2026-08-15', '2026-08-16', '09:00', '17:00' );
		$expedition_ids['B-T1'] = $this->create_test_expedition( 'Combined 1-day Training (Bike)', 'B-T1', 'training', 'biking', 'silver', '2026-08-22', '2026-08-22', '09:00', '17:00' );
		$expedition_ids['B-T2'] = $this->create_test_expedition( 'Combined 2-day Training (Bike)', 'B-T2', 'training', 'biking', 'silver', '2026-08-22', '2026-08-23', '09:00', '17:00' );

		$expedition_ids['H-SP1'] = $this->create_test_expedition( 'Silver Practice 1', 'H-SP1', 'practice', 'hillwalking', 'silver', '2026-09-04', '2026-09-06', '16:00', '16:00' );
		$expedition_ids['H-SP2'] = $this->create_test_expedition( 'Silver Practice 2', 'H-SP2', 'practice', 'hillwalking', 'silver', '2026-09-11', '2026-09-13', '16:00', '16:00' );
		$expedition_ids['H-GP1'] = $this->create_test_expedition( 'Gold Practice 1', 'H-GP1', 'practice', 'hillwalking', 'gold', '2026-09-04', '2026-09-06', '16:00', '16:00' );
		$expedition_ids['H-GP2'] = $this->create_test_expedition( 'Gold Practice 2', 'H-GP2', 'practice', 'hillwalking', 'gold', '2026-09-11', '2026-09-13', '16:00', '16:00' );
		$expedition_ids['B-GP1'] = $this->create_test_expedition( 'Gold Practice Biking 1', 'B-GP1', 'practice', 'biking', 'gold', '2026-09-04', '2026-09-06', '16:00', '16:00' );

		$expedition_ids['H-SQ1'] = $this->create_test_expedition( 'Silver Qualifier 1', 'H-SQ1', 'qualifying', 'hillwalking', 'silver', '2026-09-25', '2026-09-27', '09:00', '16:00' );
		$expedition_ids['H-SQ2'] = $this->create_test_expedition( 'Silver Qualifier 2', 'H-SQ2', 'qualifying', 'hillwalking', 'silver', '2026-10-02', '2026-10-04', '09:00', '16:00' );
		$expedition_ids['H-GQ1'] = $this->create_test_expedition( 'Gold Qualifier 1', 'H-GQ1', 'qualifying', 'hillwalking', 'gold', '2026-09-23', '2026-09-27', '19:00', '16:00' );
		$expedition_ids['H-GQ2'] = $this->create_test_expedition( 'Gold Qualifier 2', 'H-GQ2', 'qualifying', 'hillwalking', 'gold', '2026-09-30', '2026-10-04', '19:00', '16:00' );
		$expedition_ids['B-GQ1'] = $this->create_test_expedition( 'Gold Qualifier Biking 1', 'B-GQ1', 'qualifying', 'biking', 'gold', '2026-09-23', '2026-09-27', '19:00', '16:00' );

		if ( $log_callback ) {
			call_user_func( $log_callback, 'Querying synced explorers from DB...' );
		}

		$explorers = $wpdb->get_results( "SELECT scout_id, first_name, last_name, email, parent_email, patrol FROM {$explorers_table}", ARRAY_A );
		if ( empty( $explorers ) ) {
			throw new \Exception( 'No explorers found in database! Please sync or seed explorers table first.' );
		}

		if ( $log_callback ) {
			call_user_func( $log_callback, 'Seeding adult volunteers and availability...' );
		}

		$volunteers = array(
			array(
				'first_name'      => 'Dave',
				'last_name'       => 'Smith',
				'email'           => 'dave.smith@volunteers.ems',
				'phone'           => '07700900077',
				'dbs_number'      => '1234567890',
				'qualifications'  => array( 'First Aid', 'Mountain Leader' ),
				'preferred_roles' => array( 'Supervisor' ),
				'availability'    => array(
					array( 'code' => 'H-SP1', 'dates' => array( '2026-09-04', '2026-09-05', '2026-09-06' ), 'overnight' => 1, 'confirmed' => 1 ),
					array( 'code' => 'H-SQ1', 'dates' => array( '2026-09-25', '2026-09-26', '2026-09-27' ), 'overnight' => 1, 'confirmed' => 0 ),
				),
			),
			array(
				'first_name'      => 'Sarah',
				'last_name'       => 'Jones',
				'email'           => 'sarah.jones@volunteers.ems',
				'phone'           => '07700900088',
				'dbs_number'      => '2345678901',
				'qualifications'  => array( 'First Aid', 'Hillwalking Assessor' ),
				'preferred_roles' => array( 'Assessor' ),
				'availability'    => array(
					array( 'code' => 'H-SP1', 'dates' => array( '2026-09-04' ), 'overnight' => 0, 'confirmed' => 1 ),
					array( 'code' => 'H-GP1', 'dates' => array( '2026-09-04' ), 'overnight' => 0, 'confirmed' => -1 ),
					array( 'code' => 'H-SP1', 'dates' => array( '2026-09-05', '2026-09-06' ), 'overnight' => 1, 'confirmed' => 0 ),
				),
			),
			array(
				'first_name'      => 'John',
				'last_name'       => 'Miller',
				'email'           => 'john.miller@volunteers.ems',
				'phone'           => '07700900099',
				'dbs_number'      => '3456789012',
				'qualifications'  => array( 'First Aid' ),
				'preferred_roles' => array( 'Supervisor' ),
				'availability'    => array(
					array( 'code' => 'H-T1', 'dates' => array( '2026-08-15' ), 'overnight' => 0, 'confirmed' => 1 ),
					array( 'code' => 'H-T2', 'dates' => array( '2026-08-15', '2026-08-16' ), 'overnight' => 1, 'confirmed' => 0 ),
					array( 'code' => 'H-SP2', 'dates' => array( '2026-09-11', '2026-09-12', '2026-09-13' ), 'overnight' => 1, 'confirmed' => 1 ),
				),
			),
			array(
				'first_name'      => 'Emily',
				'last_name'       => 'Watson',
				'email'           => 'emily.watson@volunteers.ems',
				'phone'           => '07700900100',
				'dbs_number'      => '4567890123',
				'qualifications'  => array( 'Hillwalking Assessor' ),
				'preferred_roles' => array( 'Assessor' ),
				'availability'    => array(
					array( 'code' => 'B-GP1', 'dates' => array( '2026-09-04', '2026-09-05', '2026-09-06' ), 'overnight' => 1, 'confirmed' => 0 ),
				),
			),
		);

		$vol_repo = new \EMS\Data\Volunteer_Repository();
		foreach ( $volunteers as $v_data ) {
			$saved = $vol_repo->save_volunteer( array(
				'first_name'      => $v_data['first_name'],
				'last_name'       => $v_data['last_name'],
				'email'           => $v_data['email'],
				'phone'           => $v_data['phone'],
				'dbs_number'      => $v_data['dbs_number'],
				'qualifications'  => $v_data['qualifications'],
				'preferred_roles' => $v_data['preferred_roles'],
			) );

			foreach ( $v_data['availability'] as $avail_data ) {
				$exped_id = $expedition_ids[ $avail_data['code'] ] ?? null;
				if ( ! $exped_id ) {
					continue;
				}

				foreach ( $avail_data['dates'] as $date ) {
					$wpdb->insert(
						$wpdb->prefix . 'ems_volunteer_availability',
						array(
							'volunteer_id'       => $saved['id'],
							'expedition_post_id' => $exped_id,
							'date'               => $date,
							'overnight'          => $avail_data['overnight'],
							'confirmed'          => $avail_data['confirmed'],
							'confirmed_by'       => $avail_data['confirmed'] === 1 ? 1 : null,
							'updated_at'         => current_time( 'mysql' ),
							'signup_type'        => 'part',
						)
					);
				}
			}
		}

		if ( $log_callback ) {
			call_user_func( $log_callback, 'Found ' . count( $explorers ) . ' explorers. Seeding form submissions...' );
		}

		$sync = new \EMS\Integrations\Fluent_Forms_Sync();

		$explorers_by_patrol = array();
		foreach ( $explorers as $exp ) {
			$patrol                           = $exp['patrol'] ?: 'Unknown';
			$explorers_by_patrol[ $patrol ][] = $exp;
		}

		$participant_count = 0;
		$expedition_count  = 0;

		foreach ( $explorers as $exp ) {
			$scout_id     = (int) $exp['scout_id'];
			$first_name   = $exp['first_name'];
			$last_name    = $exp['last_name'];
			$patrol       = $exp['patrol'];
			$email        = $exp['email'] ?: strtolower( "{$first_name}.{$last_name}@mailinator.com" );
			$parent_email = $exp['parent_email'] ?: 'SEEE-parent@mailinator.com';

			// 1. Determine level by Patrol
			if ( $patrol === 'S3' ) {
				$level = 'bronze';
			} elseif ( $patrol === 'S4' ) {
				$level = 'silver';
			} else {
				$level = 'gold';
			}

			// 2. Create Participant Place form submission
			$p_data = array(
				'signup_child'             => "{$scout_id}|{$first_name}|{$last_name}",
				'signup_child_name'        => array(
					'first_name' => $first_name,
					'last_name'  => $last_name,
				),
				'signup_scoutid'           => $scout_id,
				'signup_unit'              => $patrol,
				'signup_explorer_email'    => $email,
				'signup_parent_email'      => $parent_email,
				'signup_dob'               => '2010-05-15',
				'signup_level'             => $level,
				'signup_dofe_registered'   => 'y',
				'signup_dofe_number'       => 'D-' . rand( 100000, 999999 ),
				'signup_dofe_org'          => '',
				'signup_bronze_completion' => $level !== 'bronze' ? array( 'Volunteering', 'Skills', 'Physical', 'Expedition' ) : null,
				'signup_silver_completion' => $level === 'gold' ? array( 'Volunteering', 'Skills', 'Physical', 'Expedition' ) : null,
			);

			$this->submit_mock_form( $p_form_id, $p_data, $sync );
			++$participant_count;

			// 3. Create Expedition signup (Silver & Gold only)
			if ( $level === 'silver' || $level === 'gold' ) {
				$transport = 'Hillwalking';
				if ( $level === 'gold' && rand( 1, 10 ) == 10 ) {
					$transport = 'Biking';
				}

				$practice_dates  = array();
				$qualifier_dates = array();

				if ( $transport === 'Hillwalking' ) {
					if ( $level === 'silver' ) {
						$practice_dates  = array( 'H-SP1', 'H-SP2' );
						$qualifier_dates = array( 'H-SQ1', 'H-SQ2' );
					} else {
						$practice_dates  = array( 'H-GP1', 'H-GP2' );
						$qualifier_dates = array( 'H-GQ1', 'H-GQ2' );
					}
				} else {
					$practice_dates  = array( 'B-GP1' );
					$qualifier_dates = array( 'B-GQ1' );
				}

				$teammate_names = array();
				$patrol_peers   = $explorers_by_patrol[ $patrol ] ?? array();
				foreach ( $patrol_peers as $peer ) {
					if ( (int) $peer['scout_id'] !== $scout_id ) {
						$teammate_names[] = "{$peer['first_name']} {$peer['last_name']}";
					}
				}
				shuffle( $teammate_names );
				$teammate_str = implode( ', ', array_slice( $teammate_names, 0, 3 ) );

				$e_data = array(
					'signup_child'                 => "{$scout_id}|{$first_name}|{$last_name}",
					'signup_child_name'            => array(
						'first_name' => $first_name,
						'last_name'  => $last_name,
					),
					'signup_scoutid'               => $scout_id,
					'signup_unit'                  => $patrol,
					'signup_explorer_email'        => $email,
					'signup_parent_email'          => $parent_email,
					'signup_edofe_registered'      => 'y',
					'signup_dofe_number'           => 'D-' . rand( 100000, 999999 ),
					'signup_level'                 => $level,
					'exped_type'                   => $transport,
					'exped-silver-practice-dates'  => $level === 'silver' ? $practice_dates : array(),
					'exped-silver-qualifier-dates' => $level === 'silver' ? $qualifier_dates : array(),
					'exped-gold-practice-dates'    => $level === 'gold' ? $practice_dates : array(),
					'exped-gold-qualifier-dates'   => $level === 'gold' ? $qualifier_dates : array(),
					'exped_silver_practice_dates'  => $level === 'silver' ? $practice_dates : array(),
					'exped_silver_qualifier_dates' => $level === 'silver' ? $qualifier_dates : array(),
					'exped_gold_practice_dates'    => $level === 'gold' ? $practice_dates : array(),
					'exped_gold_qualifier_dates'   => $level === 'gold' ? $qualifier_dates : array(),
					'exped_team_names'             => $teammate_str,
					'exped_asn'                    => rand( 1, 10 ) > 8 ? 'Asthma inhaler' : '',
					'input_radio'                  => 'first-response',
					'datetime'                     => '2028-06-13',
				);

				$this->submit_mock_form( $e_form_id, $e_data, $sync );
				++$expedition_count;
			}
		}

		return array(
			'participant_count' => $participant_count,
			'expedition_count'  => $expedition_count,
			'volunteer_count'   => 4,
		);
	}

	private function create_test_expedition( $title, $code, $type, $transport, $level, $start_date, $end_date, $start_time, $end_time ) {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'expedition',
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_parent' => 0,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			throw new \Exception( "Failed to create expedition {$title}: " . $post_id->get_error_message() );
		}

		update_post_meta( $post_id, 'ems_event_code', $code );
		update_post_meta( $post_id, 'ems_type', $type );
		update_post_meta( $post_id, 'ems_transport', $transport );
		update_post_meta( $post_id, 'ems_level', $level );
		update_post_meta( $post_id, 'ems_status', 'active' );
		update_post_meta( $post_id, 'ems_start_date', $start_date );
		update_post_meta( $post_id, 'ems_end_date', $end_date );
		update_post_meta( $post_id, 'ems_start_time', $start_time );
		update_post_meta( $post_id, 'ems_end_time', $end_time );

		// Auto-create UNALLOCATED team for this event
		$team_repo = new \EMS\Data\Team_Repository();
		$team_repo->create( $post_id, $code, 'UNALLOCATED' );

		return $post_id;
	}

	private function submit_mock_form( $form_id, $form_data, $sync ) {
		global $wpdb;
		$submissions_table = $wpdb->prefix . 'fluentform_submissions';

		$wpdb->insert(
			$submissions_table,
			array(
				'form_id'        => $form_id,
				'response'       => json_encode( $form_data ),
				'status'         => 'unread',
				'created_at'     => current_time( 'mysql' ),
				'updated_at'     => current_time( 'mysql' ),
				'payment_status' => $form_id === (int) get_option( 'ems_fluent_participant_form_id', 6 ) ? 'paid' : null,
			)
		);

		$entry_id = $wpdb->insert_id;

		$form_obj = (object) array( 'id' => $form_id );
		$sync->handle_submission( $entry_id, $form_data, $form_obj );

		return $entry_id;
	}
}
