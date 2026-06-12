<?php
namespace EMS\Admin;

class Diagnostic_Panel {

    public function get_html( int $user_id ): string {
        $access_type = get_user_meta( $user_id, 'ems_access_type', true );

        if ( empty( $access_type ) || $access_type === 'local' ) {
            return '<p>' . esc_html__( 'No OSM account linked.', 'ems-plugin' ) . '</p>';
        }

        $section_ids = (array) get_user_meta( $user_id, 'ems_section_ids', true );
        $scout_ids   = (array) get_user_meta( $user_id, 'ems_scout_ids', true );

        $html  = '<dl class="ems-diagnostic">';
        $html .= '<dt>' . esc_html__( 'Access Type', 'ems-plugin' ) . '</dt>';
        $html .= '<dd>' . esc_html( $access_type ) . '</dd>';
        $html .= '<dt>' . esc_html__( 'Section IDs', 'ems-plugin' ) . '</dt>';
        $html .= '<dd>' . esc_html( implode( ', ', $section_ids ) ) . '</dd>';
        $html .= '<dt>' . esc_html__( 'Scout IDs', 'ems-plugin' ) . '</dt>';
        $html .= '<dd>' . esc_html( implode( ', ', $scout_ids ) ) . '</dd>';
        $html .= '</dl>';

        return $html;
    }

    public function render( int $user_id ): void {
        echo $this->get_html( $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}
