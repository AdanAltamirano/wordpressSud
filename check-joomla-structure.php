<?php
/**
 * Verificar estructura de tablas Joomla ZOO
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$joomla = mysqli_init();
$joomla->options(MYSQLI_SET_CHARSET_NAME, 'utf8');
$joomla->real_connect('127.0.0.1', 'root', 'yue02', 'sudcalifornios', 3306);
if ($joomla->connect_error) die("Error Joomla: " . $joomla->connect_error);

echo "<h2>ESTRUCTURA JOOMLA ZOO</h2>";
echo "<style>body{font-family:Arial;font-size:12px;} pre{background:#f5f5f5;padding:10px;}</style>";

// Ver columnas de jos_zoo_item
echo "<h3>Columnas de jos_zoo_item:</h3>";
$result = $joomla->query("DESCRIBE jos_zoo_item");
echo "<ul>";
while ($row = $result->fetch_assoc()) {
    echo "<li><strong>{$row['Field']}</strong> - {$row['Type']}</li>";
}
echo "</ul>";

// Ver un ejemplo de item
echo "<h3>Ejemplo de item (primeros 2):</h3>";
$result = $joomla->query("SELECT id, name, type, params FROM jos_zoo_item WHERE type='article' LIMIT 2");
while ($row = $result->fetch_assoc()) {
    echo "<p><strong>ID:</strong> {$row['id']} - <strong>Name:</strong> {$row['name']}</p>";
    echo "<p><strong>Type:</strong> {$row['type']}</p>";
    echo "<details><summary>Ver params</summary><pre>" . htmlspecialchars(substr($row['params'], 0, 2000)) . "</pre></details>";
    echo "<hr>";
}

// Buscar donde está el contenido con imágenes
echo "<h3>Buscar contenido con imágenes (base64_converted):</h3>";
$result = $joomla->query("SELECT id, name FROM jos_zoo_item WHERE params LIKE '%base64_converted%' LIMIT 5");
if ($result->num_rows > 0) {
    echo "<p>Encontrados en params:</p><ul>";
    while ($row = $result->fetch_assoc()) {
        echo "<li>ID: {$row['id']} - {$row['name']}</li>";
    }
    echo "</ul>";
} else {
    echo "<p>No encontrado en params</p>";
}

// Verificar otras tablas
echo "<h3>Todas las tablas con 'element':</h3>";
$result = $joomla->query("SHOW TABLES LIKE '%element%'");
if ($result->num_rows > 0) {
    echo "<ul>";
    while ($row = $result->fetch_array()) {
        echo "<li>{$row[0]}</li>";
    }
    echo "</ul>";
} else {
    echo "<p>No hay tablas con 'element'</p>";
}

$joomla->close();
?>
