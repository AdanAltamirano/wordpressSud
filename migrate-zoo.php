<?php
// Aumentar tiempo de ejecución
set_time_limit(300);
ini_set('max_execution_time', 300);
ini_set('memory_limit', '512M');
define('WP_IMPORTING', true);

// Cargar WordPress
require_once('wp-load.php');

// Desactivar acciones pesadas
remove_all_actions('do_pings');
remove_all_actions('publish_post');

// Conexión a Joomla MySQL 5.7
$joomla_db = new mysqli('127.0.0.1', 'root', 'yue02', 'sudcalifornios', 3306);

if ($joomla_db->connect_error) {
    die("Error de conexión: " . $joomla_db->connect_error);
}

$joomla_db->set_charset("utf8");

$batch_size = 100; // Reducido para evitar timeouts
$offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;

echo "<h1>Migración de ZOO Items a WordPress</h1>";
echo "<pre>";

$count_result = $joomla_db->query("SELECT COUNT(*) as total FROM jos_zoo_item WHERE state = 1 AND type IN ('article', 'page')");
$total = $count_result->fetch_assoc()['total'];
echo "Total: $total | Offset: $offset\n\n";

$query = "SELECT id, name, alias, type, elements, created, modified, hits
          FROM jos_zoo_item
          WHERE state = 1 AND type IN ('article', 'page')
          ORDER BY id
          LIMIT $batch_size OFFSET $offset";

$result = $joomla_db->query($query);

$imported = 0;
$skipped = 0;
$errors = 0;

while ($row = $result->fetch_assoc()) {
    $slug = $row['alias'];

    global $wpdb;
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s",
        $slug
    ));

    if ($exists) {
        $skipped++;
        continue;
    }

    $elements = json_decode($row['elements'], true);
    $content = '';
    if ($elements) {
        foreach ($elements as $element) {
            if (isset($element['0']['value'])) $content .= $element['0']['value'];
            if (isset($element['1']['value'])) $content .= "\n" . $element['1']['value'];
        }
    }

    $content = stripslashes(str_replace('\\/', '/', $content));
    $wp_post_type = ($row['type'] == 'page') ? 'page' : 'post';

    $post_id = wp_insert_post(array(
        'post_title'    => wp_strip_all_tags($row['name']),
        'post_content'  => $content,
        'post_status'   => 'publish',
        'post_date'     => $row['created'],
        'post_name'     => $slug,
        'post_type'     => $wp_post_type,
        'post_author'   => 1,
    ), true);

    if (is_wp_error($post_id)) {
        $errors++;
    } else {
        update_post_meta($post_id, '_joomla_zoo_id', $row['id']);
        $imported++;
    }
}

$progress = min(100, round((($offset + $batch_size) / $total) * 100));

echo "========================================\n";
echo "Progreso: $progress%\n";
echo "Importados: $imported | Omitidos: $skipped | Errores: $errors\n";
echo "========================================\n";

$next_offset = $offset + $batch_size;
if ($next_offset < $total) {
    echo "\n<a href='migrate-zoo.php?offset=$next_offset'>>>> CONTINUAR <<<</a>\n";
    echo "<script>setTimeout(function(){ window.location.href='migrate-zoo.php?offset=$next_offset'; }, 1000);</script>";
} else {
    echo "\n¡¡¡ MIGRACIÓN COMPLETADA !!!\n";
}

echo "</pre>";
$joomla_db->close();
?>
