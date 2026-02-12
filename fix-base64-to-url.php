<?php
/**
 * Reemplazar imágenes base64 en posts con URLs de archivos existentes
 * Busca en Joomla la URL correspondiente y actualiza WordPress
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 300);

// Conexión WordPress
$wordpress = mysqli_init();
$wordpress->options(MYSQLI_SET_CHARSET_NAME, 'utf8');
$wordpress->real_connect('127.0.0.1', 'wpuser', 'MiPassword123', 'wordpress', 3308);
if ($wordpress->connect_error) die("Error WordPress: " . $wordpress->connect_error);

// Conexión Joomla
$joomla = mysqli_init();
$joomla->options(MYSQLI_SET_CHARSET_NAME, 'utf8');
$joomla->real_connect('127.0.0.1', 'root', 'yue02', 'sudcalifornios', 3306);
if ($joomla->connect_error) die("Error Joomla: " . $joomla->connect_error);

echo "<h2>REEMPLAZAR BASE64 POR URLs</h2>";
echo "<style>body{font-family:Arial;font-size:12px;} table{border-collapse:collapse;width:100%;} td,th{border:1px solid #ccc;padding:5px;}</style>";

// Buscar posts en WordPress que tengan base64
$result = $wordpress->query("
    SELECT ID, post_title, post_content
    FROM wp_posts
    WHERE post_type = 'post'
    AND post_status = 'publish'
    AND post_content LIKE '%data:image%'
    LIMIT 100
");

$total = $result->num_rows;
echo "<p>Posts con imágenes base64: <strong>{$total}</strong></p>";

if ($total == 0) {
    echo "<p style='color:green;'>¡No hay posts con imágenes base64!</p>";
    exit;
}

$actualizados = 0;
$errores = 0;

echo "<table><tr style='background:#eee;'><th>ID</th><th>Título</th><th>URL Joomla</th><th>Estado</th></tr>";

while ($row = $result->fetch_assoc()) {
    $post_id = $row['ID'];
    $titulo = mb_substr($row['post_title'], 0, 40);
    $contenido = $row['post_content'];

    // Buscar el item correspondiente en Joomla por título similar
    $titulo_buscar = $joomla->real_escape_string($row['post_title']);
    $joomla_item = $joomla->query("
        SELECT id, name, elements
        FROM jos_zoo_item
        WHERE name = '{$titulo_buscar}'
        OR name LIKE '{$titulo_buscar}%'
        LIMIT 1
    ");

    if ($joomla_row = $joomla_item->fetch_assoc()) {
        $joomla_elements = $joomla_row['elements'];

        // Buscar URL de imagen en elements (no base64)
        // Primero buscar imágenes locales de Joomla
        if (preg_match('#/images/[^"\'<>]+\.(?:jpg|jpeg|png|gif)#i', $joomla_elements, $joomla_img)) {
            $imagen_joomla = $joomla_img[0];
            $imagen_wp = str_replace('/images/', '/wp-content/uploads/joomla-images/', $imagen_joomla);

            // Reemplazar base64 por URL en WordPress
            $contenido_nuevo = preg_replace(
                '/<img([^>]*)src=["\']data:image\/[^;]+;base64,[^"\']+["\']/',
                '<img$1src="' . $imagen_wp . '"',
                $contenido,
                1
            );

            if ($contenido_nuevo !== $contenido) {
                $contenido_safe = $wordpress->real_escape_string($contenido_nuevo);
                $wordpress->query("UPDATE wp_posts SET post_content = '{$contenido_safe}' WHERE ID = {$post_id}");

                // Eliminar thumbnail para que se procese de nuevo
                $wordpress->query("DELETE FROM wp_postmeta WHERE post_id = {$post_id} AND meta_key = '_thumbnail_id'");

                $actualizados++;
                $status = "<span style='color:green;'>✓ Actualizado</span>";
                $img_nueva = mb_substr(basename($imagen_wp), 0, 35);
            } else {
                $status = "<span style='color:orange;'>Sin cambios</span>";
                $img_nueva = "-";
            }
        }
        // Buscar URLs externas (http/https)
        elseif (preg_match('#https?://[^"\'<>\s]+\.(?:jpg|jpeg|png|gif)#i', $joomla_elements, $joomla_img)) {
            $imagen_externa = $joomla_img[0];

            // Reemplazar base64 por URL externa
            $contenido_nuevo = preg_replace(
                '/<img([^>]*)src=["\']data:image\/[^;]+;base64,[^"\']+["\']/',
                '<img$1src="' . $imagen_externa . '"',
                $contenido,
                1
            );

            if ($contenido_nuevo !== $contenido) {
                $contenido_safe = $wordpress->real_escape_string($contenido_nuevo);
                $wordpress->query("UPDATE wp_posts SET post_content = '{$contenido_safe}' WHERE ID = {$post_id}");

                $wordpress->query("DELETE FROM wp_postmeta WHERE post_id = {$post_id} AND meta_key = '_thumbnail_id'");

                $actualizados++;
                $status = "<span style='color:blue;'>✓ URL Externa</span>";
                $img_nueva = mb_substr(basename($imagen_externa), 0, 35);
            } else {
                $status = "<span style='color:orange;'>Sin cambios</span>";
                $img_nueva = "-";
            }
        }
        else {
            $status = "<span style='color:red;'>Sin imagen en Joomla</span>";
            $img_nueva = "-";
            $errores++;
        }
    } else {
        $status = "<span style='color:red;'>No encontrado en Joomla</span>";
        $img_nueva = "-";
        $errores++;
    }

    echo "<tr><td>{$post_id}</td><td>{$titulo}</td><td>{$img_nueva}</td><td>{$status}</td></tr>";
}

echo "</table>";

echo "<h3>Resumen:</h3>";
echo "<ul>";
echo "<li>Actualizados: <strong style='color:green;'>{$actualizados}</strong></li>";
echo "<li>Errores/No encontrados: {$errores}</li>";
echo "</ul>";

if ($actualizados > 0) {
    echo "<p><a href='fix-featured-images-v3.php' style='padding:10px 20px;background:#0073aa;color:white;text-decoration:none;border-radius:5px;'>▶ Ejecutar fix-featured-images-v3.php</a></p>";
}

$wordpress->close();
$joomla->close();
?>
