<?php
/**
 * Configuration for Joomla to WordPress Migration
 */

// Joomla Database Configuration
define('JOOMLA_DB_HOST', '127.0.0.1');
define('JOOMLA_DB_PORT', 3306);
define('JOOMLA_DB_USER', 'root');
define('JOOMLA_DB_PASS', 'yue02');
define('JOOMLA_DB_NAME', 'sudcalifornios');

// WordPress Database Configuration (Local)
define('WP_DB_HOST', '127.0.0.1');
define('WP_DB_PORT', 3308);
define('WP_DB_USER', 'wpuser');
define('WP_DB_PASS', 'MiPassword123');
define('WP_DB_NAME', 'wordpress');

// Local Paths
// Note: This path is where the Joomla files reside on the local Windows machine.
define('JOOMLA_LOCAL_PATH', 'C:/Sudcalifornios');

// Migration Settings
define('MIGRATION_BATCH_SIZE', 50); // Number of items to process per request
define('MIGRATION_UPLOAD_DIR', 'imported-content'); // Subfolder in wp-content/uploads

/**
 * Helper function to get Joomla DB Connection
 */
function get_joomla_connection() {
    $conn = new mysqli(JOOMLA_DB_HOST, JOOMLA_DB_USER, JOOMLA_DB_PASS, JOOMLA_DB_NAME, JOOMLA_DB_PORT);
    if ($conn->connect_error) {
        die("Joomla DB Connection Error: " . $conn->connect_error);
    }
    $conn->set_charset("utf8");
    return $conn;
}

/**
 * Helper function to get WordPress DB Connection
 * (Usually we use global $wpdb, but this is for direct access if needed)
 */
function get_wp_connection() {
    $conn = new mysqli(WP_DB_HOST, WP_DB_USER, WP_DB_PASS, WP_DB_NAME, WP_DB_PORT);
    if ($conn->connect_error) {
        die("WordPress DB Connection Error: " . $conn->connect_error);
    }
    $conn->set_charset("utf8mb4");
    return $conn;
}
?>
