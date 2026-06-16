<?php
namespace EMS\Integrations;

class OSM_Parser {
    public function parse_user_id( array $payload ): int {
        return (int) ( $payload['data']['globals']['userid'] ?? 0 );
    }

    public function parse_access_type( array $payload ): string {
        foreach ( $payload['data']['globals']['member_access'] ?? [] as $members_by_section ) {
            foreach ( $members_by_section['members'] ?? [] as $member ) {
                $type = $member['access_type'] ?? '';
                if ( $type !== '' ) {
                    return $type;
                }
            }
        }
        return 'unknown';
    }

    public function parse_scout_ids( array $payload ): array {
        $ids = [];
        foreach ( $payload['data']['globals']['member_access'] ?? [] as $section_data ) {
            foreach ( array_keys( $section_data['members'] ?? [] ) as $scout_id ) {
                $ids[ (int) $scout_id ] = true;
            }
        }
        return array_keys( $ids );
    }

    public function parse_section_ids( array $payload ): array {
        return array_map(
            'intval',
            array_keys( $payload['data']['globals']['member_access'] ?? [] )
        );
    }

    /**
     * Returns a map of section_id => ['name' => section_name] sourced from the roles list.
     * Falls back to section ID as name if role data is missing.
     *
     * @return array<int, array{name: string}>
     */
    public function parse_section_names( array $payload ): array {
        $names = [];
        foreach ( $payload['data']['globals']['roles'] ?? [] as $role ) {
            $id = (int) ( $role['sectionid'] ?? 0 );
            if ( $id > 0 && ! isset( $names[ $id ] ) ) {
                $names[ $id ] = [ 'name' => $role['sectionname'] ?? $role['section'] ?? (string) $id ];
            }
        }
        foreach ( $this->parse_section_ids( $payload ) as $id ) {
            if ( ! isset( $names[ $id ] ) ) {
                $names[ $id ] = [ 'name' => (string) $id ];
            }
        }
        return $names;
    }

    public function parse_children( array $payload ): array {
        if ( $this->parse_access_type( $payload ) !== 'parent' ) {
            return [];
        }

        $children = [];
        foreach ( $payload['data']['globals']['member_access'] ?? [] as $section_id => $section_data ) {
            foreach ( $section_data['members'] ?? [] as $scout_id => $member ) {
                $id = (int) $scout_id;
                if ( ! isset( $children[ $id ] ) ) {
                    $children[ $id ] = [
                        'scout_id'    => $id,
                        'first_name'  => $member['first_name'] ?? '',
                        'last_name'   => $member['last_name'] ?? '',
                        'section_ids' => [],
                    ];
                }
                $children[ $id ]['section_ids'][] = (int) $section_id;
            }
        }
        return array_values( $children );
    }

    public function parse_events( array $raw ): array {
        return array_map(
            static function ( array $item ): array {
                return [
                    'event_id'   => (int) $item['eventid'],
                    'name'       => $item['name']       ?? '',
                    'start_date' => $item['startdate_g'] ?? '',
                    'end_date'   => self::uk_to_iso( $item['enddate'] ?? '' ),
                    'location'   => $item['location']   ?? '',
                ];
            },
            $raw['items'] ?? []
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
        $terms_raw = $payload['data']['globals']['terms'] ?? [];
        $result    = [];
        foreach ( $terms_raw as $section_id => $term_list ) {
            $result[ (int) $section_id ] = array_map( static function ( array $t ): array {
                return [
                    'term_id' => (int) $t['termid'],
                    'name'    => $t['name']      ?? '',
                    'start'   => $t['startdate'] ?? '',
                    'end'     => $t['enddate']   ?? '',
                ];
            }, $term_list );
        }
        return $result;
    }

    /**
     * Finds the current term for a section: the term whose date range contains today.
     * Falls back to the most recent past term if none is current.
     * Returns null if no terms exist for the section.
     *
     * @param array $terms  Output of parse_terms() — keyed by section_id
     * @param int   $section_id
     * @param string $today  Y-m-d, defaults to today
     * @return array|null  ['term_id'=>int, 'name'=>str, 'start'=>str, 'end'=>str]
     */
    public function find_current_term( array $terms, int $section_id, string $today = '' ): ?array {
        if ( $today === '' ) {
            $today = gmdate( 'Y-m-d' );
        }
        $section_terms = $terms[ $section_id ] ?? [];
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
                return [
                    'member_id'  => (int) ( $item['scoutid']   ?? $item['member_id']  ?? 0 ),
                    'first_name' => $item['firstname']  ?? $item['first_name'] ?? '',
                    'last_name'  => $item['lastname']   ?? $item['last_name']  ?? '',
                    'patrol'     => $item['patrol']     ?? '',
                    'patrol_id'  => (int) ( $item['patrolid']  ?? 0 ),
                ];
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

        $groups = $raw['data'] ?? [];
        foreach ( $groups as $group ) {
            if ( (int) ( $group['group_id'] ?? 0 ) !== 6 ) {
                continue;
            }
            foreach ( $group['columns'] ?? [] as $col ) {
                $cid = (int) ( $col['column_id'] ?? 0 );
                if ( $cid === 12 ) {
                    $email = $col['value'] ?? '';
                } elseif ( $cid === 14 ) {
                    $parent_email = $col['value'] ?? '';
                }
            }
        }

        return [ 'email' => $email, 'parent_email' => $parent_email ];
    }
}
