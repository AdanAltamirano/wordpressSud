<?php
/**
 * Advanced script to fix missing comments in the sidebar.
 * It searches for the "Comentarios al Sitio" widget to identify the correct sidebar,
 * checks for approved comments, and ensures the "Recent Comments" widget is present.
 *
 * Upload this to your WordPress root directory and run it via browser:
 * http://your-site.com/fix-sidebar-comments.php
 */

require_once('wp-load.php');

if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Access Denied. You must be an administrator to run this script.' );
}

echo "<h1>Fix Sidebar Comments</h1>";

// 1. Check for Approved Comments
$approved_comments_count = get_comments(array(
    'status' => 'approve',
    'count' => true
));

echo "<h2>1. Checking Database for Comments</h2>";
if ($approved_comments_count == 0) {
    echo "<p style='color:red; font-size: 1.2em;'><strong>WARNING: No approved comments found in the database (Count: 0).</strong></p>";
    echo "<p>The 'Recent Comments' widget automatically hides itself when there are no comments to display. This is likely why you don't see anything.</p>";
    echo "<p><strong>Recommendation:</strong> Create a test comment on any post and approve it, then refresh the page.</p>";
} else {
    echo "<p style='color:green'>Found <strong>$approved_comments_count</strong> approved comments. Proceeding...</p>";
}

// 2. Identify the Target Sidebar by searching for "Comentarios al Sitio"
echo "<h2>2. Identifying the Correct Sidebar</h2>";
$sidebars_widgets = get_option('sidebars_widgets');
$registered_sidebars = $GLOBALS['wp_registered_sidebars'];
$target_sidebar_id = '';
$found_marker = false;

// Helper to get widget instance data
function get_widget_instance($base_id, $number) {
    $options = get_option("widget_{$base_id}");
    if (isset($options[$number])) {
        return $options[$number];
    }
    return false;
}

// Scan all sidebars
foreach ($sidebars_widgets as $sidebar_id => $widgets) {
    if (!isset($registered_sidebars[$sidebar_id])) continue; // Skip inactive/unregistered
    if (!is_array($widgets)) continue;

    foreach ($widgets as $widget_id) {
        // Parse widget ID (e.g., text-5)
        if (preg_match('/^([a-z0-9_-]+)-(\d+)$/', $widget_id, $matches)) {
            $base_id = $matches[1];
            $number = $matches[2];
            $instance = get_widget_instance($base_id, $number);

            if ($instance) {
                // Check Title or Text for "Comentarios al Sitio" or "Ver comentarios"
                $title = isset($instance['title']) ? $instance['title'] : '';
                $text = isset($instance['text']) ? $instance['text'] : ''; // For text widgets

                if (stripos($title, 'Comentarios al Sitio') !== false || stripos($text, 'Ver comentarios') !== false) {
                    echo "<p style='color:green'>Found likely target widget: <strong>$widget_id</strong> (Title: '$title') in sidebar: <strong>{$registered_sidebars[$sidebar_id]['name']} ($sidebar_id)</strong></p>";
                    $target_sidebar_id = $sidebar_id;
                    $found_marker = true;
                    break 2; // Found it, break both loops
                }
            }
        }
    }
}

if (!$target_sidebar_id) {
    echo "<p style='color:orange'>Could not find a widget with 'Comentarios al Sitio'. Falling back to default detection.</p>";
    // Fallback logic
    if (isset($sidebars_widgets['td-default-sidebar'])) {
        $target_sidebar_id = 'td-default-sidebar';
    } elseif (isset($sidebars_widgets['sidebar-1'])) {
        $target_sidebar_id = 'sidebar-1';
    } else {
        // Just pick the first active one
        foreach ($sidebars_widgets as $id => $w) {
            if ($id != 'wp_inactive_widgets' && !empty($w)) {
                $target_sidebar_id = $id;
                break;
            }
        }
    }
}

echo "Target Sidebar for Fix: <strong>$target_sidebar_id</strong><br>";

// 3. Add Recent Comments Widget
echo "<h2>3. Restoring Recent Comments Widget</h2>";

if (!$target_sidebar_id) {
    echo "<p style='color:red'>Error: No valid sidebar found to add the widget to.</p>";
} else {
    // Check if Recent Comments is already there
    $already_exists = false;
    $existing_widget_id = '';

    if (isset($sidebars_widgets[$target_sidebar_id])) {
        foreach ($sidebars_widgets[$target_sidebar_id] as $widget_id) {
            if (strpos($widget_id, 'recent-comments') !== false) {
                $already_exists = true;
                $existing_widget_id = $widget_id;
                break;
            }
        }
    }

    if ($already_exists) {
        echo "<p style='color:blue'>Recent Comments widget ($existing_widget_id) is already in $target_sidebar_id.</p>";

        // Check configuration
        $parts = explode('-', $existing_widget_id);
        $number = end($parts);
        $opts = get_option('widget_recent-comments');
        if (!isset($opts[$number])) {
            echo "<p style='color:red'>Widget settings missing. Repairing...</p>";
            if (!is_array($opts)) $opts = array('_multiwidget' => 1);
            $opts[$number] = array('title' => 'Comentarios Recientes', 'number' => 5);
            update_option('widget_recent-comments', $opts);
            echo "Repaired.";
        } else {
            echo "Widget settings are valid (Title: " . $opts[$number]['title'] . ").";
        }

    } else {
        echo "<p>Adding new Recent Comments widget to $target_sidebar_id...</p>";

        $ops = get_option('widget_recent-comments');
        if (!is_array($ops)) $ops = array('_multiwidget' => 1);

        // Find next ID
        $next_id = 1;
        foreach (array_keys($ops) as $k) {
            if (is_numeric($k) && $k >= $next_id) $next_id = $k + 1;
        }

        $ops[$next_id] = array('title' => 'Comentarios Recientes', 'number' => 5);
        update_option('widget_recent-comments', $ops);

        if (!isset($sidebars_widgets[$target_sidebar_id])) {
            $sidebars_widgets[$target_sidebar_id] = array();
        }
        $sidebars_widgets[$target_sidebar_id][] = 'recent-comments-' . $next_id;
        update_option('sidebars_widgets', $sidebars_widgets);

        echo "<strong style='color:green'>SUCCESS: Added 'Recent Comments' widget to $target_sidebar_id.</strong>";
    }
}

echo "<h2>4. Final Verification</h2>";
echo "<p>Please verify on the frontend. If you still don't see comments:</p>";
echo "<ul>";
echo "<li>Ensure you have <strong>approved comments</strong> (Step 1).</li>";
echo "<li>Check if the theme has a specific setting to hide comments.</li>";
echo "<li>If you see the widget title but no comments list, it confirms the 'no comments' issue.</li>";
echo "</ul>";
?>
