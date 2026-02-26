<?php
// inspect-authors.php - Standalone version without WP dependencies
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Database configuration
define('J_HOST', '127.0.0.1');
define('J_PORT', 3306);
define('J_USER', 'root');
define('J_PASS', 'yue02');
define('J_DB', 'sudcalifornios');

// Create connection
$conn = new mysqli(J_HOST, J_USER, J_PASS, J_DB, J_PORT);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

echo "<h1>Inspecting Zoo Authors (Standalone)</h1>\n";

// Get first 5 author items
$result = $conn->query("SELECT id, name, elements FROM jos_zoo_item WHERE type='author' LIMIT 5");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "<h3>Author: " . $row['name'] . " (ID: {$row['id']})</h3>\n";
        echo "Elements JSON:\n";
        $data = json_decode($row['elements'], true);
        print_r($data);
        echo "\n--------------------------------------------------\n";
    }
} else {
    echo "No authors found or query error: " . $conn->error . "\n";
}

$conn->close();
