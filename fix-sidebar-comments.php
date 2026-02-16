<?php
/**
 * Advanced script to find and restore missing comments widget in the sidebar.
 *
 * This script will:
 * 1. Check for approved comments (which is a prerequisite for display).
 * 2. Search the entire database for ANY widget configuration containing "Comentarios al sitio".
 *    - This helps identify if a specific theme widget (like td_block_social_counter) was used.
 * 3. Restore the correct widget to the sidebar.
 *
 * Upload to WordPress root and run via browser: http://your-site.com/fix-sidebar-comments.php
 */

require_once('wp-load.php');

if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Access Denied. You must be an administrator to run this script.' );
}

echo "<h1>Fix Sidebar Comments (Advanced Search)</h1>";

// 1. Check for Approved Comments
$approved_comments_count = get_comments(array(
    'status' => 'approve',
    'count' => true
));

echo "<h2>1. Checking Database for Comments</h2>";
if ($approved_comments_count == 0) {
    echo "<p style='color:red; font-size: 1.2em;'><strong>WARNING: No approved comments found (Count: 0).</strong></p>";
    echo "<p>The widget will likely be hidden until you have at least one approved comment.</p>";
} else {
    echo "<p style='color:green'>Found <strong>$approved_comments_count</strong> approved comments.</p>";
}

// 2. Search for the "Comentarios al sitio" widget configuration
echo "<h2>2. Searching Database for 'Comentarios al sitio' Widget</h2>";
global $wpdb;

// Search in wp_options for option_name like 'widget_%' and option_value like '%Comentarios al sitio%'
$results = $wpdb->get_results(
    "SELECT option_name, option_value FROM $wpdb->options
     WHERE option_name LIKE 'widget_%'
     AND option_value LIKE '%Comentarios al sitio%'"
);

$found_widget_type = '';
$found_instance_id = '';
$found_settings = array();

if ($results) {
    echo "Found potential matches in database:<br><ul>";
    foreach ($results as $row) {
        $option_name = $row->option_name;
        // Extract widget base ID (e.g., widget_recent-comments -> recent-comments)
        $base_id = substr($option_name, 7);

        $value = unserialize($row->option_value);
        if (is_array($value)) {
            foreach ($value as $key => $instance) {
                if (is_array($instance) && (
                    (isset($instance['title']) && stripos($instance['title'], 'Comentarios al sitio') !== false) ||
                    (isset($instance['custom_title']) && stripos($instance['custom_title'], 'Comentarios al sitio') !== false)
                )) {
                    echo "<li><strong>MATCH FOUND:</strong> Widget Type: <code>$base_id</code> | Instance ID: <code>$key</code> | Title: " . (isset($instance['title']) ? $instance['title'] : $instance['custom_title']) . "</li>";
                    $found_widget_type = $base_id;
                    $found_instance_id = $key;
                    $found_settings = $instance;
                    break 2; // Stop after first good match
                }
            }
        }
    }
    echo "</ul>";
} else {
    echo "<p>No existing widget configuration found with title 'Comentarios al sitio'.</p>";
}

// 3. Restore the widget
echo "<h2>3. Restoring Widget to Sidebar</h2>";

// Identify target sidebar
$sidebars_widgets = get_option('sidebars_widgets');
$target_sidebar_id = '';

// Priority sidebars for Newspaper theme
$priority_sidebars = array('td-default-sidebar', 'sidebar-1');

foreach ($priority_sidebars as $sid) {
    if (isset($sidebars_widgets[$sid])) {
        $target_sidebar_id = $sid;
        break;
    }
}
if (!$target_sidebar_id) {
    // Fallback to first active sidebar
    foreach ($sidebars_widgets as $id => $widgets) {
        if ($id != 'wp_inactive_widgets' && !empty($widgets)) {
            $target_sidebar_id = $id;
            break;
        }
    }
}

if (!$target_sidebar_id) {
    echo "<p style='color:red'>Error: No active sidebar found.</p>";
} else {
    echo "Target Sidebar: <strong>$target_sidebar_id</strong><br>";

    // Case A: Found an existing widget configuration in DB
    if ($found_widget_type && $found_instance_id) {
        echo "<p>Restoring existing widget: <strong>$found_widget_type-$found_instance_id</strong></p>";

        $widget_id_string = $found_widget_type . '-' . $found_instance_id;

        if (!isset($sidebars_widgets[$target_sidebar_id])) {
            $sidebars_widgets[$target_sidebar_id] = array();
        }

        if (in_array($widget_id_string, $sidebars_widgets[$target_sidebar_id])) {
            echo "<p style='color:blue'>Widget is already in the sidebar!</p>";
        } else {
            $sidebars_widgets[$target_sidebar_id][] = $widget_id_string;
            update_option('sidebars_widgets', $sidebars_widgets);
            echo "<strong style='color:green'>SUCCESS: Restored '$found_widget_type' widget to sidebar.</strong>";
        }
    }
    // Case B: No match, create new one
    else {
        echo "<p>No specific configuration found. Creating new widget...</p>";

        // 1. Check if 'td_block_recent_comments' (Newspaper Theme) is available
        $td_available = get_option('widget_td_block_recent_comments');
        if ($td_available !== false) {
             echo "<p>Found Newspaper Theme 'Recent Comments' block settings. Using this type.</p>";
             $widget_base = 'td_block_recent_comments';
        } else {
             echo "<p>Newspaper Theme specific block not found. Falling back to standard 'Recent Comments'.</p>";
             $widget_base = 'recent-comments';
        }

        $ops = get_option('widget_' . $widget_base);
        if (!is_array($ops)) $ops = array('_multiwidget' => 1);

        // Create new instance
        $next_id = 1;
        foreach (array_keys($ops) as $k) {
            if (is_numeric($k) && $k >= $next_id) $next_id = $k + 1;
        }

        // Configuration for the new widget
        if ($widget_base == 'td_block_recent_comments') {
            $ops[$next_id] = array(
                'custom_title' => 'Comentarios al sitio',
                'limit' => 5,
                'style' => 'style1' // Guessing style
            );
        } else {
            $ops[$next_id] = array(
                'title' => 'Comentarios al sitio',
                'number' => 5
            );
        }

        update_option('widget_' . $widget_base, $ops);

        $widget_id_string = $widget_base . '-' . $next_id;

        if (!isset($sidebars_widgets[$target_sidebar_id])) {
            $sidebars_widgets[$target_sidebar_id] = array();
        }

        $sidebars_widgets[$target_sidebar_id][] = $widget_id_string;
        update_option('sidebars_widgets', $sidebars_widgets);

        echo "<strong style='color:green'>SUCCESS: Created new '$widget_base' widget with title 'Comentarios al sitio'.</strong>";
    }
}
?>
