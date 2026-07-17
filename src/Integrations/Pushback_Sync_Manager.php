<?php
namespace EMS\Integrations;

use EMS\Integrations\Exceptions\Rate_Limit_Exception;
use EMS\Integrations\Exceptions\Api_Blocked_Exception;

class Pushback_Sync_Manager {

	private OSM_API_Client $api_client;
	private OSM_Parser $parser;

	public function __construct( OSM_API_Client $api_client, ?OSM_Parser $parser = null ) {
		$this->api_client = $api_client;
		$this->parser     = $parser ?? new OSM_Parser();
	}

	public function get_preview( int $section_id ): array {
		global $wpdb;

		$preview = array(
			'flexi_record' => array(
				'exists'           => false,
				'id'               => null,
				'proposed_name'    => '2026 Expeditions',
				'missing_columns'  => array(),
				'updates'          => array(),
			),
			'events'       => array(),
			'errors'       => array(),
		);

		try {
			// 1. Resolve term ID
			$payload = $this->api_client->get_data_payload();
			$terms   = $this->parser->parse_terms( $payload );
			$term    = $this->parser->find_current_term( $terms, $section_id );
			$term_id = $term ? (int) $term['term_id'] : 0;

			// 2. Fetch flexi-record structure
			$flexi_id = get_option( 'ems_osm_flexi_record_' . $section_id, false );
			if ( ! $flexi_id ) {
				$records = $this->api_client->get_flexi_records( $section_id );
				foreach ( $records as $rec ) {
					if ( ( $rec['name'] ?? '' ) === '2026 Expeditions' ) {
						$flexi_id = (int) $rec['id'];
						update_option( 'ems_osm_flexi_record_' . $section_id, $flexi_id );
						break;
					}
				}
			}

			$columns = array();
			$osm_data = array();

			if ( $flexi_id ) {
				$preview['flexi_record']['exists'] = true;
				$preview['flexi_record']['id']     = (int) $flexi_id;

				$struct = $this->api_client->get_flexi_record_structure( $section_id, $flexi_id );
				$config = json_decode( $struct['config'] ?? '[]', true ) ?: array();
				
				$mapped_cols = array();
				foreach ( $config as $col ) {
					$mapped_cols[ strtoupper( $col['name'] ?? '' ) ] = $col['id'] ?? '';
				}

				$required_cols = array(
					'PRACTICE GROUPS',
					'PRACTICE ACCEPTED',
					'QUALIFIER GROUPS',
					'QUALIFIER ACCEPTED',
					'TRAINING DAY',
					'FIRST AID',
				);

				foreach ( $required_cols as $req ) {
					if ( ! isset( $mapped_cols[ $req ] ) ) {
						$preview['flexi_record']['missing_columns'][] = $req;
					} else {
						$columns[ $req ] = $mapped_cols[ $req ];
					}
				}

				$data_payload = $this->api_client->get_flexi_record_data( $section_id, $flexi_id, $term_id );
				foreach ( $data_payload['items'] ?? array() as $item ) {
					$osm_data[ (int) $item['scoutid'] ] = $item;
				}
			}

			// 3. Query local EMS assignments
			$t_members = $wpdb->prefix . 'ems_team_members';
			$t_explorers = $wpdb->prefix . 'ems_osm_explorers';
			$posts = $wpdb->posts;
			$postmeta = $wpdb->postmeta;

			$local_assignments = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT 
						e.scout_id, e.first_name, e.last_name, e.first_aid_level,
						tm.team_post_id,
						t.post_title as team_name,
						m_code.meta_value as team_code,
						m_event.meta_value as osm_event_id,
						m_type.meta_value as event_type,
						m_date.meta_value as event_date
					FROM {$t_members} tm
					JOIN {$t_explorers} e ON e.scout_id = tm.scout_id
					JOIN {$posts} t ON t.ID = tm.team_post_id
					JOIN {$posts} exp ON exp.ID = t.post_parent
					LEFT JOIN {$postmeta} m_code ON m_code.post_id = t.ID AND m_code.meta_key = 'ems_team_code'
					LEFT JOIN {$postmeta} m_event ON m_event.post_id = exp.ID AND m_event.meta_key = 'ems_osm_event_id'
					LEFT JOIN {$postmeta} m_type ON m_type.post_id = exp.ID AND m_type.meta_key = 'ems_type'
					LEFT JOIN {$postmeta} m_date ON m_date.post_id = exp.ID AND m_date.meta_key = 'ems_start_date'
					WHERE e.section_id = %d AND t.post_type = 'team' AND t.post_status = 'publish'",
					$section_id
				)
			) ?: array();

			// Group by Event ID to fetch event attendance
			$event_to_assignments = array();
			foreach ( $local_assignments as $assign ) {
				$eid = $assign->osm_event_id ? (int) $assign->osm_event_id : null;
				if ( $eid ) {
					$event_to_assignments[ $eid ][] = $assign;
				}
			}

			// 4. Compute Event Attendance proposed invitations
			foreach ( $event_to_assignments as $eid => $assigns ) {
				try {
					$attendance = $this->api_client->get_event_attendance( $eid, $term_id );
					$items      = $attendance['data'] ?? $attendance;
					$att_map    = array();
					foreach ( $items as $att ) {
						$att_map[ (int) $att['member_id'] ] = $att['attending'] ?? null;
					}

					$proposed_invites = array();
					foreach ( $assigns as $assign ) {
						$scout_id = (int) $assign->scout_id;
						$current  = $att_map[ $scout_id ] ?? null;

						if ( $current === null || $current === '' ) {
							$proposed_invites[] = array(
								'scout_id'   => $scout_id,
								'first_name' => $assign->first_name,
								'last_name'  => $assign->last_name,
							);
						}
					}

					$preview['events'][] = array(
						'event_id'         => $eid,
						'proposed_invites' => $proposed_invites,
					);
				} catch ( \Exception $e ) {
					$preview['errors'][] = "Failed to fetch attendance for event {$eid}: " . $e->getMessage();
				}
			}

			// 5. Compute Flexi-record proposed updates
			if ( $flexi_id ) {
				// Group local assignments by scout to aggregate their values
				$scout_assignments = array();
				foreach ( $local_assignments as $assign ) {
					$scout_assignments[ (int) $assign->scout_id ][] = $assign;
				}

				foreach ( $scout_assignments as $scout_id => $assigns ) {
					$osm_row = $osm_data[ $scout_id ] ?? array();

					// Compute proposed value per column
					$proposed_values = array();

					// Practice
					$practice_team = '';
					$practice_accepted = '';
					// Qualifier
					$qualifier_team = '';
					$qualifier_accepted = '';
					// Training
					$training_day = '';
					// First Aid
					$first_aid = '';

					foreach ( $assigns as $assign ) {
						$type = $assign->event_type;
						$code = $assign->team_code ?: $assign->team_name;
						$date_str = $assign->event_date ? date( 'j/n', strtotime( $assign->event_date ) ) : '';

						if ( $assign->first_aid_level ) {
							$first_aid = strtoupper( str_replace( '_', ' ', $assign->first_aid_level ) );
						}

						if ( $type === 'practice' ) {
							$practice_team = $code;
							$practice_accepted = trim( "{$code} {$date_str} N" ); // Default invitation status
						} elseif ( $type === 'qualifying' ) {
							$qualifier_team = $code;
							$qualifier_accepted = trim( "{$code} {$date_str} N" );
						} elseif ( $type === 'training' ) {
							$training_day = $code;
						}
					}

					$fields_to_map = array(
						'PRACTICE GROUPS'    => $practice_team,
						'PRACTICE ACCEPTED'  => $practice_accepted,
						'QUALIFIER GROUPS'   => $qualifier_team,
						'QUALIFIER ACCEPTED' => $qualifier_accepted,
						'TRAINING DAY'       => $training_day,
						'FIRST AID'          => $first_aid,
					);

					foreach ( $fields_to_map as $col_name => $proposed_val ) {
						if ( ! isset( $columns[ $col_name ] ) || $proposed_val === '' ) {
							continue;
						}

						$col_key = $columns[ $col_name ];
						$current = $osm_row[ $col_key ] ?? '';

						// For accepted fields: if OSM already has a value ending in Y/N/Invited, check if the base team/date matches
						if ( strpos( $col_name, 'ACCEPTED' ) !== false && $current !== '' ) {
							// If the team and date matches but OSM has 'Y' or something else, do not override
							$base_proposed = substr( $proposed_val, 0, -1 ); // strip the 'N'
							if ( strpos( $current, trim( $base_proposed ) ) === 0 ) {
								continue;
							}
						}

						if ( $current !== $proposed_val ) {
							$preview['flexi_record']['updates'][] = array(
								'scout_id'       => $scout_id,
								'first_name'     => $assigns[0]->first_name,
								'last_name'      => $assigns[0]->last_name,
								'column'         => $col_key,
								'column_name'    => $col_name,
								'current_value'  => $current,
								'proposed_value' => $proposed_val,
							);
						}
					}
				}
			}

		} catch ( Rate_Limit_Exception $e ) {
			$preview['errors'][] = 'Rate limit exceeded: ' . $e->getMessage();
		} catch ( Api_Blocked_Exception $e ) {
			$preview['errors'][] = 'API is blocked: ' . $e->getMessage();
		} catch ( \Exception $e ) {
			$preview['errors'][] = 'Sync preview failed: ' . $e->getMessage();
		}

		return $preview;
	}
}
