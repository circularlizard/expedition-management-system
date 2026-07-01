<?php
/**
 * Diagnose Unit Lookup Database State
 * Run: docker compose run --rm wpcli eval-file mock_diagnose.php
 */

$parent_user = get_user_by( 'email', 'parent@example.com' );
if ( ! $parent_user ) {
    $users = get_users( [ 'role' => 'ems_parent' ] );
    $parent_user = ! empty( $users ) ? $users[0] : get_user_by( 'id', 1 );
}

echo "Parent User ID: " . ($parent_user ? $parent_user->ID : 'None') . "\n";
if ( $parent_user ) {
    $children = get_user_meta( $parent_user->ID, 'ems_children', true );
    echo "\nParent children meta (ems_children):\n";
    print_r( $children );

    if ( is_array( $children ) ) {
        global $wpdb;
        foreach ( $children as $child ) {
            $scout_id = (int) ( $child['scout_id'] ?? 0 );
            echo "\n--- Diagnosing Scout ID: $scout_id ---\n";
            
            // Query explorer row
            $explorer = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}ems_osm_explorers WHERE scout_id = $scout_id", ARRAY_A );
            echo "Explorer DB Row:\n";
            print_r( $explorer );
            
            $section_ids = ! empty( $explorer['section_id'] ) ? [ (int) $explorer['section_id'] ] : (array) ( $child['section_ids'] ?? [] );
            echo "Section IDs to query: " . implode( ', ', $section_ids ) . "\n";
            
            foreach ( $section_ids as $sec_id ) {
                $units = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ems_units WHERE section_id = $sec_id", ARRAY_A );
                echo "Units mapped to section_id $sec_id:\n";
                print_r( $units );
            }
        }
    }
}
