<?php
/**
 * Asignar posts a sus autores correctos
 * La relación en Joomla está en elements con estructura: {"item":["ID_AUTOR"]}
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);

echo "=== ASIGNAR POSTS A AUTORES ===\n\n";

// Conexión Joomla (MySQL 5.7 - puerto 3306)
$joomla = new mysqli('127.0.0.1', 'root', 'yue02', 'sudcalifornios', 3306);
if ($joomla->connect_error) die("Error Joomla: " . $joomla->connect_error);
$joomla->set_charset('utf8');

// Conexión WordPress (MySQL 8 - puerto 3308)
$wordpress = mysqli_init();
$wordpress->options(MYSQLI_SET_CHARSET_NAME, 'utf8');
$wordpress->real_connect('127.0.0.1', 'wpuser', 'MiPassword123', 'wordpress', 3308);
if ($wordpress->connect_error) die("Error WordPress: " . $wordpress->connect_error);

// 1. Crear mapeo: joomla_author_id -> wordpress_user_id
echo "1. Creando mapeo de autores...\n";
$author_map = [];
$result = $wordpress->query("
    SELECT user_id, meta_value as joomla_id
    FROM wp_usermeta
    WHERE meta_key = '_joomla_author_id'
");
while ($row = $result->fetch_assoc()) {
    $author_map[$row['joomla_id']] = $row['user_id'];
}
echo "   Autores en WordPress: " . count($author_map) . "\n\n";

// 2. Crear mapeo: joomla_article_alias -> wordpress_post_id
echo "2. Creando mapeo de posts...\n";
$post_map = [];
$result = $wordpress->query("
    SELECT ID, post_name FROM wp_posts
    WHERE post_type = 'post' AND post_status = 'publish'
");
while ($row = $result->fetch_assoc()) {
    $post_map[$row['post_name']] = $row['ID'];
}
echo "   Posts en WordPress: " . count($post_map) . "\n\n";

// 3. Buscar artículos en Joomla y extraer relación con autor
echo "3. Procesando artículos de Joomla...\n";
$result = $joomla->query("
    SELECT id, alias, elements
    FROM jos_zoo_item
    WHERE type = 'article' AND state = 1
");

$posts_updated = 0;
$posts_not_found = 0;
$authors_not_found = 0;
$no_author_relation = 0;

while ($row = $result->fetch_assoc()) {
    $elements = json_decode($row['elements'], true);
    if (!is_array($elements)) continue;

    $author_joomla_id = null;

    // Buscar en todos los elements la estructura {"item":["ID"]}
    foreach ($elements as $key => $element) {
        if (isset($element['item']) && is_array($element['item']) && !empty($element['item'][0])) {
            // Verificar si este ID corresponde a un autor
            $potential_id = $element['item'][0];
            if (isset($author_map[$potential_id])) {
                $author_joomla_id = $potential_id;
                break;
            }
        }
    }

    if (!$author_joomla_id) {
        $no_author_relation++;
        continue;
    }

    // Buscar el post en WordPress
    $article_alias = $row['alias'];
    if (!isset($post_map[$article_alias])) {
        $posts_not_found++;
        continue;
    }

    $wp_post_id = $post_map[$article_alias];
    $wp_author_id = $author_map[$author_joomla_id];

    // Actualizar el post
    $wordpress->query("
        UPDATE wp_posts
        SET post_author = {$wp_author_id}
        WHERE ID = {$wp_post_id}
    ");

    if ($wordpress->affected_rows > 0) {
        $posts_updated++;
    }
}

echo "   Posts actualizados: {$posts_updated}\n";
echo "   Posts no encontrados en WP: {$posts_not_found}\n";
echo "   Artículos sin relación de autor: {$no_author_relation}\n\n";

// 4. Verificar ejemplo: Adrián Corona Ibarra
echo "4. Verificación - Adrián Corona Ibarra:\n";
$result = $wordpress->query("
    SELECT ID FROM wp_users WHERE user_nicename = 'adrian-corona-ibarra'
");
if ($row = $result->fetch_assoc()) {
    $user_id = $row['ID'];

    // Contar posts
    $count = $wordpress->query("
        SELECT COUNT(*) as total
        FROM wp_posts
        WHERE post_author = {$user_id}
        AND post_status = 'publish'
        AND post_type = 'post'
    ");
    $c = $count->fetch_assoc();
    echo "   Posts asignados: {$c['total']}\n";

    // Mostrar algunos títulos
    $posts = $wordpress->query("
        SELECT post_title
        FROM wp_posts
        WHERE post_author = {$user_id}
        AND post_status = 'publish'
        AND post_type = 'post'
        LIMIT 5
    ");
    echo "   Ejemplos:\n";
    while ($p = $posts->fetch_assoc()) {
        echo "   - {$p['post_title']}\n";
    }
}

$joomla->close();
$wordpress->close();

echo "\n=== COMPLETADO ===\n";
?>
