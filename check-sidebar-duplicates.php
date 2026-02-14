<?php
/**
 * Script to check for duplicate widgets in sidebars.
 * Upload this file to your WordPress root directory and access it via browser.
 */

require_once('wp-load.php');

if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Access Denied. You must be an administrator to run this script.' );
}

$sidebars_widgets = get_option('sidebars_widgets');

echo "<h1>Sidebar Widget Check</h1>";

if (!$sidebars_widgets) {
    echo "Could not retrieve sidebars_widgets option.";
    exit;
}

$found_duplicates = false;

foreach ($sidebars_widgets as $sidebar_id => $widgets) {
    if ($sidebar_id == 'wp_inactive_widgets' || !is_array($widgets)) {
        continue;
    }

    echo "<h2>Sidebar: $sidebar_id</h2>";

    $counts = array_count_values($widgets);
    $duplicates = array_filter($counts, function($count) {
        return $count > 1;
    });

    if (!empty($duplicates)) {
        $found_duplicates = true;
        echo "<p style='color:red'><strong>Duplicates found:</strong></p>";
        echo "<ul>";
        foreach ($duplicates as $widget_id => $count) {
            echo "<li>$widget_id (appears $count times)</li>";
        }
        echo "</ul>";
    } else {
        echo "<p style='color:green'>No duplicates found.</p>";
    }
}

if ($found_duplicates) {
    echo "<hr><h3>Summary</h3>";
    echo "<p>Duplicates were found. You can run <code>fix-sidebar-duplicates.php</code> to clean them up.</p>";
} else {
    echo "<hr><h3>Summary</h3>";
    echo "<p>No duplicate widgets found in sidebars.</p>";
}
?>
