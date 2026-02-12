<?php
/**
 * Ver artículos específicos en Joomla
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$joomla = mysqli_init();
$joomla->options(MYSQLI_SET_CHARSET_NAME, 'utf8');
$joomla->real_connect('127.0.0.1', 'root', 'yue02', 'sudcalifornios', 3306);
if ($joomla->connect_error) die("Error Joomla: " . $joomla->connect_error);

echo "<h2>ARTÍCULOS ESPECÍFICOS EN JOOMLA</h2>";
echo "<style>body{font-family:Arial;font-size:12px;} pre{background:#f5f5f5;padding:10px;max-height:300px;overflow:auto;}</style>";

// IDs específicos de Joomla
$ids = [4733, 10161]; // CHURIDO, LA PALOMILLA del diccionario

// Buscar por nombre exacto
$nombres = [
    'Diccionario popular choyero: LA PALOMILLA',
    'Diccionario popular choyero: "CHURIDO"'
];

foreach ($nombres as $nombre) {
    echo "<h3>Buscar: '{$nombre}'</h3>";
    $nombre_safe = $joomla->real_escape_string($nombre);
    $result = $joomla->query("SELECT id, name, elements FROM jos_zoo_item WHERE name LIKE '%{$nombre_safe}%' OR name = '{$nombre_safe}' LIMIT 1");

    if ($row = $result->fetch_assoc()) {
        echo "<p><strong>ID Joomla:</strong> {$row['id']}</p>";
        echo "<p><strong>Nombre:</strong> {$row['name']}</p>";

        // Decodificar JSON
        $elements = json_decode($row['elements'], true);

        // Buscar imágenes en el JSON
        $found_images = [];
        array_walk_recursive($elements, function($value) use (&$found_images) {
            if (is_string($value)) {
                // Buscar imágenes en el valor
                if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/', $value, $imgs)) {
                    foreach ($imgs[1] as $img) {
                        $found_images[] = $img;
                    }
                }
                // También buscar URLs de imágenes directas
                if (preg_match_all('#(?:https?:)?//[^\s"\'<>]+\.(?:jpg|jpeg|png|gif)#i', $value, $urls)) {
                    foreach ($urls[0] as $url) {
                        $found_images[] = $url;
                    }
                }
                // Buscar rutas locales
                if (preg_match_all('#/images/[^\s"\'<>]+\.(?:jpg|jpeg|png|gif)#i', $value, $paths)) {
                    foreach ($paths[0] as $path) {
                        $found_images[] = $path;
                    }
                }
            }
        });

        if (!empty($found_images)) {
            echo "<p><strong>Imágenes encontradas:</strong></p><ul>";
            foreach (array_unique($found_images) as $img) {
                $tipo = strpos($img, 'data:image') === 0 ? '<span style="color:red;">BASE64</span>' :
                       (strpos($img, '/images/') === 0 ? '<span style="color:green;">LOCAL</span>' :
                       '<span style="color:blue;">EXTERNA</span>');
                $mostrar = strlen($img) > 80 ? substr($img, 0, 80) . '...' : $img;
                echo "<li>{$tipo}: {$mostrar}</li>";
            }
            echo "</ul>";
        } else {
            echo "<p style='color:orange;'>No se encontraron imágenes</p>";
        }

        echo "<details><summary>Ver JSON completo</summary><pre>" . htmlspecialchars(json_encode($elements, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre></details>";
    } else {
        echo "<p style='color:red;'>No encontrado</p>";
    }
    echo "<hr>";
}

// También buscar el ID 8723 que es "LA PALOMILLA" en WordPress
echo "<h3>Buscar artículo con 'palomilla' en diccionario:</h3>";
$result = $joomla->query("SELECT id, name, elements FROM jos_zoo_item WHERE name LIKE '%Diccionario%palomilla%' OR name LIKE '%palomilla%Diccionario%' LIMIT 3");
while ($row = $result->fetch_assoc()) {
    echo "<p>ID: {$row['id']} - {$row['name']}</p>";
}

$joomla->close();
?>
