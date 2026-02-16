<?php
/**
 * Advanced script to diagnose and fix missing comments widget in the sidebar.
 *
 * This script will:
 * 1. Check for approved comments and verify they are attached to PUBLISHED posts.
 * 2. Search for existing widget configurations.
 * 3. Diagnose why the widget might be empty or missing.
 * 4. Offer to recreate the widget with correct settings.
 *
 * Usage: Upload to WordPress root and run via browser: http://your-site.com/fix-sidebar-comments.php
 */

require_once('wp-load.php');

if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Access Denied. You must be an administrator to run this script.' );
}

echo "<h1>Sidebar Comments Diagnostics & Fixer</h1>";
echo "<p><a href='fix-sidebar-comments.php'>Refresh Page</a> | <a href='fix-sidebar-comments.php?action=force_recreate'>Force Recreate Widget</a></p>";

// -------------------------------------------------------------------------
// 1. Check for Approved Comments and Post Status
// -------------------------------------------------------------------------
echo "<h2>1. Checking Comments Database</h2>";

$all_approved = get_comments(array(
    'status' => 'approve',
    'count' => true
));

if ($all_approved == 0) {
    echo "<p style='color:red; font-size: 1.2em;'><strong>CRITICAL: No approved comments found (Count: 0).</strong></p>";
    echo "<p>The widget will be hidden until you have at least one approved comment.</p>";
} else {
    echo "<p style='color:green'>Found <strong>$all_approved</strong> approved comments in total.</p>";

    // Check specific validity of the latest 10 comments
    $recent_comments = get_comments(array(
        'status' => 'approve',
        'number' => 10,
        'orderby' => 'comment_date_gmt',
        'order' => 'DESC'
    ));

    $valid_count = 0;
    $orphaned_count = 0;

    echo "<table border='1' cellpadding='5' style='border-collapse:collapse; width:100%;'>";
    echo "<tr><th>Comment ID</th><th>Author</th><th>Post ID</th><th>Post Status</th><th>Verdict</th></tr>";

    foreach ($recent_comments as $c) {
        $post_status = get_post_status($c->comment_post_ID);
        $verdict = ($post_status === 'publish') ? "<span style='color:green'>Valid</span>" : "<span style='color:red'>Orphaned/Hidden</span>";

        echo "<tr>";
        echo "<td>{$c->comment_ID}</td>";
        echo "<td>{$c->comment_author}</td>";
        echo "<td>{$c->comment_post_ID}</td>";
        echo "<td>" . ($post_status ? $post_status : '<em>Non-existent</em>') . "</td>";
        echo "<td>$verdict</td>";
        echo "</tr>";

        if ($post_status === 'publish') {
            $valid_count++;
        } else {
            $orphaned_count++;
        }
    }
    echo "</table>";

    if ($valid_count == 0) {
        echo "<p style='color:red; font-weight:bold;'>WARNING: The latest 10 comments are not attached to PUBLISHED posts. The widget may appear empty or be hidden.</p>";
        echo "<p>Ensure that the posts associated with these comments exist and are published.</p>";
    } else {
        echo "<p style='color:green;'>Found $valid_count valid comments attached to published posts. The widget should verify visible.</p>";
    }
}

// -------------------------------------------------------------------------
// 2. Search for Existing Widget Configuration
// -------------------------------------------------------------------------
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
        $base_id = substr($option_name, 7); // remove 'widget_'

        $value = unserialize($row->option_value);
        if (is_array($value)) {
            foreach ($value as $key => $instance) {
                if (is_array($instance) && (
                    (isset($instance['title']) && stripos($instance['title'], 'Comentarios al sitio') !== false) ||
                    (isset($instance['custom_title']) && stripos($instance['custom_title'], 'Comentarios al sitio') !== false)
                )) {
                    echo "<li><strong>MATCH FOUND:</strong> Widget Type: <code>$base_id</code> | Instance ID: <code>$key</code> | Settings: " . print_r($instance, true) . "</li>";
                    $found_widget_type = $base_id;
                    $found_instance_id = $key;
                    $found_settings = $instance;
                    // Keep searching to show all duplicates if any
                }
            }
        }
    }
    echo "</ul>";
} else {
    echo "<p>No existing widget configuration found with title 'Comentarios al sitio'.</p>";
}

// -------------------------------------------------------------------------
// 3. Check Active Sidebars
// -------------------------------------------------------------------------
echo "<h2>3. Checking Sidebar Status</h2>";
$sidebars_widgets = get_option('sidebars_widgets');
$target_sidebar_id = '';
$priority_sidebars = array('td-default-sidebar', 'sidebar-1');

// Determine target sidebar
foreach ($priority_sidebars as $sid) {
    if (isset($sidebars_widgets[$sid])) {
        $target_sidebar_id = $sid;
        break;
    }
}
if (!$target_sidebar_id) {
    foreach ($sidebars_widgets as $id => $widgets) {
        if ($id != 'wp_inactive_widgets' && !empty($widgets)) {
            $target_sidebar_id = $id;
            break;
        }
    }
}

if (!$target_sidebar_id) {
    echo "<p style='color:red'>Error: No active sidebar found to inspect.</p>";
} else {
    echo "Target Sidebar: <strong>$target_sidebar_id</strong><br>";
    $current_widgets = isset($sidebars_widgets[$target_sidebar_id]) ? $sidebars_widgets[$target_sidebar_id] : array();

    echo "Current widgets in this sidebar: <ul>";
    $widget_is_present = false;
    foreach ($current_widgets as $wid) {
        echo "<li>$wid</li>";
        // Check if this matches our found widget
        if ($found_widget_type && $found_instance_id && $wid == "$found_widget_type-$found_instance_id") {
            $widget_is_present = true;
            echo " ^-- <strong>This is the target widget!</strong>";
        }
    }
    echo "</ul>";

    if ($widget_is_present) {
        echo "<p style='color:green'>The widget is correctly placed in the sidebar.</p>";
    } else {
        echo "<p style='color:orange'>The widget is NOT currently in this sidebar.</p>";
    }
}

// -------------------------------------------------------------------------
// 4. Action: Restore or Recreate
// -------------------------------------------------------------------------
echo "<h2>4. Actions</h2>";

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action == 'force_recreate') {
    echo "<p>Forcing recreation of the widget...</p>";

    // Determine widget type
    $td_available = get_option('widget_td_block_recent_comments');
    $widget_base = ($td_available !== false) ? 'td_block_recent_comments' : 'recent-comments';

    echo "Using widget type: <strong>$widget_base</strong><br>";

    $ops = get_option('widget_' . $widget_base);
    if (!is_array($ops)) $ops = array('_multiwidget' => 1);

    // Find next ID
    $next_id = 1;
    foreach (array_keys($ops) as $k) {
        if (is_numeric($k) && $k >= $next_id) $next_id = $k + 1;
    }

    // Configure
    if ($widget_base == 'td_block_recent_comments') {
        $ops[$next_id] = array(
            'custom_title' => 'Comentarios al sitio',
            'limit' => 5,
            'style' => 'style1' // Default style
        );
    } else {
        $ops[$next_id] = array(
            'title' => 'Comentarios al sitio',
            'number' => 5
        );
    }

    update_option('widget_' . $widget_base, $ops);
    $widget_id_string = $widget_base . '-' . $next_id;

    // Add to sidebar
    if (!isset($sidebars_widgets[$target_sidebar_id])) {
        $sidebars_widgets[$target_sidebar_id] = array();
    }
    $sidebars_widgets[$target_sidebar_id][] = $widget_id_string;
    update_option('sidebars_widgets', $sidebars_widgets);

    echo "<strong style='color:green'>SUCCESS: Created and added new widget '$widget_id_string'. Refresh page to see results.</strong>";

} elseif (!$widget_is_present && $target_sidebar_id) {
    // Attempt auto-restore if not present
    if ($found_widget_type && $found_instance_id) {
        echo "<p>Restoring existing widget config found in database...</p>";
        $widget_id_string = $found_widget_type . '-' . $found_instance_id;
        $sidebars_widgets[$target_sidebar_id][] = $widget_id_string;
        update_option('sidebars_widgets', $sidebars_widgets);
        echo "<strong style='color:green'>SUCCESS: Restored '$widget_id_string' to sidebar.</strong>";
    } else {
        echo "<p>No widget found. <a href='?action=force_recreate' class='button'>Create New Widget</a></p>";
    }
} else {
    echo "<p>Widget seems to be present. If it's still empty, check the diagnostics in section 1.</p>";
    echo "<p><a href='?action=force_recreate'>Click here to Force Re-create a new instance</a> (useful if the old one is corrupted)</p>";
}
?>
