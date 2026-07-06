<?php
namespace EMS\Data;

class Volunteer_Repository {
    private $wpdb;

    public function __construct( $wpdb = null ) {
        if ( $wpdb === null ) {
            global $wpdb;
        }
        $this->wpdb = $wpdb;
    }

    public function get_table(): string {
        return $this->wpdb->prefix . 'ems_volunteers';
    }

    public function get_availability_table(): string {
        return $this->wpdb->prefix . 'ems_volunteer_availability';
    }

    public function save_volunteer( array $data ): array {
        $email = sanitize_email( $data['email'] ?? '' );
        if ( empty( $email ) ) {
            throw new \Exception( 'Email is required for volunteer signup.' );
        }

        $table = $this->get_table();
        $existing = $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s", $email )
        );

        $now = current_time( 'mysql' );
        $fields = [
            'first_name'      => sanitize_text_field( $data['first_name'] ?? '' ),
            'last_name'       => sanitize_text_field( $data['last_name'] ?? '' ),
            'email'           => $email,
            'phone'           => sanitize_text_field( $data['phone'] ?? '' ),
            'dbs_number'      => sanitize_text_field( $data['dbs_number'] ?? '' ),
            'qualifications'  => isset( $data['qualifications'] ) ? json_encode( $data['qualifications'] ) : null,
            'preferred_roles' => isset( $data['preferred_roles'] ) ? json_encode( $data['preferred_roles'] ) : null,
            'updated_at'      => $now,
        ];

        if ( isset( $data['osm_user_id'] ) ) {
            $fields['osm_user_id'] = (int) $data['osm_user_id'];
        }
        if ( isset( $data['user_id'] ) ) {
            $fields['user_id'] = (int) $data['user_id'];
        }

        if ( $existing ) {
            $this->wpdb->update(
                $table,
                $fields,
                [ 'id' => $existing->id ]
            );
            $fields['id'] = (int) $existing->id;
        } else {
            $fields['created_at'] = $now;
            $this->wpdb->insert( $table, $fields );
            $fields['id'] = (int) $this->wpdb->insert_id;
        }

        return $fields;
    }

    public function save_availability( int $volunteer_id, int $expedition_post_id, array $shifts ): void {
        $table = $this->get_availability_table();

        // Clear existing availability for this volunteer and expedition
        $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$table} WHERE volunteer_id = %d AND expedition_post_id = %d",
                $volunteer_id,
                $expedition_post_id
            )
        );

        $now = current_time( 'mysql' );

        // Insert new records
        foreach ( $shifts as $shift ) {
            $this->wpdb->insert(
                $table,
                [
                    'volunteer_id'       => $volunteer_id,
                    'expedition_post_id' => $expedition_post_id,
                    'date'               => sanitize_text_field( $shift['date'] ),
                    'overnight'          => empty( $shift['overnight'] ) ? 0 : 1,
                    'confirmed'          => 0,
                    'updated_at'         => $now,
                ]
            );
        }
    }

    public function confirm_availability( int $id, int $confirmed ): bool {
        $table = $this->get_availability_table();
        $user_id = get_current_user_id();
        $now = current_time( 'mysql' );

        $updated = $this->wpdb->update(
            $table,
            [
                'confirmed'    => $confirmed,
                'confirmed_by' => $confirmed !== 0 ? $user_id : null,
                'updated_at'   => $now,
            ],
            [ 'id' => $id ]
        );

        if ( !$updated ) {
            return false;
        }

        // Fetch row info to enforce double-booking lock rules
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
        );

        if ( $row ) {
            if ( $confirmed === 1 ) {
                // Lock out alternatives: Set confirmed = -1 for other events on same date for same volunteer
                $this->wpdb->query(
                    $this->wpdb->prepare(
                        "UPDATE {$table} SET confirmed = -1 WHERE volunteer_id = %d AND date = %s AND expedition_post_id != %d AND confirmed = 0",
                        $row->volunteer_id,
                        $row->date,
                        $row->expedition_post_id
                    )
                );
            } elseif ( $confirmed === 0 ) {
                // Release lockout: Reset other conflicted events on same date for same volunteer back to 0
                $this->wpdb->query(
                    $this->wpdb->prepare(
                        "UPDATE {$table} SET confirmed = 0 WHERE volunteer_id = %d AND date = %s AND confirmed = -1",
                        $row->volunteer_id,
                        $row->date
                    )
                );
            }
        }

        return true;
    }

    public function get_volunteers(): array {
        $v_table = $this->get_table();
        $a_table = $this->get_availability_table();

        $volunteers = $this->wpdb->get_results( "SELECT * FROM {$v_table}", ARRAY_A );
        if ( empty( $volunteers ) ) {
            return [];
        }

        // Hydrate availability schedules
        foreach ( $volunteers as &$v ) {
            $v['id'] = (int) $v['id'];
            $v['osm_user_id'] = $v['osm_user_id'] ? (int) $v['osm_user_id'] : null;
            $v['user_id'] = $v['user_id'] ? (int) $v['user_id'] : null;
            $v['qualifications'] = $v['qualifications'] ? json_decode( $v['qualifications'], true ) : [];
            $v['preferred_roles'] = $v['preferred_roles'] ? json_decode( $v['preferred_roles'], true ) : [];

            $v['availability'] = $this->wpdb->get_results(
                $this->wpdb->prepare( "SELECT * FROM {$a_table} WHERE volunteer_id = %d", $v['id'] ),
                ARRAY_A
            );

            // Cast columns
            foreach ( $v['availability'] as &$avail ) {
                $avail['id'] = (int) $avail['id'];
                $avail['volunteer_id'] = (int) $avail['volunteer_id'];
                $avail['user_id'] = $avail['user_id'] ? (int) $avail['user_id'] : null;
                $avail['expedition_post_id'] = (int) $avail['expedition_post_id'];
                $avail['overnight'] = (int) $avail['overnight'];
                $avail['confirmed'] = (int) $avail['confirmed'];
                $avail['confirmed_by'] = $avail['confirmed_by'] ? (int) $avail['confirmed_by'] : null;
            }
        }

        return $volunteers;
    }
}
