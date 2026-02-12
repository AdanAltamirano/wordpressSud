<?php
/**
 * Script corregido para extraer descripciones de autores
 * La descripción puede estar en cualquier índice del array [0], [1], [2], etc.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);

echo "<h2>=== ARREGLAR AUTORES - VERSIÓN 2 ===</h2>";

// Conexión Joomla
$joomla = new mysqli('127.0.0.1', 'root', 'yue02', 'sudcalifornios', 3306);
if ($joomla->connect_error) die("Error Joomla: " . $joomla->connect_error);
$joomla->set_charset('utf8mb4');

// Conexión WordPress
$wordpress = mysqli_init();
$wordpress->options(MYSQLI_SET_CHARSET_NAME, 'utf8');
$wordpress->real_connect('127.0.0.1', 'wpuser', 'MiPassword123', 'wordpress', 3308);
if ($wordpress->connect_error) die("Error WordPress: " . $wordpress->connect_error);

// Procesar autores
$result = $joomla->query("
    SELECT id, name, alias, elements
    FROM jos_zoo_item
    WHERE type = 'author' AND state = 1
");

$stats = [
    'procesados' => 0,
    'con_descripcion' => 0,
    'con_imagen' => 0,
    'actualizados_desc' => 0,
    'actualizados_img' => 0,
    'no_encontrados' => 0
];

echo "<h3>Procesando autores...</h3>";

while ($row = $result->fetch_assoc()) {
    $stats['procesados']++;

    $elements = json_decode($row['elements'], true);
    if (!is_array($elements)) continue;

    $descripcion = '';
    $imagen = '';

    foreach ($elements as $uuid => $data) {
        // BUSCAR IMAGEN - en campo 'file' directamente
        if (is_array($data) && isset($data['file']) && !empty($data['file'])) {
            $imagen = $data['file'];
        }

        // BUSCAR DESCRIPCIÓN - puede ser un array con múltiples índices
        if (is_array($data)) {
            // Revisar cada índice del array (0, 1, 2, etc.)
            foreach ($data as $index => $item) {
                if (is_numeric($index) && is_array($item) && isset($item['value'])) {
                    $value = trim($item['value']);
                    // Buscar el texto más largo que tenga contenido real
                    $texto_limpio = trim(strip_tags($value));
                    if (strlen($texto_limpio) > 50 && strlen($texto_limpio) > strlen(strip_tags($descripcion))) {
                        $descripcion = $value;
                    }
                }
            }
        }
    }

    if (!empty($descripcion)) $stats['con_descripcion']++;
    if (!empty($imagen)) $stats['con_imagen']++;

    // Buscar usuario en WordPress
    $joomla_id = $row['id'];
    $wp_result = $wordpress->query("
        SELECT user_id FROM wp_usermeta
        WHERE meta_key = '_joomla_author_id' AND meta_value = '{$joomla_id}'
    ");

    if ($wp_row = $wp_result->fetch_assoc()) {
        $user_id = $wp_row['user_id'];

        // Guardar descripción
        if (!empty($descripcion)) {
            $desc_clean = strip_tags($descripcion, '<p><br><strong><em><a>');
            $desc_safe = $wordpress->real_escape_string($desc_clean);

            $check = $wordpress->query("SELECT umeta_id FROM wp_usermeta WHERE user_id = {$user_id} AND meta_key = 'description'");
            if ($check->num_rows > 0) {
                $wordpress->query("UPDATE wp_usermeta SET meta_value = '{$desc_safe}' WHERE user_id = {$user_id} AND meta_key = 'description'");
            } else {
                $wordpress->query("INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES ({$user_id}, 'description', '{$desc_safe}')");
            }
            $stats['actualizados_desc']++;
        }

        // Guardar imagen
        if (!empty($imagen)) {
            if (strpos($imagen, 'http') !== 0) {
                $imagen = 'https://www.sudcalifornios.com/' . ltrim($imagen, '/');
            }
            $imagen_safe = $wordpress->real_escape_string($imagen);

            $check = $wordpress->query("SELECT umeta_id FROM wp_usermeta WHERE user_id = {$user_id} AND meta_key = '_author_image'");
            if ($check->num_rows > 0) {
                $wordpress->query("UPDATE wp_usermeta SET meta_value = '{$imagen_safe}' WHERE user_id = {$user_id} AND meta_key = '_author_image'");
            } else {
                $wordpress->query("INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES ({$user_id}, '_author_image', '{$imagen_safe}')");
            }
            $stats['actualizados_img']++;
        }
    } else {
        $stats['no_encontrados']++;
    }
}

echo "<h3>RESULTADOS:</h3>";
echo "<ul>";
echo "<li>Autores procesados: <strong>{$stats['procesados']}</strong></li>";
echo "<li>Con descripción en Joomla: <strong>{$stats['con_descripcion']}</strong></li>";
echo "<li>Con imagen en Joomla: <strong>{$stats['con_imagen']}</strong></li>";
echo "<li>Descripciones actualizadas en WP: <strong>{$stats['actualizados_desc']}</strong></li>";
echo "<li>Imágenes actualizadas en WP: <strong>{$stats['actualizados_img']}</strong></li>";
echo "<li>No encontrados en WP: <strong>{$stats['no_encontrados']}</strong></li>";
echo "</ul>";

// Verificar Adrián Corona
echo "<h3>VERIFICACIÓN - Adrián Corona Ibarra:</h3>";

$wp_result = $wordpress->query("
    SELECT u.ID, u.display_name
    FROM wp_users u
    WHERE u.user_nicename = 'adrian-corona-ibarra'
");

if ($row = $wp_result->fetch_assoc()) {
    $user_id = $row['ID'];
    echo "<p>ID: {$user_id} | Nombre: {$row['display_name']}</p>";

    $desc = $wordpress->query("SELECT meta_value FROM wp_usermeta WHERE user_id = {$user_id} AND meta_key = 'description'");
    if ($d = $desc->fetch_assoc()) {
        echo "<p><strong>Descripción:</strong> " . htmlspecialchars(substr($d['meta_value'], 0, 200)) . "...</p>";
    } else {
        echo "<p style='color:red;'>Descripción: NO ENCONTRADA</p>";
    }

    $img = $wordpress->query("SELECT meta_value FROM wp_usermeta WHERE user_id = {$user_id} AND meta_key = '_author_image'");
    if ($i = $img->fetch_assoc()) {
        echo "<p><strong>Imagen:</strong> {$i['meta_value']}</p>";
    } else {
        echo "<p style='color:orange;'>Imagen: NO TIENE (normal, no todos tienen foto)</p>";
    }
}

// Mostrar algunos autores con descripción
echo "<h3>AUTORES CON DESCRIPCIÓN:</h3>";
echo "<table border='1' cellpadding='5' style='border-collapse:collapse;'>";
echo "<tr><th>ID</th><th>Nombre</th><th>Descripción (preview)</th><th>Imagen</th></tr>";

$wp_result = $wordpress->query("
    SELECT u.ID, u.display_name,
           (SELECT meta_value FROM wp_usermeta WHERE user_id = u.ID AND meta_key = 'description' LIMIT 1) as descripcion,
           (SELECT meta_value FROM wp_usermeta WHERE user_id = u.ID AND meta_key = '_author_image' LIMIT 1) as imagen
    FROM wp_users u
    INNER JOIN wp_usermeta um ON u.ID = um.user_id AND um.meta_key = '_joomla_author_id'
    HAVING descripcion IS NOT NULL AND descripcion != ''
    LIMIT 15
");

while ($row = $wp_result->fetch_assoc()) {
    $desc_preview = htmlspecialchars(substr(strip_tags($row['descripcion']), 0, 80)) . '...';
    $tiene_img = !empty($row['imagen']) ? '✓ Sí' : '✗ No';
    echo "<tr>";
    echo "<td>{$row['ID']}</td>";
    echo "<td>{$row['display_name']}</td>";
    echo "<td>{$desc_preview}</td>";
    echo "<td>{$tiene_img}</td>";
    echo "</tr>";
}
echo "</table>";

$joomla->close();
$wordpress->close();

echo "<h3 style='color:green;'>=== COMPLETADO ===</h3>";
echo "<p><strong>SIGUIENTE PASO:</strong> Agregar el código de <code>theme-author-functions.php</code> al archivo <code>functions.php</code> de tu tema de WordPress.</p>";
?>
