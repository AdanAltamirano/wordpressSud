<?php
/**
 * Script to detect and fix duplicate WIDGET CONTENT in sidebars.
 * This is useful when you have multiple widgets with DIFFERENT IDs but the SAME CONTENT (e.g. text/html/image).
 * Upload this to your WordPress root directory and access it via browser.
 */

require_once('wp-load.php');

if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Access Denied. You must be an administrator to run this script.' );
}

// Helper to get widget instance settings
function get_widget_settings($id_base, $instance_id) {
    $all_instances = get_option('widget_' . $id_base);
    if ( isset($all_instances[$instance_id]) ) {
        return $all_instances[$instance_id];
    }
    return null;
}

// Helper to extract a "content signature" for comparison
function get_content_signature($id_base, $settings) {
    if ( ! $settings ) return null;

    // Normalize: Remove title as it might differ
    $sig = $settings;
    unset($sig['title']);

    // Specific logic per widget type
    if ( $id_base == 'text' && isset($settings['text']) ) {
        // For text widgets, compare the body text
        return md5( trim($settings['text']) );
    }
    if ( $id_base == 'custom_html' && isset($settings['content']) ) {
        // For custom HTML, compare the HTML content
        return md5( trim($settings['content']) );
    }
    if ( $id_base == 'media_image' && isset($settings['attachment_id']) ) {
        // For images, compare attachment ID
        return 'img_' . $settings['attachment_id'];
    }
    if ( $id_base == 'media_video' && isset($settings['src']) ) {
        // For videos, compare source URL
        return 'vid_' . md5($settings['src']);
    }

    // Fallback: serialize everything else (e.g. recent comments settings)
    return md5( serialize($sig) );
}

$sidebars_widgets = get_option('sidebars_widgets');
$action = isset($_GET['action']) ? $_GET['action'] : 'scan';

echo "<h1>Duplicate Content Detector</h1>";
echo "<p><a href='fix-sidebar-content-duplicates.php'>Re-scan</a></p>";

$duplicates_found = array();

// SCANNIG PHASE
foreach ($sidebars_widgets as $sidebar_id => $widgets) {
    if ( $sidebar_id == 'wp_inactive_widgets' || !is_array($widgets) || empty($widgets) ) continue;

    $signatures_seen = array();

    foreach ($widgets as $widget_id) {
        // Parse ID base and instance number (e.g. 'text-2' -> base='text', number='2')
        if ( preg_match( '/^(.+)-(\d+)$/', $widget_id, $matches ) ) {
            $id_base = $matches[1];
            $instance_id = $matches[2];

            $settings = get_widget_settings($id_base, $instance_id);
            if ( $settings ) {
                $sig = get_content_signature($id_base, $settings);

                if ( isset($signatures_seen[$sig]) ) {
                    // Duplicate found!
                    $original_id = $signatures_seen[$sig];
                    $duplicates_found[] = array(
                        'sidebar' => $sidebar_id,
                        'original' => $original_id,
                        'duplicate' => $widget_id,
                        'title_original' => isset($settings['title']) ? $settings['title'] : '(No Title)', // Warning: This might be from the DUPLICATE's settings, need original's? No, just showing duplicate's title is fine.
                        'type' => $id_base
                    );
                } else {
                    $signatures_seen[$sig] = $widget_id;
                }
            }
        }
    }
}

// DISPLAY PHASE
if ( empty($duplicates_found) ) {
    echo "<h2 style='color:green'>No content duplicates found!</h2>";
    echo "<p>All widgets in your sidebars seem to have unique content.</p>";
} else {
    echo "<h2 style='color:red'>Found " . count($duplicates_found) . " potential duplicates!</h2>";
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse; width:100%'>";
    echo "<tr style='background:#f0f0f0'><th>Sidebar</th><th>Original Widget</th><th>Duplicate Widget</th><th>Type</th><th>Action</th></tr>";

    foreach ($duplicates_found as $dupe) {
        echo "<tr>";
        echo "<td>{$dupe['sidebar']}</td>";
        echo "<td>{$dupe['original']}</td>";
        echo "<td><strong>{$dupe['duplicate']}</strong><br><small>Title: {$dupe['title_original']}</small></td>";
        echo "<td>{$dupe['type']}</td>";
        echo "<td style='color:red'>Will be removed</td>";
        echo "</tr>";
    }
    echo "</table>";

    if ( $action !== 'fix' ) {
        echo "<br><div style='background:#ffffee; padding:15px; border:1px solid orange'>";
        echo "<h3>Ready to fix?</h3>";
        echo "<p>This will remove the <strong>Duplicate Widget</strong> column from the sidebars, keeping the <strong>Original Widget</strong> (the first one found).</p>";
        echo "<a href='fix-sidebar-content-duplicates.php?action=fix' style='background:red; color:white; padding:10px 20px; text-decoration:none; font-weight:bold; border-radius:5px;'>FIX DUPLICATES NOW</a>";
        echo "</div>";
    }
}

// FIX PHASE
if ( $action === 'fix' && !empty($duplicates_found) ) {
    echo "<hr><h2>Applying Fixes...</h2>";

    $new_sidebars = $sidebars_widgets;
    $removed_count = 0;

    foreach ($duplicates_found as $dupe) {
        $sidebar = $dupe['sidebar'];
        $widget_to_remove = $dupe['duplicate'];

        // Find key in array
        $key = array_search($widget_to_remove, $new_sidebars[$sidebar]);
        if ( $key !== false ) {
            unset($new_sidebars[$sidebar][$key]);
            $removed_count++;
            echo "Removed $widget_to_remove from $sidebar.<br>";
        }
    }

    // Re-index arrays
    foreach ($new_sidebars as $sid => $w) {
        if ( is_array($w) ) {
            $new_sidebars[$sid] = array_values($w);
        }
    }

    update_option('sidebars_widgets', $new_sidebars);

    echo "<h3 style='color:green'>Success! Removed $removed_count duplicate widgets.</h3>";
    echo "<p><a href='check-sidebar-duplicates.php'>Run basic check</a> to confirm sidebar integrity.</p>";
}
?>
