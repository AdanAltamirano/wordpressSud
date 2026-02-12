<?php
/**
 * Analizar estructura completa de elements de autores
 * Para entender dónde está la descripción
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$joomla = new mysqli('127.0.0.1', 'root', 'yue02', 'sudcalifornios', 3306);
if ($joomla->connect_error) die("Error: " . $joomla->connect_error);
$joomla->set_charset('utf8mb4');

echo "<h2>ANÁLISIS DETALLADO DE ELEMENTS DE AUTORES</h2>";

// Buscar un autor que SÍ tenga descripción visible en Joomla
// Por ejemplo, buscar "Adrián Corona Ibarra"
$result = $joomla->query("
    SELECT id, name, alias, elements
    FROM jos_zoo_item
    WHERE type = 'author' AND name LIKE '%Adrian%Corona%'
    LIMIT 1
");

if ($row = $result->fetch_assoc()) {
    echo "<h3>Autor: {$row['name']} (ID: {$row['id']})</h3>";
    echo "<h4>ELEMENTS COMPLETO (formateado):</h4>";

    $elements = json_decode($row['elements'], true);

    echo "<pre style='background:#f5f5f5; padding:15px; overflow:auto; max-height:600px;'>";

    foreach ($elements as $uuid => $data) {
        echo "<strong style='color:blue;'>UUID: {$uuid}</strong>\n";
        echo "Datos: " . print_r($data, true) . "\n";
        echo "-------------------------------------------\n";
    }

    echo "</pre>";
}

// Ahora buscar autores que SÍ tienen texto largo (probable descripción)
echo "<h3>AUTORES CON CONTENIDO DE TEXTO LARGO:</h3>";

$result = $joomla->query("
    SELECT id, name, elements
    FROM jos_zoo_item
    WHERE type = 'author' AND state = 1
    AND LENGTH(elements) > 500
    LIMIT 5
");

while ($row = $result->fetch_assoc()) {
    echo "<h4>{$row['name']}</h4>";
    $elements = json_decode($row['elements'], true);

    foreach ($elements as $uuid => $data) {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

        // Buscar campos con texto largo (más de 100 caracteres)
        if (strlen($json) > 100) {
            echo "<p><strong>UUID {$uuid}:</strong><br>";
            echo "<code style='word-break:break-all;'>" . htmlspecialchars(substr($json, 0, 500)) . "...</code></p>";
        }
    }
    echo "<hr>";
}

// Ver la estructura del tipo "author" en ZOO
echo "<h3>TIPOS DE CAMPOS EN ZOO (config):</h3>";

// Los tipos están en jos_zoo_application o en archivos
$result = $joomla->query("
    SELECT id, name, alias, params
    FROM jos_zoo_application
    LIMIT 5
");

echo "<pre>";
while ($row = $result->fetch_assoc()) {
    echo "App: {$row['name']} ({$row['alias']})\n";
}
echo "</pre>";

$joomla->close();
?>
