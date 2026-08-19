<?php
namespace EMS\Integrations;

class OSM_Parser {
	public function parse_user_id( array $payload ): int {
		return (int) ( $payload['data']['globals']['userid'] ?? 0 );
	}

	public function parse_access_type( array $payload ): string {
		// 1. Leader Check (Precedence)
		$roles = $payload['data']['globals']['roles'] ?? array();
		if ( ! empty( $roles ) && is_array( $roles ) ) {
			return 'leader';
		}

		// 2. Member / Parent Check
		$member_access = $payload['data']['globals']['member_access'] ?? array();
		if ( is_array( $member_access ) ) {
			foreach ( $member_access as $members_by_section ) {
				$members = $members_by_section['members'] ?? array();
				if ( is_array( $members ) ) {
					foreach ( $members as $member ) {
						$type = $member['access_type'] ?? '';
						if ( $type === 'member' ) {
							// Layer 2 detection: permissions === true (adult Network member)
							if ( isset( $member['permissions'] ) && $member['permissions'] === true ) {
								return 'network_member';
							}
							// Layer 1 detection: section type in OSM is network
							if ( ( $members_by_section['type'] ?? '' ) === 'network' ) {
								return 'network_member';
							}
						}
						if ( $type !== '' ) {
							return $type;
						}
					}
				}
			}
		}

		return 'unknown';
	}

	public function parse_scout_ids( array $payload ): array {
		$ids           = array();
		$member_access = $payload['data']['globals']['member_access'] ?? array();
		if ( is_array( $member_access ) ) {
			foreach ( $member_access as $section_data ) {
				$members = $section_data['members'] ?? array();
				if ( is_array( $members ) ) {
					foreach ( array_keys( $members ) as $scout_id ) {
						$ids[ (int) $scout_id ] = true;
					}
				}
			}
		}
		return array_keys( $ids );
	}

	public function parse_section_ids( array $payload ): array {
		$member_access = $payload['data']['globals']['member_access'] ?? array();
		if ( ! is_array( $member_access ) ) {
			$member_access = array();
		}
		$ids = array_map( 'intval', array_keys( $member_access ) );

		$roles = $payload['data']['globals']['roles'] ?? array();
		if ( is_array( $roles ) ) {
			foreach ( $roles as $role ) {
				$section_id = (int) ( $role['sectionid'] ?? 0 );
				if ( $section_id > 0 ) {
					$ids[] = $section_id;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Returns a map of section_id => ['name' => section_name] sourced from the roles list.
	 * Falls back to section ID as name if role data is missing.
	 *
	 * @return array<int, array{name: string, type: string}>
	 */
	public function parse_section_names( array $payload ): array {
		$names = array();
		foreach ( $payload['data']['globals']['roles'] ?? array() as $role ) {
			$id = (int) ( $role['sectionid'] ?? 0 );
			if ( $id > 0 && ! isset( $names[ $id ] ) ) {
				$names[ $id ] = array(
					'name' => $role['sectionname'] ?? $role['section'] ?? (string) $id,
					'type' => $role['section'] ?? '',
				);
			}
		}
		foreach ( $this->parse_section_ids( $payload ) as $id ) {
			if ( ! isset( $names[ $id ] ) ) {
				$names[ $id ] = array(
					'name' => (string) $id,
					'type' => '',
				);
			}
		}
		return $names;
	}

	public function parse_children( array $payload ): array {
		$children = array();
		$member_access = $payload['data']['globals']['member_access'] ?? array();
		if ( is_array( $member_access ) ) {
			foreach ( $member_access as $section_id => $section_data ) {
				$members = $section_data['members'] ?? array();
				if ( is_array( $members ) ) {
					foreach ( $members as $scout_id => $member ) {
						if ( ( $member['access_type'] ?? '' ) !== 'parent' ) {
							continue;
						}
						$id = (int) $scout_id;
						if ( ! isset( $children[ $id ] ) ) {
							$children[ $id ] = array(
								'scout_id'    => $id,
								'first_name'  => $member['first_name'] ?? '',
								'last_name'   => $member['last_name'] ?? '',
								'section_ids' => array(),
							);
						}
						$children[ $id ]['section_ids'][] = (int) $section_id;
					}
				}
			}
		}
		return array_values( $children );
	}

	public function parse_events( array $raw ): array {
		return array_map(
			static function ( array $item ): array {
				return array(
					'event_id'    => (int) $item['eventid'],
					'name'        => $item['name'] ?? '',
					'start_date'  => $item['startdate_g'] ?? '',
					'end_date'    => self::uk_to_iso( $item['enddate'] ?? '' ),
					'location'    => $item['location'] ?? '',
					'yes_members' => (int) ( $item['yes_members'] ?? 0 ),
					'yes_leaders' => (int) ( $item['yes_leaders'] ?? 0 ),
					'no'          => (int) ( $item['no'] ?? 0 ),
				);
			},
			$raw['items'] ?? array()
		);
	}

	private static function uk_to_iso( string $date ): string {
		if ( preg_match( '#^(\d{2})/(\d{2})/(\d{4})$#', $date, $m ) ) {
			return "{$m[3]}-{$m[2]}-{$m[1]}";
		}
		return $date;
	}

	/**
	 * Parses the full terms list from a getDataPayload response.
	 * Returns: [ section_id => [ ['term_id'=>int, 'name'=>str, 'start'=>str, 'end'=>str], ... ], ... ]
	 */
	public function parse_terms( array $payload ): array {
		$terms_raw = $payload['data']['globals']['terms'] ?? array();
		$result    = array();
		foreach ( $terms_raw as $section_id => $term_list ) {
			$result[ (int) $section_id ] = array_map(
				static function ( array $t ): array {
					return array(
						'term_id' => (int) $t['termid'],
						'name'    => $t['name'] ?? '',
						'start'   => $t['startdate'] ?? '',
						'end'     => $t['enddate'] ?? '',
					);
				},
				$term_list
			);
		}
		return $result;
	}

	/**
	 * Finds the current term for a section: the term whose date range contains today.
	 * Falls back to the most recent past term if none is current.
	 * Returns null if no terms exist for the section.
	 *
	 * @param array  $terms  Output of parse_terms() — keyed by section_id
	 * @param int    $section_id
	 * @param string $today  Y-m-d, defaults to today
	 * @return array|null  ['term_id'=>int, 'name'=>str, 'start'=>str, 'end'=>str]
	 */
	public function find_current_term( array $terms, int $section_id, string $today = '' ): ?array {
		if ( $today === '' ) {
			$today = gmdate( 'Y-m-d' );
		}
		$section_terms = $terms[ $section_id ] ?? array();
		if ( empty( $section_terms ) ) {
			return null;
		}

		$current  = null;
		$fallback = null;

		foreach ( $section_terms as $term ) {
			if ( $term['start'] <= $today && $today <= $term['end'] ) {
				$current = $term;
				break;
			}
			if ( $term['end'] < $today ) {
				$fallback = $term;
			}
		}

		return $current ?? $fallback;
	}

	/**
	 * Parses a getListOfMembers response into a normalised member array.
	 * Each item has: member_id, first_name, last_name, patrol, patrol_id.
	 * Email fields are NOT present — they require a separate getData call.
	 */
	public function parse_members( array $raw ): array {
		$items = $raw['items'] ?? $raw;
		return array_map(
			static function ( array $item ): array {
				return array(
					'member_id'  => (int) ( $item['scoutid'] ?? $item['member_id'] ?? 0 ),
					'first_name' => $item['firstname'] ?? $item['first_name'] ?? '',
					'last_name'  => $item['lastname'] ?? $item['last_name'] ?? '',
					'patrol'     => $item['patrol'] ?? '',
					'patrol_id'  => (int) ( $item['patrolid'] ?? 0 ),
				);
			},
			$items
		);
	}

	/**
	 * Parses a getData (members-getData) response and extracts email addresses.
	 * group_id=6 (Member contact), column_id=12 (Email 1 / explorer email),
	 * column_id=14 (Email 2 / parent email).
	 *
	 * @return array ['email' => string, 'parent_email' => string]
	 */
	public function parse_member_detail( array $raw ): array {
		$email        = '';
		$parent_email = '';

		$groups = $raw['data'] ?? array();
		foreach ( $groups as $group ) {
			if ( (int) ( $group['group_id'] ?? 0 ) !== 6 ) {
				continue;
			}
			foreach ( $group['columns'] ?? array() as $col ) {
				$cid = (int) ( $col['column_id'] ?? 0 );
				if ( $cid === 12 ) {
					$email = $col['value'] ?? '';
				} elseif ( $cid === 14 ) {
					$parent_email = $col['value'] ?? '';
				}
			}
		}

		return array(
			'email'        => $email,
			'parent_email' => $parent_email,
		);
	}

	/**
	 * Parses a getContactDetails response and extracts DOB.
	 */
	public function parse_contact_details( array $raw ): array {
		// Safe mapping of the nested structure in getContactDetails response
		$data = $raw['data']['data'] ?? $raw['data'] ?? array();
		return array(
			'scout_id'   => (int) ( $data['scoutid'] ?? $data['member_id'] ?? 0 ),
			'first_name' => $data['firstname'] ?? '',
			'last_name'  => $data['lastname'] ?? '',
			'dob'        => $data['dob'] ?? '',
		);
	}
}
