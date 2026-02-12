<?php
/**
 * Script completo para actualizar TODAS las URLs de imágenes en WordPress
 * - Reemplaza imágenes BASE64 por URLs de archivos
 * - Convierte rutas de Joomla (/images/...) a WordPress (/wp-content/uploads/joomla-images/...)
 * - Procesa en lotes para evitar timeout
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 600);
ini_set('memory_limit', '512M');

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

// Configuración
$batch_size = 50;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$dry_run = isset($_GET['dry_run']) ? $_GET['dry_run'] === '1' : true; // Por defecto solo muestra, no modifica

// Rutas
$joomla_base = '/images/';
$wp_base = '/wp-content/uploads/joomla-images/';

echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Fix All URLs</title></head><body>";
echo "<style>
    body { font-family: Arial, sans-serif; font-size: 13px; padding: 20px; }
    table { border-collapse: collapse; width: 100%; margin: 10px 0; }
    td, th { border: 1px solid #ddd; padding: 8px; text-align: left; vertical-align: top; }
    th { background: #f5f5f5; }
    .success { color: green; }
    .warning { color: orange; }
    .error { color: red; }
    .info { color: blue; }
    pre { background: #f9f9f9; padding: 5px; overflow-x: auto; max-width: 400px; font-size: 11px; }
    .btn { padding: 10px 20px; margin: 5px; text-decoration: none; border-radius: 5px; display: inline-block; }
    .btn-primary { background: #0073aa; color: white; }
    .btn-success { background: #28a745; color: white; }
    .btn-warning { background: #ffc107; color: black; }
</style>";

echo "<h1>🔧 Actualizar URLs de Imágenes en WordPress</h1>";

if ($dry_run) {
    echo "<div style='background:#fff3cd;padding:15px;border-radius:5px;margin:10px 0;'>";
    echo "<strong>⚠️ MODO VISTA PREVIA</strong> - No se modificarán datos. ";
    echo "<a href='?dry_run=0' class='btn btn-success'>▶️ EJECUTAR CAMBIOS REALES</a>";
    echo "</div>";
} else {
    echo "<div style='background:#d4edda;padding:15px;border-radius:5px;margin:10px 0;'>";
    echo "<strong>✅ MODO EJECUCIÓN</strong> - Se están aplicando los cambios.";
    echo "</div>";
}

// Contar total de posts
$count_result = $wordpress->query("
    SELECT COUNT(*) as total FROM wp_posts
    WHERE post_type = 'post'
    AND post_status = 'publish'
    AND (
        post_content LIKE '%data:image%'
        OR post_content LIKE '%/images/%'
        OR post_content LIKE '%images/zoo/%'
    )
");
$total_posts = $count_result->fetch_assoc()['total'];

echo "<p><strong>Total de posts a procesar:</strong> {$total_posts}</p>";
echo "<p><strong>Procesando lote:</strong> {$offset} - " . ($offset + $batch_size) . "</p>";

// Obtener posts del lote actual
$result = $wordpress->query("
    SELECT ID, post_title, post_content
    FROM wp_posts
    WHERE post_type = 'post'
    AND post_status = 'publish'
    AND (
        post_content LIKE '%data:image%'
        OR post_content LIKE '%/images/%'
        OR post_content LIKE '%images/zoo/%'
    )
    ORDER BY ID
    LIMIT {$batch_size} OFFSET {$offset}
");

$stats = [
    'base64_replaced' => 0,
    'joomla_paths_fixed' => 0,
    'external_urls_found' => 0,
    'errors' => 0,
    'no_changes' => 0
];

echo "<table>";
echo "<tr><th width='60'>ID</th><th width='250'>Título</th><th>Cambios</th><th width='100'>Estado</th></tr>";

while ($row = $result->fetch_assoc()) {
    $post_id = $row['ID'];
    $titulo = mb_substr($row['post_title'], 0, 50);
    $contenido_original = $row['post_content'];
    $contenido_nuevo = $contenido_original;
    $cambios = [];

    // 1. Buscar el artículo correspondiente en Joomla para obtener las URLs originales
    $titulo_buscar = $joomla->real_escape_string($row['post_title']);
    $joomla_result = $joomla->query("
        SELECT id, name, elements
        FROM jos_zoo_item
        WHERE name = '{$titulo_buscar}'
        OR name LIKE '{$titulo_buscar}%'
        LIMIT 1
    ");

    $joomla_images = [];
    if ($joomla_row = $joomla_result->fetch_assoc()) {
        // Extraer todas las URLs de imágenes del JSON de Joomla
        $elements = $joomla_row['elements'];

        // Buscar imágenes en paths locales de Joomla
        if (preg_match_all('#images/zoo/[^\s"\'<>]+\.(?:jpg|jpeg|png|gif)#i', $elements, $matches)) {
            $joomla_images = array_merge($joomla_images, $matches[0]);
        }

        // Buscar también con /images/
        if (preg_match_all('#/images/[^\s"\'<>]+\.(?:jpg|jpeg|png|gif)#i', $elements, $matches)) {
            $joomla_images = array_merge($joomla_images, $matches[0]);
        }

        $joomla_images = array_unique($joomla_images);
    }

    // 2. Reemplazar imágenes BASE64 con URLs de Joomla
    if (preg_match_all('/<img[^>]+src=["\']data:image\/[^;]+;base64,[^"\']+["\'][^>]*>/i', $contenido_nuevo, $base64_matches)) {
        foreach ($base64_matches[0] as $index => $img_tag) {
            if (!empty($joomla_images[$index])) {
                // Convertir ruta de Joomla a WordPress
                $joomla_path = $joomla_images[$index];
                $wp_path = str_replace('images/', '/wp-content/uploads/joomla-images/', $joomla_path);
                $wp_path = str_replace('/images/', '/wp-content/uploads/joomla-images/', $wp_path);

                // Crear nuevo tag de imagen
                $new_img = preg_replace(
                    '/src=["\']data:image\/[^;]+;base64,[^"\']+["\']/i',
                    'src="' . $wp_path . '"',
                    $img_tag
                );

                $contenido_nuevo = str_replace($img_tag, $new_img, $contenido_nuevo);
                $cambios[] = "<span class='success'>BASE64 → " . basename($wp_path) . "</span>";
                $stats['base64_replaced']++;
            }
        }
    }

    // 3. Reemplazar rutas de Joomla que ya están como URL (no base64)
    // Patrón: src="images/zoo/..." o src="/images/..."
    $patterns = [
        // images/zoo/... (sin slash inicial)
        '/(src=["\'])images\/(zoo\/[^\s"\'<>]+\.(?:jpg|jpeg|png|gif))(["\'])/i',
        // /images/zoo/... (con slash inicial)
        '/(src=["\'])\/images\/(zoo\/[^\s"\'<>]+\.(?:jpg|jpeg|png|gif))(["\'])/i',
        // images/stories/...
        '/(src=["\'])images\/(stories\/[^\s"\'<>]+\.(?:jpg|jpeg|png|gif))(["\'])/i',
        '/(src=["\'])\/images\/(stories\/[^\s"\'<>]+\.(?:jpg|jpeg|png|gif))(["\'])/i',
        // Cualquier /images/...
        '/(src=["\'])\/images\/([^\s"\'<>]+\.(?:jpg|jpeg|png|gif))(["\'])/i',
        '/(src=["\'])images\/([^\s"\'<>]+\.(?:jpg|jpeg|png|gif))(["\'])/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $contenido_nuevo, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $old_path = $match[0];
                $new_path = $match[1] . '/wp-content/uploads/joomla-images/' . $match[2] . $match[3];

                if (strpos($contenido_nuevo, $old_path) !== false) {
                    $contenido_nuevo = str_replace($old_path, $new_path, $contenido_nuevo);
                    $cambios[] = "<span class='info'>Ruta Joomla → " . basename($match[2]) . "</span>";
                    $stats['joomla_paths_fixed']++;
                }
            }
        }
    }

    // 4. También buscar en atributos data-src, data-lazy-src, etc.
    $data_patterns = [
        '/(data-src=["\'])images\/([^\s"\'<>]+\.(?:jpg|jpeg|png|gif))(["\'])/i',
        '/(data-src=["\'])\/images\/([^\s"\'<>]+\.(?:jpg|jpeg|png|gif))(["\'])/i',
        '/(data-lazy-src=["\'])images\/([^\s"\'<>]+\.(?:jpg|jpeg|png|gif))(["\'])/i',
        '/(data-lazy-src=["\'])\/images\/([^\s"\'<>]+\.(?:jpg|jpeg|png|gif))(["\'])/i',
    ];

    foreach ($data_patterns as $pattern) {
        if (preg_match_all($pattern, $contenido_nuevo, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $old_path = $match[0];
                $new_path = $match[1] . '/wp-content/uploads/joomla-images/' . $match[2] . $match[3];
                $contenido_nuevo = str_replace($old_path, $new_path, $contenido_nuevo);
                $cambios[] = "<span class='info'>data-attr → " . basename($match[2]) . "</span>";
                $stats['joomla_paths_fixed']++;
            }
        }
    }

    // Determinar estado y actualizar si hay cambios
    if ($contenido_nuevo !== $contenido_original) {
        if (!$dry_run) {
            $contenido_safe = $wordpress->real_escape_string($contenido_nuevo);
            $update_result = $wordpress->query("UPDATE wp_posts SET post_content = '{$contenido_safe}' WHERE ID = {$post_id}");

            if ($update_result) {
                $status = "<span class='success'>✅ Actualizado</span>";
            } else {
                $status = "<span class='error'>❌ Error DB</span>";
                $stats['errors']++;
            }
        } else {
            $status = "<span class='warning'>👁️ Vista previa</span>";
        }

        $cambios_html = implode("<br>", $cambios);
    } else {
        $status = "<span class='info'>⏭️ Sin cambios</span>";
        $cambios_html = "-";
        $stats['no_changes']++;
    }

    echo "<tr>";
    echo "<td>{$post_id}</td>";
    echo "<td>{$titulo}</td>";
    echo "<td>{$cambios_html}</td>";
    echo "<td>{$status}</td>";
    echo "</tr>";
}

echo "</table>";

// Resumen
echo "<h2>📊 Resumen del Lote</h2>";
echo "<ul>";
echo "<li><strong>BASE64 reemplazados:</strong> <span class='success'>{$stats['base64_replaced']}</span></li>";
echo "<li><strong>Rutas Joomla corregidas:</strong> <span class='info'>{$stats['joomla_paths_fixed']}</span></li>";
echo "<li><strong>Sin cambios necesarios:</strong> {$stats['no_changes']}</li>";
echo "<li><strong>Errores:</strong> <span class='error'>{$stats['errors']}</span></li>";
echo "</ul>";

// Navegación entre lotes
$next_offset = $offset + $batch_size;
$prev_offset = max(0, $offset - $batch_size);

echo "<h2>🔄 Navegación</h2>";
echo "<p>";
if ($offset > 0) {
    $dry_param = $dry_run ? '1' : '0';
    echo "<a href='?offset={$prev_offset}&dry_run={$dry_param}' class='btn btn-warning'>⬅️ Lote Anterior</a> ";
}
if ($next_offset < $total_posts) {
    $dry_param = $dry_run ? '1' : '0';
    echo "<a href='?offset={$next_offset}&dry_run={$dry_param}' class='btn btn-primary'>➡️ Siguiente Lote</a> ";
}
echo "</p>";

// Botón para procesar todo automáticamente
if ($dry_run) {
    echo "<h2>🚀 Ejecutar Todo</h2>";
    echo "<p><a href='?dry_run=0&offset=0' class='btn btn-success'>▶️ EJECUTAR TODOS LOS CAMBIOS (desde el inicio)</a></p>";
    echo "<p style='color:#666;'>Esto procesará todos los posts en lotes de {$batch_size}.</p>";
}

// Auto-continuar si no es dry_run y hay más posts
if (!$dry_run && $next_offset < $total_posts) {
    echo "<script>
        setTimeout(function() {
            if (confirm('¿Continuar con el siguiente lote? ({$next_offset} de {$total_posts})')) {
                window.location.href = '?offset={$next_offset}&dry_run=0';
            }
        }, 2000);
    </script>";
}

// Si terminamos todos los lotes
if ($next_offset >= $total_posts) {
    echo "<div style='background:#d4edda;padding:20px;border-radius:5px;margin:20px 0;'>";
    echo "<h2 style='color:#155724;'>🎉 ¡Proceso Completado!</h2>";
    echo "<p>Se han procesado todos los posts.</p>";
    echo "<p><a href='fix-featured-images-v3.php' class='btn btn-primary'>▶️ Ahora ejecutar: Asignar Imágenes Destacadas</a></p>";
    echo "</div>";
}

$wordpress->close();
$joomla->close();

echo "</body></html>";
?>
