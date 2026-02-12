<?php
/**
 * Asignar imágenes destacadas - Versión 2
 * Busca la primera imagen válida en el contenido y la asigna como destacada
 * Maneja URLs externas, locales y base64
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');
set_time_limit(0);

$wordpress = mysqli_init();
$wordpress->options(MYSQLI_SET_CHARSET_NAME, 'utf8');
$wordpress->real_connect('127.0.0.1', 'wpuser', 'MiPassword123', 'wordpress', 3308);
if ($wordpress->connect_error) die("Error WordPress: " . $wordpress->connect_error);

echo "<h2>ASIGNAR IMÁGENES DESTACADAS A POSTS - V2</h2>";

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
    'con_imagen_local' => 0,
    'con_imagen_externa' => 0,
    'sin_imagen' => 0,
    'errores' => 0
];

$batch_size = 100;
$offset = 0;

echo "<table border='1' cellpadding='5' style='border-collapse:collapse; font-size:12px;'>";
echo "<tr style='background:#f0f0f0;'><th>ID</th><th>Título</th><th>Tipo</th><th>Imagen</th><th>Estado</th></tr>";

/**
 * Función para obtener la primera imagen válida del contenido
 */
function obtener_imagen_del_contenido($contenido) {
    // Buscar todas las imágenes
    if (!preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/', $contenido, $matches)) {
        return null;
    }

    foreach ($matches[1] as $imagen_url) {
        // Ignorar imágenes base64 (data:image)
        if (strpos($imagen_url, 'data:image') === 0) {
            continue;
        }

        // Ignorar imágenes muy pequeñas (tracking pixels, etc)
        if (preg_match('/1x1|pixel|spacer|blank/i', $imagen_url)) {
            continue;
        }

        // Determinar el tipo de imagen
        $tipo = 'externa';

        // Es URL de sudcalifornios.com - convertir a local
        if (preg_match('#https?://(www\.)?sudcalifornios\.com/images/#', $imagen_url)) {
            $imagen_url = preg_replace(
                '#https?://(www\.)?sudcalifornios\.com/images/#',
                '/wp-content/uploads/joomla-images/',
                $imagen_url
            );
            $tipo = 'local';
        }
        // Es ruta relativa de Joomla
        elseif (preg_match('#^/images/#', $imagen_url)) {
            $imagen_url = str_replace('/images/', '/wp-content/uploads/joomla-images/', $imagen_url);
            $tipo = 'local';
        }
        // Ya es ruta local de WordPress
        elseif (strpos($imagen_url, '/wp-content/uploads/') === 0) {
            $tipo = 'local';
        }
        // Es URL externa completa (http/https)
        elseif (preg_match('#^https?://#', $imagen_url)) {
            $tipo = 'externa';
        }
        // Otro tipo de ruta - ignorar
        else {
            continue;
        }

        return [
            'url' => $imagen_url,
            'tipo' => $tipo
        ];
    }

    return null;
}

/**
 * Crear o encontrar attachment para una imagen
 */
function obtener_o_crear_attachment($wordpress, $imagen_url, $post_id, $tipo) {
    // Obtener nombre del archivo
    $parsed = parse_url($imagen_url);
    $path = isset($parsed['path']) ? $parsed['path'] : $imagen_url;
    $filename = basename($path);
    $filename = preg_replace('/[?#].*$/', '', $filename); // Quitar query strings

    if (empty($filename) || strlen($filename) < 3) {
        $filename = 'image_' . $post_id . '.jpg';
    }

    $filename_safe = $wordpress->real_escape_string(mb_substr($filename, 0, 100));

    // Buscar attachment existente
    $attachment = $wordpress->query("
        SELECT ID FROM wp_posts
        WHERE post_type = 'attachment'
        AND (guid LIKE '%{$filename_safe}' OR post_title = '{$filename_safe}')
        LIMIT 1
    ");

    if ($att = $attachment->fetch_assoc()) {
        return $att['ID'];
    }

    // Crear nuevo attachment
    // Para imágenes externas, guardar la URL completa
    // Para imágenes locales, guardar la ruta relativa
    if ($tipo === 'externa') {
        $guid = mb_substr($imagen_url, 0, 250);
    } else {
        $guid = mb_substr($imagen_url, 0, 250);
    }

    $guid_safe = $wordpress->real_escape_string($guid);
    $titulo_safe = $wordpress->real_escape_string(mb_substr($filename, 0, 100));
    $post_name = sanitize_filename($filename);
    $post_name_safe = $wordpress->real_escape_string(mb_substr($post_name, 0, 100));

    // Determinar mime type
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mime_types = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp'
    ];
    $mime_type = isset($mime_types[$extension]) ? $mime_types[$extension] : 'image/jpeg';

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
            '', 0, '{$guid_safe}', 0, 'attachment', '{$mime_type}'
        )
    ");

    $attachment_id = $wordpress->insert_id;

    if ($attachment_id && $tipo === 'local') {
        // Para imágenes locales, guardar la ruta relativa en _wp_attached_file
        $ruta_relativa = str_replace('/wp-content/uploads/', '', $imagen_url);
        $ruta_safe = $wordpress->real_escape_string($ruta_relativa);
        $wordpress->query("
            INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
            VALUES ({$attachment_id}, '_wp_attached_file', '{$ruta_safe}')
        ");
    }

    return $attachment_id;
}

function sanitize_filename($filename) {
    $filename = preg_replace('/[^a-zA-Z0-9_.-]/', '-', $filename);
    $filename = preg_replace('/-+/', '-', $filename);
    return trim($filename, '-');
}

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

        $imagen = obtener_imagen_del_contenido($row['post_content']);

        if (!$imagen) {
            $stats['sin_imagen']++;
            continue;
        }

        $attachment_id = obtener_o_crear_attachment($wordpress, $imagen['url'], $row['ID'], $imagen['tipo']);

        if ($attachment_id) {
            $wordpress->query("
                INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
                VALUES ({$row['ID']}, '_thumbnail_id', {$attachment_id})
            ");

            if ($imagen['tipo'] === 'local') {
                $stats['con_imagen_local']++;
            } else {
                $stats['con_imagen_externa']++;
            }
            $status = "✓";
        } else {
            $stats['errores']++;
            $status = "✗";
        }

        if ($stats['procesados'] <= 50) {
            $titulo_corto = mb_substr($row['post_title'], 0, 30);
            $img_corto = mb_substr(basename($imagen['url']), 0, 30);
            $tipo_badge = $imagen['tipo'] === 'local'
                ? "<span style='color:green;'>LOCAL</span>"
                : "<span style='color:blue;'>EXT</span>";
            echo "<tr><td>{$row['ID']}</td><td>{$titulo_corto}...</td><td>{$tipo_badge}</td><td>{$img_corto}</td><td>{$status}</td></tr>";
        }
    }

    $result->free();
    $offset += $batch_size;

} while ($rows_in_batch == $batch_size);

echo "</table>";

if ($stats['procesados'] > 50) {
    echo "<p><em>... y " . ($stats['procesados'] - 50) . " más procesados</em></p>";
}

echo "<h3>RESUMEN:</h3>";
echo "<ul>";
echo "<li>Posts procesados: <strong>{$stats['procesados']}</strong></li>";
echo "<li>Con imagen LOCAL: <strong style='color:green;'>{$stats['con_imagen_local']}</strong></li>";
echo "<li>Con imagen EXTERNA: <strong style='color:blue;'>{$stats['con_imagen_externa']}</strong></li>";
echo "<li>Sin imagen válida: {$stats['sin_imagen']}</li>";
echo "<li>Errores: {$stats['errores']}</li>";
echo "</ul>";

$wordpress->close();
echo "<h3 style='color:green;'>¡COMPLETADO!</h3>";
echo "<p>Ahora visita la página de un autor para verificar que las imágenes se muestren.</p>";
?>
