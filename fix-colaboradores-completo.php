<?php
/**
 * Arreglar colaboradores COMPLETO:
 * - Descripción/Biografía
 * - Imagen de perfil
 * - Asignar posts a autores
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);

echo "=== ARREGLAR COLABORADORES - COMPLETO ===\n\n";

// Conexiones
$joomla = new mysqli('127.0.0.1', 'root', 'yue02', 'sudcalifornios', 3306);
$wordpress = new mysqli('127.0.0.1', 'wpuser', 'MiPassword123', 'wordpress', 3308);

if ($joomla->connect_error) die("Error Joomla: " . $joomla->connect_error);
if ($wordpress->connect_error) die("Error WordPress: " . $wordpress->connect_error);

$joomla->set_charset('utf8mb4');
$wordpress->set_charset('utf8mb4');

// ==========================================
// PARTE 1: Actualizar descripciones e imágenes de usuarios
// ==========================================
echo "1. ACTUALIZANDO DESCRIPCIONES E IMÁGENES...\n";

$result = $joomla->query("
    SELECT id, name, alias, elements
    FROM jos_zoo_item
    WHERE type = 'author' AND state = 1
");

$updated_desc = 0;
$updated_img = 0;

while ($row = $result->fetch_assoc()) {
    $elements = json_decode($row['elements'], true);
    $descripcion = '';
    $imagen = '';

    if (is_array($elements)) {
        foreach ($elements as $key => $element) {
            // Buscar descripción (textarea)
            if (isset($element['0']['value']) && !empty($element['0']['value'])) {
                $value = $element['0']['value'];
                if (strlen($value) > 50 && strlen($value) > strlen($descripcion)) {
                    $descripcion = $value;
                }
            }

            // Buscar imagen
            if (isset($element['0']['file']) && !empty($element['0']['file'])) {
                $imagen = $element['0']['file'];
            }
        }
    }

    // Buscar el usuario en WordPress
    $joomla_id = $row['id'];
    $wp_result = $wordpress->query("
        SELECT user_id FROM wp_usermeta
        WHERE meta_key = '_joomla_author_id' AND meta_value = '{$joomla_id}'
    ");

    if ($wp_row = $wp_result->fetch_assoc()) {
        $user_id = $wp_row['user_id'];

        // Actualizar descripción
        if (!empty($descripcion)) {
            $descripcion_safe = $wordpress->real_escape_string(strip_tags($descripcion));
            $check = $wordpress->query("SELECT umeta_id FROM wp_usermeta WHERE user_id = {$user_id} AND meta_key = 'description'");
            if ($check->num_rows > 0) {
                $wordpress->query("UPDATE wp_usermeta SET meta_value = '{$descripcion_safe}' WHERE user_id = {$user_id} AND meta_key = 'description'");
            } else {
                $wordpress->query("INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES ({$user_id}, 'description', '{$descripcion_safe}')");
            }
            $updated_desc++;
        }

        // Guardar imagen como meta (para uso con Simple Local Avatars o custom code)
        if (!empty($imagen)) {
            // Construir URL completa de la imagen
            if (strpos($imagen, 'http') !== 0) {
                $imagen = 'http://www.sudcalifornios.com/' . ltrim($imagen, '/');
            }
            $imagen_safe = $wordpress->real_escape_string($imagen);

            $check = $wordpress->query("SELECT umeta_id FROM wp_usermeta WHERE user_id = {$user_id} AND meta_key = '_author_image'");
            if ($check->num_rows > 0) {
                $wordpress->query("UPDATE wp_usermeta SET meta_value = '{$imagen_safe}' WHERE user_id = {$user_id} AND meta_key = '_author_image'");
            } else {
                $wordpress->query("INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES ({$user_id}, '_author_image', '{$imagen_safe}')");
            }
            $updated_img++;
        }
    }
}
echo "   Usuarios con descripción: {$updated_desc}\n";
echo "   Usuarios con imagen: {$updated_img}\n\n";

// ==========================================
// PARTE 2: Asignar posts a autores
// ==========================================
echo "2. ASIGNANDO POSTS A AUTORES...\n";

// Crear mapeo: joomla_author_id -> wordpress_user_id
$author_map = [];
$result = $wordpress->query("
    SELECT user_id, meta_value as joomla_id
    FROM wp_usermeta
    WHERE meta_key = '_joomla_author_id'
");
while ($row = $result->fetch_assoc()) {
    $author_map[$row['joomla_id']] = $row['user_id'];
}
echo "   Autores mapeados: " . count($author_map) . "\n";

// Buscar artículos en Joomla y su relación con autores
$posts_updated = 0;
$result = $joomla->query("
    SELECT id, alias, elements
    FROM jos_zoo_item
    WHERE type = 'article' AND state = 1
");

while ($row = $result->fetch_assoc()) {
    $elements = json_decode($row['elements'], true);
    if (!is_array($elements)) continue;

    foreach ($elements as $key => $element) {
        // Buscar campos de relación con autor
        if (strpos($key, 'related') !== false || strpos($key, 'author') !== false || strpos($key, 'item') !== false) {
            $author_joomla_id = null;

            // Diferentes estructuras posibles
            if (isset($element['0']['item'])) {
                $author_joomla_id = $element['0']['item'];
            } elseif (isset($element['item'])) {
                $author_joomla_id = $element['item'];
            } elseif (isset($element['0']) && is_numeric($element['0'])) {
                $author_joomla_id = $element['0'];
            }

            if ($author_joomla_id && isset($author_map[$author_joomla_id])) {
                $wp_author_id = $author_map[$author_joomla_id];
                $article_alias = $wordpress->real_escape_string($row['alias']);

                $wordpress->query("
                    UPDATE wp_posts
                    SET post_author = {$wp_author_id}
                    WHERE post_name = '{$article_alias}'
                    AND post_type = 'post'
                ");

                if ($wordpress->affected_rows > 0) {
                    $posts_updated++;
                }
                break; // Ya encontramos el autor, salir del loop
            }
        }
    }
}
echo "   Posts asignados a autores: {$posts_updated}\n\n";

// ==========================================
// PARTE 3: Mostrar ejemplo
// ==========================================
echo "3. EJEMPLO - Adrián Corona Ibarra:\n";

$result = $wordpress->query("
    SELECT u.ID, u.display_name
    FROM wp_users u
    WHERE u.user_nicename = 'adrian-corona-ibarra'
");

if ($row = $result->fetch_assoc()) {
    $user_id = $row['ID'];
    echo "   ID: {$user_id}\n";
    echo "   Nombre: {$row['display_name']}\n";

    // Descripción
    $desc = $wordpress->query("SELECT meta_value FROM wp_usermeta WHERE user_id = {$user_id} AND meta_key = 'description'");
    if ($d = $desc->fetch_assoc()) {
        echo "   Descripción: " . substr($d['meta_value'], 0, 80) . "...\n";
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
    $count = $wordpress->query("SELECT COUNT(*) as total FROM wp_posts WHERE post_author = {$user_id} AND post_status = 'publish' AND post_type = 'post'");
    $c = $count->fetch_assoc();
    echo "   Posts: {$c['total']}\n";
}

// ==========================================
// PARTE 4: Analizar estructura de elements para debug
// ==========================================
echo "\n4. DEBUG - Estructura de elements de Adrián Corona:\n";
$debug = $joomla->query("SELECT elements FROM jos_zoo_item WHERE alias = 'adrian-corona-ibarra' AND type = 'author'");
if ($d = $debug->fetch_assoc()) {
    $elements = json_decode($d['elements'], true);
    if (is_array($elements)) {
        foreach ($elements as $key => $value) {
            echo "   Key: {$key}\n";
            echo "   Value: " . substr(json_encode($value), 0, 150) . "...\n\n";
        }
    }
}

$joomla->close();
$wordpress->close();

echo "\n=== COMPLETADO ===\n";
echo "\nNOTA: Para mostrar la imagen personalizada en WordPress,\n";
echo "necesitas agregar código al tema o usar un plugin como 'Simple Local Avatars'\n";
?>
