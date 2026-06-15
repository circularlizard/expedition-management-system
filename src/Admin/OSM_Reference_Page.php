<?php
namespace EMS\Admin;

use EMS\Data\Expedition_Repository;

class OSM_Reference_Page {

    private Expedition_Repository $expeditions;

    public function __construct( Expedition_Repository $expeditions ) {
        $this->expeditions = $expeditions;
    }

    public function register(): void {
        add_submenu_page(
            'ems',
            __( 'OSM Reference', 'ems-plugin' ),
            __( 'OSM Reference', 'ems-plugin' ),
            'manage_options',
            'ems-reference',
            [ $this, 'render' ]
        );
    }

    public function render(): void {
        $explorers = get_users( [
            'meta_key'     => 'ems_scout_id',
            'meta_compare' => 'EXISTS',
        ] );

        $expeditions = $this->expeditions->list_all();

        $last_sync = get_option( 'ems_osm_last_sync', '' );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'OSM Reference', 'ems-plugin' ) . '</h1>';

        if ( $last_sync ) {
            echo '<p><strong>' . esc_html__( 'Last OSM sync:', 'ems-plugin' ) . '</strong> ' . esc_html( $last_sync ) . '</p>';
        } else {
            echo '<p><strong>' . esc_html__( 'Last OSM sync:', 'ems-plugin' ) . '</strong> ' . esc_html__( 'Never', 'ems-plugin' ) . '</p>';
        }

        echo '<h2>' . esc_html__( 'Explorers', 'ems-plugin' ) . '</h2>';

        if ( empty( $explorers ) ) {
            echo '<p>' . esc_html__( 'No explorers synced from OSM.', 'ems-plugin' ) . '</p>';
        } else {
            echo '<table class="widefat striped" style="width:100%;">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__( 'Scout ID', 'ems-plugin' ) . '</th>';
            echo '<th>' . esc_html__( 'Name', 'ems-plugin' ) . '</th>';
            echo '<th>' . esc_html__( 'Unit / Patrol', 'ems-plugin' ) . '</th>';
            echo '<th>' . esc_html__( 'Email', 'ems-plugin' ) . '</th>';
            echo '<th>' . esc_html__( 'Parent Email', 'ems-plugin' ) . '</th>';
            echo '</tr></thead><tbody>';
            foreach ( $explorers as $user ) {
                $scout_id = (int) get_user_meta( $user->ID, 'ems_scout_id', true );
                $first    = get_user_meta( $user->ID, 'ems_first_name', true );
                $last     = get_user_meta( $user->ID, 'ems_last_name', true );
                $unit     = get_user_meta( $user->ID, 'ems_unit', true );
                $email    = get_user_meta( $user->ID, 'ems_explorer_email', true );
                $pemail   = get_user_meta( $user->ID, 'ems_parent_email', true );

                echo '<tr>';
                echo '<td>' . esc_html( $scout_id ) . '</td>';
                echo '<td>' . esc_html( $first . ' ' . $last ) . '</td>';
                echo '<td>' . esc_html( $unit ?: '—' ) . '</td>';
                echo '<td>' . esc_html( $email ?: '—' ) . '</td>';
                echo '<td>' . esc_html( $pemail ?: '—' ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h2>' . esc_html__( 'Expeditions (OSM Events)', 'ems-plugin' ) . '</h2>';

        if ( empty( $expeditions ) ) {
            echo '<p>' . esc_html__( 'No expeditions found.', 'ems-plugin' ) . '</p>';
        } else {
            echo '<table class="widefat striped" style="width:100%;">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__( 'Code', 'ems-plugin' ) . '</th>';
            echo '<th>' . esc_html__( 'Title', 'ems-plugin' ) . '</th>';
            echo '<th>' . esc_html__( 'OSM Event ID', 'ems-plugin' ) . '</th>';
            echo '<th>' . esc_html__( 'Level', 'ems-plugin' ) . '</th>';
            echo '<th>' . esc_html__( 'Type', 'ems-plugin' ) . '</th>';
            echo '<th>' . esc_html__( 'Start Date', 'ems-plugin' ) . '</th>';
            echo '<th>' . esc_html__( 'End Date', 'ems-plugin' ) . '</th>';
            echo '<th>' . esc_html__( 'Location', 'ems-plugin' ) . '</th>';
            echo '<th>' . esc_html__( 'Status', 'ems-plugin' ) . '</th>';
            echo '</tr></thead><tbody>';
            foreach ( $expeditions as $exp ) {
                echo '<tr>';
                echo '<td>' . esc_html( $exp['ems_expedition_code'] ?: '—' ) . '</td>';
                echo '<td>' . esc_html( $exp['post_title'] ?: '—' ) . '</td>';
                echo '<td>' . esc_html( $exp['ems_osm_event_id'] ?: '—' ) . '</td>';
                echo '<td>' . esc_html( $exp['ems_level'] ?: '—' ) . '</td>';
                echo '<td>' . esc_html( $exp['ems_type'] ?: '—' ) . '</td>';
                echo '<td>' . esc_html( $exp['ems_start_date'] ?: '—' ) . '</td>';
                echo '<td>' . esc_html( $exp['ems_end_date'] ?: '—' ) . '</td>';
                echo '<td>' . esc_html( $exp['ems_location_name'] ?: '—' ) . '</td>';
                echo '<td>' . esc_html( $exp['ems_status'] ?: '—' ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '</div>';
    }
}
