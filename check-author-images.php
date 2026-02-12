<?php
/**
 * Verificar rutas de imágenes de autores
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$joomla = new mysqli('127.0.0.1', 'root', 'yue02', 'sudcalifornios', 3306);
if ($joomla->connect_error) die("Error: " . $joomla->connect_error);
$joomla->set_charset('utf8mb4');

$wordpress = mysqli_init();
$wordpress->options(MYSQLI_SET_CHARSET_NAME, 'utf8');
$wordpress->real_connect('127.0.0.1', 'wpuser', 'MiPassword123', 'wordpress', 3308);

echo "<h2>VERIFICACIÓN DE IMÁGENES DE AUTORES</h2>";

// Buscar Andrea M. Geiger en Joomla
echo "<h3>1. ANDREA GEIGER EN JOOMLA:</h3>";
$result = $joomla->query("
    SELECT id, name, elements
    FROM jos_zoo_item
    WHERE type = 'author' AND name LIKE '%Andrea%Geiger%'
");

while ($row = $result->fetch_assoc()) {
    echo "<p><strong>{$row['name']} (ID: {$row['id']})</strong></p>";

    $elements = json_decode($row['elements'], true);

    echo "<pre style='background:#f5f5f5; padding:10px; overflow:auto;'>";
    foreach ($elements as $uuid => $data) {
        // Buscar campo con 'file'
        if (is_array($data) && isset($data['file']) && !empty($data['file'])) {
            echo "UUID: {$uuid}\n";
            echo "FILE: {$data['file']}\n";
            echo "---\n";
        }
    }
    echo "</pre>";
}

// Verificar en WordPress
echo "<h3>2. ANDREA GEIGER EN WORDPRESS:</h3>";
$result = $wordpress->query("
    SELECT u.ID, u.display_name,
           (SELECT meta_value FROM wp_usermeta WHERE user_id = u.ID AND meta_key = '_author_image') as imagen
    FROM wp_users u
    WHERE u.display_name LIKE '%Andrea%Geiger%'
");

while ($row = $result->fetch_assoc()) {
    echo "<p><strong>{$row['display_name']} (ID: {$row['ID']})</strong></p>";
    echo "<p>Imagen guardada: <code>{$row['imagen']}</code></p>";
    if (!empty($row['imagen'])) {
        echo "<p><img src='{$row['imagen']}' style='max-width:150px;' /></p>";
    }
}

// Mostrar todos los autores con imagen en Joomla
echo "<h3>3. TODOS LOS AUTORES CON IMAGEN EN JOOMLA:</h3>";
echo "<table border='1' cellpadding='5' style='border-collapse:collapse;'>";
echo "<tr><th>ID</th><th>Nombre</th><th>Ruta imagen en Joomla</th></tr>";

$result = $joomla->query("
    SELECT id, name, elements
    FROM jos_zoo_item
    WHERE type = 'author' AND state = 1
");

$count = 0;
while ($row = $result->fetch_assoc()) {
    $elements = json_decode($row['elements'], true);

    foreach ($elements as $uuid => $data) {
        if (is_array($data) && isset($data['file']) && !empty($data['file'])) {
            $count++;
            $file = htmlspecialchars($data['file']);
            echo "<tr><td>{$row['id']}</td><td>{$row['name']}</td><td><code>{$file}</code></td></tr>";
            break;
        }
    }
}
echo "</table>";
echo "<p>Total autores con imagen: <strong>{$count}</strong></p>";

$joomla->close();
$wordpress->close();
?>
