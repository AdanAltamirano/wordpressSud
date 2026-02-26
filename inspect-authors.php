<?php
// inspect-authors.php
// Quick script to inspect the elements JSON of Zoo items with type='author'
// to identify where the author image is stored.

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . '/wp-load.php');

// Simple DB connection mimicking importation script
define('J_HOST', '127.0.0.1');
define('J_PORT', 3306);
define('J_USER', 'root');
define('J_PASS', 'yue02');
define('J_DB', 'sudcalifornios');

$conn = new mysqli(J_HOST, J_USER, J_PASS, J_DB, J_PORT);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

echo "<h1>Inspecting Zoo Authors</h1>";

// Get first 5 author items
$result = $conn->query("SELECT id, name, elements FROM jos_zoo_item WHERE type='author' LIMIT 5");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "<h3>Author: " . htmlspecialchars($row['name']) . " (ID: {$row['id']})</h3>";
        $data = json_decode($row['elements'], true);
        echo "<pre>" . print_r($data, true) . "</pre>";
        echo "<hr>";
    }
} else {
    echo "No authors found or query error: " . $conn->error;
}
