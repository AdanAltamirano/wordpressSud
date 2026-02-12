<?php
/**
 * Asignar imágenes destacadas a los posts
 * Procesa en lotes para evitar problemas de memoria
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');
set_time_limit(0);

$wordpress = mysqli_init();
$wordpress->options(MYSQLI_SET_CHARSET_NAME, 'utf8');
$wordpress->real_connect('127.0.0.1', 'wpuser', 'MiPassword123', 'wordpress', 3308);
if ($wordpress->connect_error) die("Error WordPress: " . $wordpress->connect_error);

echo "<h2>ASIGNAR IMÁGENES DESTACADAS A POSTS</h2>";

// Contar posts sin imagen destacada
$count_result = $wordpress->query("
    SELECT COUNT(*) as total
    FROM wp_posts p
    LEFT JOIN wp_postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_thumbnail_id'
    WHERE p.post_type = 'post'
    AND p.post_status = 'publish'
    AND pm.meta_id IS NULL
");
$count = $count_result->fetch_assoc();
echo "<p>Posts sin imagen destacada: <strong>{$count['total']}</strong></p>";

$stats = [
    'procesados' => 0,
    'con_imagen' => 0,
    'sin_imagen' => 0,
    'errores' => 0
];

$batch_size = 100;
$offset = 0;

echo "<table border='1' cellpadding='5' style='border-collapse:collapse; font-size:12px;'>";
echo "<tr style='background:#f0f0f0;'><th>ID</th><th>Título</th><th>Imagen</th><th>Estado</th></tr>";

do {
    $result = $wordpress->query("
        SELECT p.ID, p.post_title, p.post_content
        FROM wp_posts p
        LEFT JOIN wp_postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_thumbnail_id'
        WHERE p.post_type = 'post'
        AND p.post_status = 'publish'
        AND pm.meta_id IS NULL
        LIMIT {$batch_size} OFFSET {$offset}
    ");

    $rows_in_batch = $result->num_rows;

    while ($row = $result->fetch_assoc()) {
        $stats['procesados']++;

        $imagen_url = '';
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/', $row['post_content'], $matches)) {
            $imagen_url = $matches[1];
        }

        if (empty($imagen_url)) {
            $stats['sin_imagen']++;
            continue;
        }

        // Convertir URL a ruta local
        $imagen_local = $imagen_url;
        if (strpos($imagen_url, 'sudcalifornios.com') !== false) {
            $imagen_local = preg_replace(
                '#https?://(www\.)?sudcalifornios\.com/images/#',
                '/wp-content/uploads/joomla-images/',
                $imagen_url
            );
        }

        // Obtener solo el nombre del archivo
        $imagen_filename = basename(parse_url($imagen_local, PHP_URL_PATH));
        $imagen_filename_safe = $wordpress->real_escape_string(substr($imagen_filename, 0, 200));

        // Buscar attachment existente
        $attachment = $wordpress->query("
            SELECT ID FROM wp_posts
            WHERE post_type = 'attachment'
            AND guid LIKE '%{$imagen_filename_safe}'
            LIMIT 1
        ");

        $attachment_id = null;

        if ($att = $attachment->fetch_assoc()) {
            $attachment_id = $att['ID'];
        } else {
            // Crear GUID corto (máximo 255 caracteres)
            $guid = substr($imagen_local, 0, 250);
            $guid_safe = $wordpress->real_escape_string($guid);
            $titulo_safe = $wordpress->real_escape_string(substr($row['post_title'], 0, 150));
            $post_name_safe = $wordpress->real_escape_string(substr($imagen_filename, 0, 190));

            $wordpress->query("
                INSERT INTO wp_posts (
                    post_author, post_date, post_date_gmt, post_content, post_title,
                    post_excerpt, post_status, comment_status, ping_status, post_password,
                    post_name, to_ping, pinged, post_modified, post_modified_gmt,
                    post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type
                ) VALUES (
                    1, NOW(), NOW(), '', '{$titulo_safe}',
                    '', 'inherit', 'open', 'closed', '',
                    '{$post_name_safe}', '', '', NOW(), NOW(),
                    '', 0, '{$guid_safe}', 0, 'attachment', 'image/jpeg'
                )
            ");

            $attachment_id = $wordpress->insert_id;

            if ($attachment_id) {
                $ruta_relativa = str_replace('/wp-content/uploads/', '', $imagen_local);
                $ruta_safe = $wordpress->real_escape_string(substr($ruta_relativa, 0, 250));
                $wordpress->query("
                    INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
                    VALUES ({$attachment_id}, '_wp_attached_file', '{$ruta_safe}')
                ");
            }
        }

        if ($attachment_id) {
            $wordpress->query("
                INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
                VALUES ({$row['ID']}, '_thumbnail_id', {$attachment_id})
            ");

            $stats['con_imagen']++;
            $status = "✓";
        } else {
            $stats['errores']++;
            $status = "✗";
        }

        if ($stats['procesados'] <= 30) {
            $titulo_corto = substr($row['post_title'], 0, 35);
            $img_corto = substr(basename($imagen_local), 0, 25);
            echo "<tr><td>{$row['ID']}</td><td>{$titulo_corto}...</td><td>{$img_corto}</td><td>{$status}</td></tr>";
        }
    }

    $result->free();
    $offset += $batch_size;

} while ($rows_in_batch == $batch_size);

echo "</table>";

if ($stats['procesados'] > 30) {
    echo "<p><em>... y " . ($stats['procesados'] - 30) . " más procesados</em></p>";
}

echo "<h3>RESUMEN:</h3>";
echo "<ul>";
echo "<li>Posts procesados: <strong>{$stats['procesados']}</strong></li>";
echo "<li>Imágenes asignadas: <strong style='color:green;'>{$stats['con_imagen']}</strong></li>";
echo "<li>Sin imagen en contenido: {$stats['sin_imagen']}</li>";
echo "<li>Errores: {$stats['errores']}</li>";
echo "</ul>";

$wordpress->close();
echo "<h3 style='color:green;'>¡COMPLETADO!</h3>";
?>
