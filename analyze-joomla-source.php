<?php
/**
 * Analyze Joomla Source Integrity
 *
 * This script is READ-ONLY. It scans the Joomla database (Zoo Items) and
 * verifies if the referenced images physically exist in the local filesystem.
 */

// Load config but do not run any migration logic
require_once('migration-config.php');

// Increase limits for file scanning
set_time_limit(600);
ini_set('memory_limit', '512M');

echo "<h1>Análisis de Integridad de Joomla (Solo Lectura)</h1>";
echo "<p>Ruta Local Configurada: <strong>" . JOOMLA_LOCAL_PATH . "</strong></p>";
echo "<style>
    body { font-family: sans-serif; padding: 20px; }
    .stat-box { background: #f9f9f9; border: 1px solid #ddd; padding: 15px; margin-bottom: 20px; border-radius: 5px; }
    .error { color: #d63638; }
    .success { color: #00a32a; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { border: 1px solid #ccc; padding: 8px; text-align: left; font-size: 13px; }
    th { background: #f0f0f0; }
    details { margin-top: 10px; cursor: pointer; }
</style>";

// Connect to Joomla
$jdb = get_joomla_connection();

// Stats
$total_items = 0;
$items_with_images = 0;
$total_images_found = 0;
$total_images_missing = 0;
$missing_files = [];

// Query Zoo Items
$q = "SELECT id, name, alias, elements, type, state
      FROM jos_zoo_item
      WHERE state = 1 AND type IN ('article', 'blog-post', 'page')
      ORDER BY id DESC"; // Newest first
$result = $jdb->query($q);

if (!$result) {
    die("Error consultando Joomla: " . $jdb->error);
}

$total_items = $result->num_rows;

echo "<div class='stat-box'><h3>Analizando $total_items artículos...</h3>";
echo "<div id='progress'></div>";

$count = 0;

while ($row = $result->fetch_assoc()) {
    $count++;
    if ($count % 500 == 0) {
        echo "<script>document.getElementById('progress').innerHTML = 'Procesados: $count / $total_items';</script>";
        flush();
    }

    $content = $row['elements']; // Raw JSON/Content

    // Find images
    // Matches: src="/images/...", src="images/...", src="http.../images/..."
    // We focus on relative paths starting with images/ or /images/
    preg_match_all('#(?<=["\'])/?images/([^"\']+\.(jpg|jpeg|png|gif))#i', $content, $matches);

    if (!empty($matches[1])) {
        $items_with_images++;
        $unique_imgs = array_unique($matches[1]);

        foreach ($unique_imgs as $img_rel) {
            // Construct full local path
            $full_path = JOOMLA_LOCAL_PATH . '/images/' . $img_rel;

            // Fix slashes for Windows
            $full_path = str_replace('/', DIRECTORY_SEPARATOR, $full_path);
            $full_path = str_replace('\\', DIRECTORY_SEPARATOR, $full_path);

            if (file_exists($full_path)) {
                $total_images_found++;
            } else {
                $total_images_missing++;
                // Store first 100 missing files details
                if (count($missing_files) < 100) {
                    $missing_files[] = [
                        'item_id' => $row['id'],
                        'item_name' => $row['name'],
                        'file' => $img_rel,
                        'full_path' => $full_path
                    ];
                }
            }
        }
    }
}

echo "</div>";

// Report
echo "<div class='stat-box'>";
echo "<h2>Resultados del Análisis</h2>";
echo "<ul>";
echo "<li><strong>Total Artículos Escaneados:</strong> $total_items</li>";
echo "<li><strong>Artículos con Referencias a Imágenes:</strong> $items_with_images</li>";
echo "<li><strong>Imágenes Encontradas (Físicamente):</strong> <span class='success'>$total_images_found</span></li>";
echo "<li><strong>Imágenes Faltantes (No existen en disco):</strong> <span class='error'>$total_images_missing</span></li>";
echo "</ul>";
echo "</div>";

if ($total_images_missing > 0) {
    echo "<div class='stat-box' style='border-color: red;'>";
    echo "<h3 class='error'>Archivos Faltantes (Muestra de los primeros 100)</h3>";
    echo "<p>Estas imágenes están en la base de datos de Joomla, pero NO se encontraron en la carpeta <code>" . JOOMLA_LOCAL_PATH . "</code>.</p>";
    echo "<table>
            <tr><th>ID Artículo</th><th>Título</th><th>Archivo Faltante</th><th>Ruta Buscada</th></tr>";

    foreach ($missing_files as $miss) {
        echo "<tr>
                <td>{$miss['item_id']}</td>
                <td>{$miss['item_name']}</td>
                <td class='error'>{$miss['file']}</td>
                <td style='font-size:10px; color:#666;'>{$miss['full_path']}</td>
              </tr>";
    }

    echo "</table>";

    if ($total_images_missing > 100) {
        echo "<p>... y " . ($total_images_missing - 100) . " más.</p>";
    }
    echo "</div>";
} else {
    echo "<div class='stat-box success'><h3>¡Excelente! Todas las imágenes referenciadas existen en el disco.</h3></div>";
}

?>
