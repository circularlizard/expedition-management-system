<?php
namespace EMS\Admin;

use EMS\Integrations\OSM_API_Client;
use EMS\Integrations\Gravity_Forms_Client;

class Reconciliation_Controller {
    private OSM_API_Client $osm_client;
    private Gravity_Forms_Client $gf_client;

    public function __construct( OSM_API_Client $osm_client, Gravity_Forms_Client $gf_client ) {
        $this->osm_client = $osm_client;
        $this->gf_client  = $gf_client;
    }

    public function reconcile( int $section_id, int $form_id ): array {
        $osm_members = $this->osm_client->get_section_participants( $section_id );
        $gf_entries  = $this->gf_client->get_entries( $form_id );

        $osm_by_email = [];
        foreach ( $osm_members as $member ) {
            $key                = strtolower( $member['email'] );
            $osm_by_email[$key] = $member;
        }

        $gf_by_email = [];
        foreach ( $gf_entries as $entry ) {
            $key               = strtolower( $entry['email'] );
            $gf_by_email[$key] = $entry;
        }

        $matched     = [];
        $only_in_osm = [];
        $only_in_gf  = [];

        foreach ( $osm_by_email as $email => $member ) {
            if ( isset( $gf_by_email[ $email ] ) ) {
                $matched[] = [
                    'email'      => $email,
                    'first_name' => $member['first_name'],
                    'last_name'  => $member['last_name'],
                    'member_id'  => $member['member_id'],
                    'gf_id'      => $gf_by_email[ $email ]['id'],
                ];
            } else {
                $only_in_osm[] = [
                    'email'      => $email,
                    'first_name' => $member['first_name'],
                    'last_name'  => $member['last_name'],
                    'member_id'  => $member['member_id'],
                ];
            }
        }

        foreach ( $gf_by_email as $email => $entry ) {
            if ( ! isset( $osm_by_email[ $email ] ) ) {
                $only_in_gf[] = [
                    'email'      => $email,
                    'first_name' => $entry['first_name'],
                    'last_name'  => $entry['last_name'],
                    'gf_id'      => $entry['id'],
                ];
            }
        }

        return compact( 'matched', 'only_in_osm', 'only_in_gf' );
    }
}
