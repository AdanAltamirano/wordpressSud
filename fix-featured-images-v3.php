<?php
/**
 * Asignar imágenes destacadas - Versión 3 (Rápida)
 * Procesa solo 50 posts a la vez para evitar timeout
 * Ejecutar varias veces hasta completar
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 120);

$wordpress = mysqli_init();
$wordpress->options(MYSQLI_SET_CHARSET_NAME, 'utf8');
$wordpress->real_connect('127.0.0.1', 'wpuser', 'MiPassword123', 'wordpress', 3308);
if ($wordpress->connect_error) die("Error WordPress: " . $wordpress->connect_error);

echo "<h2>ASIGNAR IMÁGENES DESTACADAS - V3</h2>";
echo "<style>body{font-family:Arial;} table{border-collapse:collapse;} td,th{border:1px solid #ccc;padding:5px;font-size:11px;}</style>";

// Contar pendientes
$count_result = $wordpress->query("
    SELECT COUNT(*) as total
    FROM wp_posts p
    LEFT JOIN wp_postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_thumbnail_id'
    WHERE p.post_type = 'post'
    AND p.post_status = 'publish'
    AND pm.meta_id IS NULL
");
$count = $count_result->fetch_assoc();
$pendientes = $count['total'];

echo "<p>Posts pendientes: <strong>{$pendientes}</strong></p>";

if ($pendientes == 0) {
    echo "<h3 style='color:green;'>¡TODOS LOS POSTS TIENEN IMAGEN DESTACADA!</h3>";
    $wordpress->close();
    exit;
}

$procesados = 0;
$asignados = 0;
$sin_imagen = 0;
$LIMITE = 50; // Procesar solo 50 a la vez

echo "<table><tr style='background:#eee;'><th>#</th><th>ID</th><th>Título</th><th>Imagen</th><th>Estado</th></tr>";

$result = $wordpress->query("
    SELECT p.ID, p.post_title, p.post_content
    FROM wp_posts p
    LEFT JOIN wp_postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_thumbnail_id'
    WHERE p.post_type = 'post'
    AND p.post_status = 'publish'
    AND pm.meta_id IS NULL
    LIMIT {$LIMITE}
");

while ($row = $result->fetch_assoc()) {
    $procesados++;
    $imagen_url = null;
    $tipo = '';

    // Buscar imagen válida en el contenido
    if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/', $row['post_content'], $matches)) {
        foreach ($matches[1] as $url) {
            // Ignorar base64
            if (strpos($url, 'data:image') === 0) continue;
            // Ignorar tracking pixels
            if (preg_match('/1x1|pixel|spacer|blank/i', $url)) continue;

            // Convertir URLs de sudcalifornios
            if (preg_match('#https?://(www\.)?sudcalifornios\.com/images/#', $url)) {
                $imagen_url = preg_replace('#https?://(www\.)?sudcalifornios\.com/images/#', '/wp-content/uploads/joomla-images/', $url);
                $tipo = 'LOCAL';
                break;
            }
            // Ruta relativa de Joomla
            elseif (preg_match('#^/images/#', $url)) {
                $imagen_url = str_replace('/images/', '/wp-content/uploads/joomla-images/', $url);
                $tipo = 'LOCAL';
                break;
            }
            // Ya es local
            elseif (strpos($url, '/wp-content/uploads/') === 0) {
                $imagen_url = $url;
                $tipo = 'LOCAL';
                break;
            }
            // Externa
            elseif (preg_match('#^https?://#', $url)) {
                $imagen_url = $url;
                $tipo = 'EXT';
                break;
            }
        }
    }

    $titulo_corto = mb_substr($row['post_title'], 0, 35);

    if (!$imagen_url) {
        $sin_imagen++;
        // Asignar imagen por defecto para que no se procese de nuevo
        $wordpress->query("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES ({$row['ID']}, '_thumbnail_id', 0)");
        echo "<tr><td>{$procesados}</td><td>{$row['ID']}</td><td>{$titulo_corto}</td><td>-</td><td style='color:orange;'>Sin imagen</td></tr>";
        continue;
    }

    // Crear attachment
    $filename = basename(parse_url($imagen_url, PHP_URL_PATH));
    $filename = preg_replace('/[?#].*$/', '', $filename);
    if (empty($filename)) $filename = 'img_' . $row['ID'] . '.jpg';

    $filename_safe = $wordpress->real_escape_string(mb_substr($filename, 0, 100));
    $guid_safe = $wordpress->real_escape_string(mb_substr($imagen_url, 0, 250));

    // Buscar attachment existente
    $att = $wordpress->query("SELECT ID FROM wp_posts WHERE post_type='attachment' AND guid='{$guid_safe}' LIMIT 1");
    if ($existing = $att->fetch_assoc()) {
        $attachment_id = $existing['ID'];
    } else {
        // Crear nuevo
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime = in_array($ext, ['png']) ? 'image/png' : (in_array($ext, ['gif']) ? 'image/gif' : 'image/jpeg');

        $wordpress->query("
            INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type)
            VALUES (1, NOW(), NOW(), '', '{$filename_safe}', '', 'inherit', 'open', 'closed', '', '{$filename_safe}', '', '', NOW(), NOW(), '', 0, '{$guid_safe}', 0, 'attachment', '{$mime}')
        ");
        $attachment_id = $wordpress->insert_id;

        // Guardar ruta del archivo
        if ($tipo == 'LOCAL') {
            $ruta = str_replace('/wp-content/uploads/', '', $imagen_url);
            $ruta_safe = $wordpress->real_escape_string($ruta);
            $wordpress->query("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES ({$attachment_id}, '_wp_attached_file', '{$ruta_safe}')");
        }
    }

    // Asignar thumbnail
    $wordpress->query("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES ({$row['ID']}, '_thumbnail_id', {$attachment_id})");
    $asignados++;

    $img_corto = mb_substr(basename($imagen_url), 0, 25);
    $color = $tipo == 'LOCAL' ? 'green' : 'blue';
    echo "<tr><td>{$procesados}</td><td>{$row['ID']}</td><td>{$titulo_corto}</td><td>{$img_corto}</td><td style='color:{$color};'>✓ {$tipo}</td></tr>";
}

echo "</table>";

$restantes = $pendientes - $procesados;
echo "<h3>Resumen:</h3>";
echo "<ul>";
echo "<li>Procesados: <strong>{$procesados}</strong></li>";
echo "<li>Asignados: <strong style='color:green;'>{$asignados}</strong></li>";
echo "<li>Sin imagen: {$sin_imagen}</li>";
echo "<li>Restantes: <strong>{$restantes}</strong></li>";
echo "</ul>";

if ($restantes > 0) {
    echo "<h3 style='color:orange;'>EJECUTA DE NUEVO para procesar los {$restantes} restantes</h3>";
    echo "<p><a href='fix-featured-images-v3.php' style='padding:10px 20px; background:#0073aa; color:white; text-decoration:none; border-radius:5px;'>▶ CONTINUAR</a></p>";
} else {
    echo "<h3 style='color:green;'>¡COMPLETADO!</h3>";
}

$wordpress->close();
?>
