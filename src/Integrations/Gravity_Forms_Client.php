<?php
namespace EMS\Integrations;

class Gravity_Forms_Client {
    public function get_entries( int $form_id ): array {
        if ( ! class_exists( 'GFAPI' ) ) {
            return [];
        }

        $raw = \GFAPI::get_entries( $form_id, [] );
        if ( ! is_array( $raw ) ) {
            return [];
        }

        return $this->normalise( $raw, $form_id );
    }

    private function normalise( array $raw_entries, int $form_id ): array {
        $form   = class_exists( 'GFAPI' ) ? \GFAPI::get_form( $form_id ) : [];
        $result = [];

        foreach ( $raw_entries as $entry ) {
            $email = $this->find_email_field( $entry, $form );
            if ( $email === '' ) {
                continue;
            }
            $result[] = [
                'id'         => $entry['id'] ?? '',
                'email'      => $email,
                'first_name' => $this->find_field_by_type( $entry, $form, 'name', 'first' )
                                ?? ( $entry['3.3'] ?? '' ),
                'last_name'  => $this->find_field_by_type( $entry, $form, 'name', 'last' )
                                ?? ( $entry['3.6'] ?? '' ),
            ];
        }

        return $result;
    }

    private function find_email_field( array $entry, array $form ): string {
        if ( empty( $form['fields'] ) ) {
            return $entry['email'] ?? '';
        }
        foreach ( $form['fields'] as $field ) {
            if ( ( $field['type'] ?? '' ) === 'email' ) {
                return $entry[ (string) $field['id'] ] ?? '';
            }
        }
        return '';
    }

    private function find_field_by_type( array $entry, array $form, string $type, string $sub ): ?string {
        if ( empty( $form['fields'] ) ) {
            return null;
        }
        foreach ( $form['fields'] as $field ) {
            if ( ( $field['type'] ?? '' ) === $type ) {
                $key = $field['id'] . '.' . ( $sub === 'first' ? '3' : '6' );
                return $entry[ $key ] ?? null;
            }
        }
        return null;
    }
}
