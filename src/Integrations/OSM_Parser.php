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

    public function parse_members( array $raw ): array {
        return array_map(
            static function ( array $item ): array {
                return [
                    'member_id'    => (int) ( $item['member_id'] ?? $item['scoutid'] ?? 0 ),
                    'first_name'   => $item['first_name']   ?? $item['firstname'] ?? '',
                    'last_name'    => $item['last_name']    ?? $item['lastname']  ?? '',
                    'email'        => $item['email']        ?? '',
                    'parent_email' => $item['parent_email'] ?? '',
                    'dob'          => $item['dob']          ?? '',
                    'patrol'       => $item['patrol']       ?? '',
                ];
            },
            $raw
        );
    }
}
