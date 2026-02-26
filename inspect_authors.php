<?php
require_once(__DIR__ . '/wp-load.php');
require_once(__DIR__ . '/importar-total.php'); // For DB connection

function inspect_authors() {
    $j = jdb();
    echo "<h1>Zoo Author Items Inspection</h1>";

    // Get 5 author items
    $rows = $j->query("SELECT id, name, elements FROM jos_zoo_item WHERE type='author' LIMIT 5");

    while ($r = $rows->fetch_assoc()) {
        echo "<h3>Author: " . htmlspecialchars($r['name']) . " (ID: {$r['id']})</h3>";
        echo "<pre>";
        $data = json_decode($r['elements'], true);
        print_r($data);
        echo "</pre>";
        echo "<hr>";
    }
}

inspect_authors();
