<?php
/**
 * Logic to analyze Joomla vs WordPress content status
 */

require_once 'migration-config.php';

// Only load WP if not already loaded
if (!defined('ABSPATH')) {
    require_once 'wp-load.php';
}

function get_migration_stats() {
    $stats = [
        'joomla_articles' => 0,
        'joomla_zoo_items' => 0,
        'wp_posts' => 0,
        'imported_zoo' => 0,
        'imported_content' => 0,
        'categories_zoo' => 0,
        'categories_wp' => 0
    ];

    // 1. Connect to Joomla
    $jdb = new mysqli(JOOMLA_DB_HOST, JOOMLA_DB_USER, JOOMLA_DB_PASS, JOOMLA_DB_NAME, JOOMLA_DB_PORT);
    if ($jdb->connect_error) {
        return ['error' => 'Connection to Joomla DB failed: ' . $jdb->connect_error];
    }
    $jdb->set_charset("utf8");

    // 2. Count Standard Articles
    $res = $jdb->query("SHOW TABLES LIKE 'jos_content'");
    if ($res && $res->num_rows > 0) {
        $q = "SELECT COUNT(*) as c FROM jos_content WHERE state = 1";
        $r = $jdb->query($q);
        if ($r) $stats['joomla_articles'] = $r->fetch_assoc()['c'];
    }

    // 3. Count Zoo Items
    $res = $jdb->query("SHOW TABLES LIKE 'jos_zoo_item'");
    if ($res && $res->num_rows > 0) {
        // Filter by the same types used in migration-worker.php
        $q = "SELECT COUNT(*) as c FROM jos_zoo_item WHERE state = 1 AND type IN ('article', 'blog-post', 'page')";
        $r = $jdb->query($q);
        if ($r) $stats['joomla_zoo_items'] = $r->fetch_assoc()['c'];

        // Count Categories
        $qc = "SELECT COUNT(*) as c FROM jos_zoo_category WHERE published = 1";
        $rc = $jdb->query($qc);
        if ($rc) $stats['categories_zoo'] = $rc->fetch_assoc()['c'];
    }

    $jdb->close();

    // 4. Count WordPress Posts
    global $wpdb;
    $stats['wp_posts'] = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'post' AND post_status = 'publish'");

    // 5. Count Imported Items (by meta key)
    $stats['imported_zoo'] = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key = '_joomla_zoo_id'");
    $stats['imported_content'] = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key = '_joomla_id'"); // Standard Joomla ID

    // 6. Count WP Categories
    $stats['categories_wp'] = wp_count_terms('category');

    return $stats;
}
?>
