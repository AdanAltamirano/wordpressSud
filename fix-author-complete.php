<?php
/**
 * Script completo para arreglar autores en WordPress
 * - Extraer descripciones de Joomla
 * - Extraer imágenes de perfil
 * - Guardar en WordPress usermeta
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);

echo "=== ARREGLAR AUTORES COMPLETO ===\n\n";

// Conexión Joomla (MySQL 5.7 - puerto 3306)
$joomla = new mysqli('127.0.0.1', 'root', 'yue02', 'sudcalifornios', 3306);
if ($joomla->connect_error) die("Error Joomla: " . $joomla->connect_error);
$joomla->set_charset('utf8mb4');

// Conexión WordPress (MySQL 8 - puerto 3308)
$wordpress = mysqli_init();
$wordpress->options(MYSQLI_SET_CHARSET_NAME, 'utf8');
$wordpress->real_connect('127.0.0.1', 'wpuser', 'MiPassword123', 'wordpress', 3308);
if ($wordpress->connect_error) die("Error WordPress: " . $wordpress->connect_error);

// 1. Primero, analizar la estructura de elements de un autor para entender el formato
echo "1. ANALIZANDO ESTRUCTURA DE ELEMENTS...\n";

$result = $joomla->query("
    SELECT id, name, alias, elements
    FROM jos_zoo_item
    WHERE type = 'author' AND state = 1
    LIMIT 1
");

if ($row = $result->fetch_assoc()) {
    echo "   Ejemplo: {$row['name']}\n";
    $elements = json_decode($row['elements'], true);

    if (is_array($elements)) {
        echo "   Claves encontradas:\n";
        foreach ($elements as $key => $value) {
            $json_value = json_encode($value, JSON_UNESCAPED_UNICODE);
            $type = "desconocido";

            // Detectar tipo de campo
            if (is_array($value) && isset($value[0]['value'])) {
                $type = "texto/descripcion";
            } elseif (is_array($value) && isset($value['file'])) {
                $type = "imagen";
            } elseif (is_array($value) && isset($value[0]['file'])) {
                $type = "imagen (array)";
            }

            echo "   - {$key} [{$type}]: " . substr($json_value, 0, 100) . "...\n";
        }
    }
}

// 2. Procesar todos los autores
echo "\n2. PROCESANDO AUTORES DE JOOMLA...\n";

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

while ($row = $result->fetch_assoc()) {
    $stats['procesados']++;

    $elements = json_decode($row['elements'], true);
    if (!is_array($elements)) continue;

    $descripcion = '';
    $imagen = '';

    // Buscar en todos los elementos
    foreach ($elements as $key => $element) {
        // Buscar descripción - diferentes estructuras posibles
        // Estructura 1: [{"value":"<p>texto</p>"}]
        if (is_array($element) && isset($element[0]['value'])) {
            $value = $element[0]['value'];
            $texto_limpio = trim(strip_tags($value));
            // Si tiene contenido significativo (más de 30 chars)
            if (strlen($texto_limpio) > 30 && strlen($texto_limpio) > strlen(strip_tags($descripcion))) {
                $descripcion = $value;
            }
        }

        // Estructura 2: {"value":"texto"}
        if (is_array($element) && isset($element['value']) && is_string($element['value'])) {
            $value = $element['value'];
            $texto_limpio = trim(strip_tags($value));
            if (strlen($texto_limpio) > 30 && strlen($texto_limpio) > strlen(strip_tags($descripcion))) {
                $descripcion = $value;
            }
        }

        // Buscar imagen - diferentes estructuras
        // Estructura 1: {"file":"path/to/image.jpg"}
        if (is_array($element) && isset($element['file']) && !empty($element['file'])) {
            $imagen = $element['file'];
        }

        // Estructura 2: [{"file":"path/to/image.jpg"}]
        if (is_array($element) && isset($element[0]['file']) && !empty($element[0]['file'])) {
            $imagen = $element[0]['file'];
        }
    }

    if (!empty($descripcion)) $stats['con_descripcion']++;
    if (!empty($imagen)) $stats['con_imagen']++;

    // Buscar el usuario en WordPress
    $joomla_id = $row['id'];
    $wp_result = $wordpress->query("
        SELECT user_id FROM wp_usermeta
        WHERE meta_key = '_joomla_author_id' AND meta_value = '{$joomla_id}'
    ");

    if ($wp_row = $wp_result->fetch_assoc()) {
        $user_id = $wp_row['user_id'];

        // Guardar descripción
        if (!empty($descripcion)) {
            // Limpiar HTML pero mantener estructura básica
            $desc_clean = strip_tags($descripcion, '<p><br><strong><em><a>');
            $desc_safe = $wordpress->real_escape_string($desc_clean);

            // Verificar si ya existe
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
            // Construir URL completa
            if (strpos($imagen, 'http') !== 0) {
                $imagen = 'https://www.sudcalifornios.com/' . ltrim($imagen, '/');
            }
            $imagen_safe = $wordpress->real_escape_string($imagen);

            // Guardar como _author_image
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

echo "   Autores procesados: {$stats['procesados']}\n";
echo "   Con descripción en Joomla: {$stats['con_descripcion']}\n";
echo "   Con imagen en Joomla: {$stats['con_imagen']}\n";
echo "   Descripciones actualizadas en WP: {$stats['actualizados_desc']}\n";
echo "   Imágenes actualizadas en WP: {$stats['actualizados_img']}\n";
echo "   No encontrados en WP: {$stats['no_encontrados']}\n";

// 3. Verificar ejemplo
echo "\n3. VERIFICACIÓN - Adrián Corona Ibarra:\n";

$wp_result = $wordpress->query("
    SELECT u.ID, u.display_name
    FROM wp_users u
    WHERE u.user_nicename = 'adrian-corona-ibarra'
");

if ($row = $wp_result->fetch_assoc()) {
    $user_id = $row['ID'];
    echo "   ID: {$user_id}\n";
    echo "   Nombre: {$row['display_name']}\n";

    // Descripción
    $desc = $wordpress->query("SELECT meta_value FROM wp_usermeta WHERE user_id = {$user_id} AND meta_key = 'description'");
    if ($d = $desc->fetch_assoc()) {
        echo "   Descripción: " . substr($d['meta_value'], 0, 100) . "...\n";
    } else {
        echo "   Descripción: NO ENCONTRADA\n";
    }

    // Imagen
    $img = $wordpress->query("SELECT meta_value FROM wp_usermeta WHERE user_id = {$user_id} AND meta_key = '_author_image'");
    if ($i = $img->fetch_assoc()) {
        echo "   Imagen: {$i['meta_value']}\n";
    } else {
        echo "   Imagen: NO ENCONTRADA\n";
    }

    // Posts
    $posts = $wordpress->query("SELECT COUNT(*) as total FROM wp_posts WHERE post_author = {$user_id} AND post_status = 'publish' AND post_type = 'post'");
    $p = $posts->fetch_assoc();
    echo "   Posts: {$p['total']}\n";
}

// 4. Mostrar algunos autores con sus datos
echo "\n4. MUESTRA DE AUTORES ACTUALIZADOS:\n";
$wp_result = $wordpress->query("
    SELECT u.ID, u.display_name,
           (SELECT meta_value FROM wp_usermeta WHERE user_id = u.ID AND meta_key = 'description' LIMIT 1) as descripcion,
           (SELECT meta_value FROM wp_usermeta WHERE user_id = u.ID AND meta_key = '_author_image' LIMIT 1) as imagen
    FROM wp_users u
    INNER JOIN wp_usermeta um ON u.ID = um.user_id AND um.meta_key = '_joomla_author_id'
    LIMIT 10
");

while ($row = $wp_result->fetch_assoc()) {
    $tiene_desc = !empty($row['descripcion']) ? 'SÍ' : 'NO';
    $tiene_img = !empty($row['imagen']) ? 'SÍ' : 'NO';
    echo "   [{$row['ID']}] {$row['display_name']} - Desc: {$tiene_desc}, Img: {$tiene_img}\n";
}

$joomla->close();
$wordpress->close();

echo "\n=== COMPLETADO ===\n";
echo "\n";
echo "SIGUIENTE PASO: Agregar código al tema de WordPress para mostrar\n";
echo "la descripción e imagen en las páginas de autor.\n";
echo "Ver archivo: theme-author-functions.php\n";
?>
