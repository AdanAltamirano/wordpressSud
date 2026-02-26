<?php
set_time_limit(120);
ini_set('memory_limit', '512M');
require_once('C:/inetpub/wwwroot/wordpress/wp-load.php');

$j = new mysqli('127.0.0.1', 'root', 'yue02', 'sudcalifornios', 3306);
$j->set_charset("utf8mb4");

global $wpdb;

// Build Joomla state map: zoo_id -> state
$joomla_states = [];
$jr = $j->query("SELECT id, state, name, created FROM jos_zoo_item WHERE type IN('article','page')");
while ($row = $jr->fetch_assoc()) {
    $joomla_states[$row['id']] = $row;
}

// Build WP map: zoo_id -> post data
$wp_posts = $wpdb->get_results("
    SELECT p.ID, p.post_title, p.post_status, p.post_date, pm.meta_value as zoo_id
    FROM $wpdb->posts p
    JOIN $wpdb->postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_joomla_zoo_id'
    WHERE p.post_type = 'post' AND p.post_status NOT IN('trash','inherit')
");

$status_map = ['1' => 'publish', '0' => 'draft', '2' => 'pending', '-1' => 'draft', '-2' => 'trash'];

$mismatch_pub_to_nopub = 0;
$mismatch_draft_to_pub = 0;
$date_future = 0;
$total_checked = 0;
$examples_pub = [];
$examples_draft = [];

foreach ($wp_posts as $wp) {
    if (!isset($joomla_states[$wp->zoo_id])) continue;
    $total_checked++;
    $jstate = $joomla_states[$wp->zoo_id]['state'];
    $expected_status = isset($status_map[$jstate]) ? $status_map[$jstate] : 'draft';

    // WP is publish but Joomla is NOT published
    if ($wp->post_status === 'publish' && $jstate != 1) {
        $mismatch_pub_to_nopub++;
        if (count($examples_pub) < 15) {
            $examples_pub[] = "WP:{$wp->ID} [{$wp->post_status}] Zoo:{$wp->zoo_id} [state={$jstate}] | " . mb_substr($wp->post_title, 0, 60);
        }
    }
    // WP is draft but Joomla IS published
    if ($wp->post_status === 'draft' && $jstate == 1) {
        $mismatch_draft_to_pub++;
        if (count($examples_draft) < 10) {
            $examples_draft[] = "WP:{$wp->ID} [{$wp->post_status}] Zoo:{$wp->zoo_id} [state={$jstate}] | " . mb_substr($wp->post_title, 0, 60);
        }
    }

    // Future dates
    if (strtotime($joomla_states[$wp->zoo_id]['created']) > time()) {
        $date_future++;
    }
}

echo "TOTAL VERIFICADOS: {$total_checked}\n\n";
echo "WP PUBLISH pero Joomla NO publicado (state!=1): {$mismatch_pub_to_nopub}\n";
echo "WP DRAFT pero Joomla SÍ publicado (state=1): {$mismatch_draft_to_pub}\n";
echo "Con fecha futura en Joomla: {$date_future}\n\n";

$dupes = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_joomla_zoo_id' WHERE p.post_type = 'post' AND p.ID > 60000");
echo "Posts re-creados por sync (ID>60000): {$dupes}\n\n";

if (!empty($examples_pub)) {
    echo "EJEMPLOS WP=publish, Joomla!=1:\n";
    foreach ($examples_pub as $ex) echo "  {$ex}\n";
}
if (!empty($examples_draft)) {
    echo "\nEJEMPLOS WP=draft, Joomla=1 (deberían ser publish):\n";
    foreach ($examples_draft as $ex) echo "  {$ex}\n";
}
