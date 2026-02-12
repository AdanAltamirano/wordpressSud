<?php
/**
 * Verificar tablas de Joomla
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$joomla = mysqli_init();
$joomla->options(MYSQLI_SET_CHARSET_NAME, 'utf8');
$joomla->real_connect('127.0.0.1', 'root', 'yue02', 'sudcalifornios', 3306);
if ($joomla->connect_error) die("Error Joomla: " . $joomla->connect_error);

echo "<h2>TABLAS EN BASE DE DATOS JOOMLA</h2>";

$result = $joomla->query("SHOW TABLES LIKE '%zoo%'");

echo "<h3>Tablas ZOO:</h3><ul>";
while ($row = $result->fetch_array()) {
    echo "<li>{$row[0]}</li>";
}
echo "</ul>";

$joomla->close();
?>
