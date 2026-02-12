<?php
/**
 * Verificar contenido de posts específicos
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$wordpress = mysqli_init();
$wordpress->options(MYSQLI_SET_CHARSET_NAME, 'utf8');
$wordpress->real_connect('127.0.0.1', 'wpuser', 'MiPassword123', 'wordpress', 3308);
if ($wordpress->connect_error) die("Error: " . $wordpress->connect_error);

echo "<h2>VERIFICAR POSTS SIN IMAGEN</h2>";
echo "<style>body{font-family:Arial;font-size:12px;} pre{background:#f5f5f5;padding:10px;overflow:auto;max-height:300px;}</style>";

// Posts problemáticos
$posts = [
    'LA PALOMILLA' => 8723,
    'CHURIDO' => 4186
];

foreach ($posts as $nombre => $id) {
    echo "<h3>{$nombre} (ID: {$id})</h3>";

    $result = $wordpress->query("SELECT post_content FROM wp_posts WHERE ID = {$id}");
    if ($row = $result->fetch_assoc()) {
        $content = $row['post_content'];

        // Buscar imágenes
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/', $content, $matches)) {
            echo "<p><strong>Imágenes encontradas:</strong></p><ul>";
            foreach ($matches[1] as $img) {
                $tipo = '';
                if (strpos($img, 'data:image') === 0) {
                    $tipo = '<span style="color:red;">BASE64</span>';
                } elseif (strpos($img, '/images/') === 0) {
                    $tipo = '<span style="color:green;">LOCAL JOOMLA</span>';
                } elseif (preg_match('#^https?://#', $img)) {
                    $tipo = '<span style="color:blue;">EXTERNA</span>';
                }
                echo "<li>{$tipo}: " . htmlspecialchars(substr($img, 0, 100)) . "...</li>";
            }
            echo "</ul>";
        } else {
            echo "<p style='color:orange;'>NO SE ENCONTRARON IMÁGENES EN EL CONTENIDO</p>";
        }

        // Verificar thumbnail asignado
        $thumb = $wordpress->query("SELECT meta_value FROM wp_postmeta WHERE post_id = {$id} AND meta_key = '_thumbnail_id'");
        if ($t = $thumb->fetch_assoc()) {
            echo "<p><strong>Thumbnail ID asignado:</strong> {$t['meta_value']}</p>";

            if ($t['meta_value'] > 0) {
                $att = $wordpress->query("SELECT guid FROM wp_posts WHERE ID = {$t['meta_value']}");
                if ($a = $att->fetch_assoc()) {
                    echo "<p><strong>URL del attachment:</strong> " . htmlspecialchars($a['guid']) . "</p>";
                }
            }
        } else {
            echo "<p style='color:red;'><strong>NO TIENE THUMBNAIL ASIGNADO</strong></p>";
        }

        echo "<details><summary>Ver contenido HTML</summary><pre>" . htmlspecialchars(substr($content, 0, 2000)) . "</pre></details>";
    }
    echo "<hr>";
}

$wordpress->close();
?>
