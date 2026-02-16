<?php
/**
 * Script to restore the Recent Comments widget to the sidebar.
 * Upload this to your WordPress root directory and run it via browser.
 */

require_once('wp-load.php');

if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Access Denied. You must be an administrator to run this script.' );
}

echo "<h1>Restore Sidebar Comments</h1>";

// 1. Get current sidebars and widgets
$sidebars_widgets = get_option('sidebars_widgets');
$recent_comments_options = get_option('widget_recent-comments');

if ( ! $sidebars_widgets ) {
    die("Error: Could not retrieve sidebars_widgets option.");
}

// 2. Identify the target sidebar
$target_sidebar_id = '';
$priority_sidebars = array('td-default-sidebar', 'sidebar-1'); // Newspaper theme default, then WP default

foreach ($priority_sidebars as $sidebar_id) {
    if ( isset($sidebars_widgets[$sidebar_id]) ) {
        $target_sidebar_id = $sidebar_id;
        break;
    }
}

if ( ! $target_sidebar_id ) {
    // Fallback: find first active sidebar that is not 'wp_inactive_widgets'
    foreach ($sidebars_widgets as $id => $widgets) {
        if ( $id != 'wp_inactive_widgets' && is_array($widgets) && !empty($widgets) ) {
            $target_sidebar_id = $id;
            break;
        }
    }
}

if ( ! $target_sidebar_id ) {
    // If absolutely no active sidebars, fallback to 'sidebar-1' even if empty/missing
    $target_sidebar_id = 'sidebar-1';
}

echo "Target Sidebar: <strong>" . htmlspecialchars($target_sidebar_id) . "</strong><br>";

// 3. Check if Recent Comments widget already exists in the target sidebar
$found_existing = false;
$existing_widget_id = '';

if ( isset($sidebars_widgets[$target_sidebar_id]) && is_array($sidebars_widgets[$target_sidebar_id]) ) {
    foreach ($sidebars_widgets[$target_sidebar_id] as $widget_id) {
        if ( strpos($widget_id, 'recent-comments') !== false ) {
            $found_existing = true;
            $existing_widget_id = $widget_id;
            break;
        }
    }
}

if ( $found_existing ) {
    echo "<p style='color:orange'>A Recent Comments widget (ID: $existing_widget_id) already exists in this sidebar.</p>";

    // Check if the instance exists in options
    $instance_number = str_replace('recent-comments-', '', $existing_widget_id);
    if ( isset($recent_comments_options[$instance_number]) ) {
        echo "Widget instance #$instance_number found in settings. Title: " . $recent_comments_options[$instance_number]['title'] . "<br>";
        echo "It should be displaying correctly. If not, try removing it and running this script again.";
    } else {
        echo "<p style='color:red'>However, the settings for instance #$instance_number are missing from the database!</p>";
        echo "Attempting to repair instance #$instance_number...<br>";

        // Repair the missing instance
        if ( ! is_array($recent_comments_options) ) {
            $recent_comments_options = array( '_multiwidget' => 1 );
        }
        $recent_comments_options[$instance_number] = array(
            'title' => 'Comentarios Recientes',
            'number' => 5
        );
        update_option('widget_recent-comments', $recent_comments_options);
        echo "<strong style='color:green'>Repaired widget settings.</strong>";
    }
} else {
    echo "<p>No Recent Comments widget found in the sidebar. Adding one now...</p>";

    // 4. Create new widget instance
    if ( ! is_array($recent_comments_options) ) {
        $recent_comments_options = array( '_multiwidget' => 1 );
    }

    // Find next available ID
    $next_id = 2; // Start from 2 usually
    if ( ! empty($recent_comments_options) ) {
        foreach ( array_keys($recent_comments_options) as $key ) {
            if ( is_numeric($key) && $key >= $next_id ) {
                $next_id = $key + 1;
            }
        }
    }

    $recent_comments_options[$next_id] = array(
        'title' => 'Comentarios Recientes',
        'number' => 5
    );

    // Save widget options
    update_option('widget_recent-comments', $recent_comments_options);
    echo "Created new widget instance #$next_id.<br>";

    // 5. Add to sidebar
    if ( ! isset($sidebars_widgets[$target_sidebar_id]) || ! is_array($sidebars_widgets[$target_sidebar_id]) ) {
        $sidebars_widgets[$target_sidebar_id] = array();
    }

    $sidebars_widgets[$target_sidebar_id][] = 'recent-comments-' . $next_id;

    // Save sidebar configuration
    update_option('sidebars_widgets', $sidebars_widgets);

    echo "<strong style='color:green'>SUCCESS: Added 'Recent Comments' widget to $target_sidebar_id.</strong>";
}

echo "<hr><p>Please check your site to verify the comments section appears.</p>";
?>
