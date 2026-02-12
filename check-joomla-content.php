<?php
/**
 * Ver el contenido exacto de un artículo en Joomla
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$joomla = mysqli_init();
$joomla->options(MYSQLI_SET_CHARSET_NAME, 'utf8');
$joomla->real_connect('127.0.0.1', 'root', 'yue02', 'sudcalifornios', 3306);
if ($joomla->connect_error) die("Error Joomla: " . $joomla->connect_error);

echo "<h2>CONTENIDO DE ARTÍCULOS EN JOOMLA</h2>";
echo "<style>body{font-family:Arial;font-size:12px;} pre{background:#f5f5f5;padding:10px;max-height:400px;overflow:auto;white-space:pre-wrap;word-wrap:break-word;}</style>";

// Buscar artículos que SÍ tienen imágenes locales en Joomla
echo "<h3>Artículos con imágenes /images/ en Joomla:</h3>";
$result = $joomla->query("
    SELECT id, name, elements
    FROM jos_zoo_item
    WHERE elements LIKE '%/images/%'
    AND elements LIKE '%.jpg%' OR elements LIKE '%.png%'
    LIMIT 5
");

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "<h4>ID: {$row['id']} - {$row['name']}</h4>";

        // Extraer imágenes
        if (preg_match_all('#/images/[^"\'<>\s]+\.(?:jpg|jpeg|png|gif)#i', $row['elements'], $imgs)) {
            echo "<p>Imágenes encontradas:</p><ul>";
            foreach ($imgs[0] as $img) {
                echo "<li>{$img}</li>";
            }
            echo "</ul>";
        }
        echo "<hr>";
    }
} else {
    echo "<p>No se encontraron artículos con imágenes locales</p>";
}

// Buscar "LA PALOMILLA" específicamente
echo "<h3>Buscar 'LA PALOMILLA':</h3>";
$result = $joomla->query("SELECT id, name, elements FROM jos_zoo_item WHERE name LIKE '%PALOMILLA%' LIMIT 1");
if ($row = $result->fetch_assoc()) {
    echo "<p><strong>ID:</strong> {$row['id']} - <strong>Name:</strong> {$row['name']}</p>";

    // Ver si tiene imágenes
    if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/', $row['elements'], $imgs)) {
        echo "<p>Imágenes en el contenido:</p><ul>";
        foreach ($imgs[1] as $img) {
            $tipo = strpos($img, 'data:image') === 0 ? 'BASE64' : 'URL';
            $mostrar = $tipo == 'BASE64' ? substr($img, 0, 50) . '...' : $img;
            echo "<li><strong>{$tipo}:</strong> {$mostrar}</li>";
        }
        echo "</ul>";
    }

    echo "<details><summary>Ver elements completo</summary><pre>" . htmlspecialchars(substr($row['elements'], 0, 5000)) . "</pre></details>";
} else {
    echo "<p>No encontrado</p>";
}

// Buscar "CHURIDO"
echo "<h3>Buscar 'CHURIDO':</h3>";
$result = $joomla->query("SELECT id, name, elements FROM jos_zoo_item WHERE name LIKE '%CHURIDO%' LIMIT 1");
if ($row = $result->fetch_assoc()) {
    echo "<p><strong>ID:</strong> {$row['id']} - <strong>Name:</strong> {$row['name']}</p>";

    if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/', $row['elements'], $imgs)) {
        echo "<p>Imágenes en el contenido:</p><ul>";
        foreach ($imgs[1] as $img) {
            $tipo = strpos($img, 'data:image') === 0 ? 'BASE64' : 'URL';
            $mostrar = $tipo == 'BASE64' ? substr($img, 0, 50) . '...' : $img;
            echo "<li><strong>{$tipo}:</strong> {$mostrar}</li>";
        }
        echo "</ul>";
    }
} else {
    echo "<p>No encontrado</p>";
}

$joomla->close();
?>
