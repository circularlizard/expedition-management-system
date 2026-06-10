<?php
require_once('wordpress/wp-load.php');
global $wpdb;

echo "Listing all post types in wp_posts:\n";
$types = $wpdb->get_col("SELECT DISTINCT post_type FROM {$wpdb->posts}");
foreach ($types as $t) {
    $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", $t));
    echo "- $t ($count)\n";
}

echo "\nChecking for assignments and submissions:\n";
$assigns = $wpdb->get_results("SELECT ID, post_title, post_type FROM {$wpdb->posts} WHERE post_type LIKE '%assign%' LIMIT 10");
foreach ($assigns as $a) {
    echo "- ID {$a->ID}: {$a->post_title} ({$a->post_type})\n";
    $children = $wpdb->get_results($wpdb->prepare("SELECT ID, post_type, post_status, post_author FROM {$wpdb->posts} WHERE post_parent = %d", $a->ID));
    foreach ($children as $c) {
        echo "  -> Child ID {$c->ID}: type={$c->post_type}, status={$c->post_status}, author={$c->post_author}\n";
    }
}
