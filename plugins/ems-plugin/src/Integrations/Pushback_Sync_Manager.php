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

	public function get_preview( int $section_id, bool $include_unaccepted = false ): array {
		global $wpdb;

		$preview = array(
			'events' => array(),
			'errors' => array(),
		);

		try {
			// 1. Resolve term ID
			$payload = $this->api_client->get_data_payload();
			$terms   = $this->parser->parse_terms( $payload );
			$term    = $this->parser->find_current_term( $terms, $section_id );
			$term_id = $term ? (int) $term['term_id'] : 0;

			// 2. Query local EMS assignments
			$t_members = $wpdb->prefix . 'ems_team_members';
			$t_explorers = $wpdb->prefix . 'ems_osm_explorers';
			$posts = $wpdb->posts;
			$postmeta = $wpdb->postmeta;

			$team_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT t.ID 
					FROM {$posts} t
					JOIN {$posts} exp ON exp.ID = t.post_parent
					JOIN {$postmeta} m_event ON m_event.post_id = exp.ID AND m_event.meta_key = 'ems_osm_event_id'
					JOIN {$wpdb->prefix}ems_osm_events oe ON oe.event_id = CAST(m_event.meta_value AS UNSIGNED)
					WHERE t.post_type = 'team' AND t.post_status = 'publish' AND oe.section_id = %d",
					$section_id
				)
			) ?: array();

			$offer_filter = $include_unaccepted ? '' : " AND tm.offer_status = 'accepted'";
			$local_assignments = array();
			if ( ! empty( $team_ids ) ) {
				$in_clause = implode( ',', array_map( 'intval', $team_ids ) );
				$local_assignments = $wpdb->get_results(
					"SELECT 
						e.scout_id, e.first_name, e.last_name, e.first_aid_level, e.section_id,
						tm.team_post_id,
						t.post_title as team_name,
						m_code.meta_value as team_code,
						m_event.meta_value as osm_event_id,
						m_type.meta_value as event_type,
						m_date.meta_value as event_date,
						m_event_code.meta_value as event_code,
						exp.post_title as expedition_name
					FROM {$t_members} tm
					JOIN {$t_explorers} e ON e.scout_id = tm.scout_id
					JOIN {$posts} t ON t.ID = tm.team_post_id
					JOIN {$posts} exp ON exp.ID = t.post_parent
					LEFT JOIN {$postmeta} m_code ON m_code.post_id = t.ID AND m_code.meta_key = 'ems_team_code'
					LEFT JOIN {$postmeta} m_event ON m_event.post_id = exp.ID AND m_event.meta_key = 'ems_osm_event_id'
					LEFT JOIN {$postmeta} m_type ON m_type.post_id = exp.ID AND m_type.meta_key = 'ems_type'
					LEFT JOIN {$postmeta} m_date ON m_date.post_id = exp.ID AND m_date.meta_key = 'ems_start_date'
					LEFT JOIN {$postmeta} m_event_code ON m_event_code.post_id = exp.ID AND m_event_code.meta_key = 'ems_event_code'
					WHERE tm.team_post_id IN ({$in_clause}) AND t.post_type = 'team' AND t.post_status = 'publish'{$offer_filter}"
				) ?: array();
			}

			// Group by Event ID to fetch event attendance
			$event_to_assignments = array();
			foreach ( $local_assignments as $assign ) {
				$eid = $assign->osm_event_id ? (int) $assign->osm_event_id : null;
				if ( $eid ) {
					$event_to_assignments[ $eid ][] = $assign;
				}
			}

			// 3. Compute Event Attendance proposed invitations
			foreach ( $event_to_assignments as $eid => $assigns ) {
				try {
					$attendance = $this->api_client->get_event_attendance( $eid, $term_id );
					$items      = $attendance['data'] ?? $attendance;
					$att_map    = array();
					foreach ( $items as $att ) {
						$att_map[ (int) $att['member_id'] ] = $att['attending'] ?? null;
					}

					$proposed_invites = array();
					$processed_scouts = array();

					// 1. Process local EMS assignments
					foreach ( $assigns as $assign ) {
						$scout_id = (int) $assign->scout_id;
						$current  = $att_map[ $scout_id ] ?? null;
						$processed_scouts[ $scout_id ] = true;

						$action        = 'None';
						$inconsistency = null;

						if ( $current === null || $current === '' ) {
							$action = 'Invite';
						} elseif ( $current === 'no' ) {
							$inconsistency = 'Declined in OSM but assigned in EMS';
						}

						$proposed_invites[] = array(
							'scout_id'      => $scout_id,
							'first_name'    => $assign->first_name,
							'last_name'     => $assign->last_name,
							'status'        => $current ?: 'Not Invited',
							'action'        => $action,
							'in_ems'        => true,
							'inconsistency' => $inconsistency,
						);
					}

					// 2. Process remaining OSM members who are NOT in EMS
					foreach ( $items as $row ) {
						$scout_id = (int) ( $row['member_id'] ?? 0 );
						if ( ! $scout_id || isset( $processed_scouts[ $scout_id ] ) ) {
							continue;
						}

						$current = $row['attending'] ?? null;
						if ( $current === null || $current === '' ) {
							continue; // Consistent (not in EMS, not in OSM)
						}

						$status_labels = array(
							'yes'                    => 'Attending',
							'no'                     => 'Declined',
							'invited'                => 'Invited',
							'reserved'               => 'Reserved',
							'show_in_parent_portal'  => 'Show in Parent Portal',
						);
						$label = $status_labels[ $current ] ?? ucfirst( str_replace( '_', ' ', $current ) );
						$inconsistency = "{$label} in OSM but not assigned in EMS";

						$proposed_invites[] = array(
							'scout_id'      => $scout_id,
							'first_name'    => $row['first_name'] ?? '',
							'last_name'     => $row['last_name'] ?? '',
							'status'        => $current,
							'action'        => 'None',
							'in_ems'        => false,
							'inconsistency' => $inconsistency,
						);
					}

					$event_row = $wpdb->get_row(
						$wpdb->prepare(
							"SELECT name FROM {$wpdb->prefix}ems_osm_events WHERE event_id = %d",
							$eid
						)
					);
					$osm_event_name  = $event_row ? $event_row->name : 'Unknown OSM Event';
					$expedition_name = ! empty( $assigns ) ? $assigns[0]->expedition_name : 'Unknown Expedition';

					$preview['events'][] = array(
						'event_id'         => $eid,
						'osm_event_name'   => $osm_event_name,
						'expedition_name'  => $expedition_name,
						'proposed_invites' => $proposed_invites,
					);
				} catch ( \Exception $e ) {
					$preview['errors'][] = "Failed to fetch attendance for event {$eid}: " . $e->getMessage();
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

	/**
	 * Executes the sync push by pushing all computed event invitations to OSM.
	 *
	 * @param int  $section_id
	 * @param bool $include_unaccepted
	 * @return array
	 * @throws \Exception
	 */
	public function execute_sync( int $section_id, bool $include_unaccepted = false ): array {
		$preview = $this->get_preview( $section_id, $include_unaccepted );

		$event_invites_count = 0;

		// Process event invitations
		$events = $preview['events'] ?? array();
		foreach ( $events as $ev ) {
			$event_id   = (int) $ev['event_id'];
			$invite_ids = array();
			foreach ( $ev['proposed_invites'] as $invite ) {
				if ( $invite['action'] === 'Invite' ) {
					$invite_ids[] = (int) $invite['scout_id'];
				}
			}

			if ( ! empty( $invite_ids ) ) {
				$this->api_client->update_event_attendance(
					$section_id,
					$event_id,
					$invite_ids
				);
				$event_invites_count += count( $invite_ids );
			}
		}

		return array(
			'success'             => true,
			'event_invites_count' => $event_invites_count,
		);
	}
}
