<?php
require_once __DIR__ . '/../wordpress/wp-load.php';

global $wpdb;

echo "=== Current User ID 13 metadata ===\n";
$access_type = get_user_meta( 13, 'ems_access_type', true );
$children = get_user_meta( 13, 'ems_children', true );
echo "Access Type: " . print_r($access_type, true) . "\n";
echo "Children Meta: " . print_r($children, true) . "\n";

echo "=== Resolving units for children ===\n";
if ( is_array( $children ) ) {
	foreach ( $children as $child ) {
		$scout_id = $child['scout_id'];
		$section_ids = (array) ( $child['section_ids'] ?? array() );
		echo "Child: {$child['first_name']} {$child['last_name']} (Scout ID: $scout_id)\n";
		foreach ( $section_ids as $sec_id ) {
			$unit = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT short_code, unit_id, name, leader_email FROM {$wpdb->prefix}ems_units WHERE unit_id = %d AND active = 1 LIMIT 1",
					$sec_id
				),
				ARRAY_A
			);
			echo "  - Checking section ID $sec_id -> " . ($unit ? "MATCHED: short_code={$unit['short_code']}, name={$unit['name']}, leader_email={$unit['leader_email']}" : "NO MATCH") . "\n";
		}
	}
}

echo "=== All rows in ems_units table ===\n";
$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ems_units", ARRAY_A );
print_r( $rows );
