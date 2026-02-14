<?php
/**
 * Script to fix duplicate widgets in sidebars by removing exact duplicates from sidebars_widgets array.
 * Upload this file to your WordPress root directory and access it via browser.
 */

require_once('wp-load.php');

if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Access Denied. You must be an administrator to run this script.' );
}

$sidebars_widgets = get_option('sidebars_widgets');

echo "<h1>Sidebar Duplicate Fix v1.1</h1>";

if (!$sidebars_widgets) {
    echo "Could not retrieve sidebars_widgets option.";
    exit;
}

$updated = false;
$new_sidebars = array();

foreach ($sidebars_widgets as $sidebar_id => $widgets) {
    // Only process array sidebars (and optionally exclude inactive ones if desired)
    // Here we include inactive ones just in case.
    if (!is_array($widgets)) {
        $new_sidebars[$sidebar_id] = $widgets;
        continue;
    }

    echo "<h2>Sidebar: $sidebar_id</h2>";
    echo "Original: " . count($widgets) . " items<br>";

    // Remove duplicates while preserving order
    $unique_widgets = array_unique($widgets);

    // Check if any change occurred
    if (count($unique_widgets) !== count($widgets)) {
        $diff = count($widgets) - count($unique_widgets);
        echo "<strong style='color:green'>Removed $diff duplicates.</strong><br>";

        // Show what was removed (approx)
        $counts = array_count_values($widgets);
        $dupes = array_filter($counts, function($n) { return $n > 1; });
        echo "Duplicates were: " . implode(', ', array_keys($dupes)) . "<br>";

        $new_sidebars[$sidebar_id] = array_values($unique_widgets);
        $updated = true;
    } else {
        echo "No duplicates.<br>";
        $new_sidebars[$sidebar_id] = $widgets;
    }
}

if ($updated) {
    echo "<hr><h3>Applying Changes...</h3>";
    update_option('sidebars_widgets', $new_sidebars);
    echo "<strong style='color:green'>SUCCESS: Sidebars updated!</strong>";
} else {
    echo "<hr><h3>No Changes Needed</h3>";
    echo "No duplicate widgets found to remove.";
}
?>
