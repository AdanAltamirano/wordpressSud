<?php
/**
 * Script para migrar Tags de ZOO a WordPress
 *
 * COPIAR A: C:\inetpub\wwwroot\wordpress\migrate-tags.php
 * EJECUTAR: http://localhost/wordpress/migrate-tags.php
 *
 * Paso 1: Crea los tags únicos en WordPress
 * Paso 2: Asigna los tags a los posts correspondientes
 */

set_time_limit(300);
ini_set('max_execution_time', 300);
ini_set('memory_limit', '512M');
define('WP_IMPORTING', true);

require_once('wp-load.php');

// Conexión a Joomla MySQL
$joomla_db = new mysqli('127.0.0.1', 'root', 'yue02', 'sudcalifornios', 3306);

if ($joomla_db->connect_error) {
    die("Error de conexión: " . $joomla_db->connect_error);
}

$joomla_db->set_charset("utf8");

$step = isset($_GET['step']) ? intval($_GET['step']) : 1;

echo "<h1>Migración de Tags ZOO a WordPress</h1>";
echo "<pre>";

// ============================================
// PASO 1: Crear los tags únicos en WordPress
// ============================================
if ($step == 1) {
    echo "=== PASO 1: Creando tags únicos ===\n\n";

    // Obtener todos los tags únicos de ZOO
    $query = "SELECT DISTINCT name FROM jos_zoo_tag ORDER BY name";
    $result = $joomla_db->query($query);

    $created = 0;
    $skipped = 0;
    $errors = 0;

    while ($row = $result->fetch_assoc()) {
        $tag_name = trim($row['name']);

        if (empty($tag_name)) {
            continue;
        }

        // Crear slug limpio
        $tag_slug = sanitize_title($tag_name);

        // Verificar si ya existe
        $existing = get_term_by('slug', $tag_slug, 'post_tag');

        if ($existing) {
            echo "EXISTE: $tag_name (ID: {$existing->term_id})\n";
            $skipped++;
            continue;
        }

        // Crear el tag
        $term = wp_insert_term($tag_name, 'post_tag', array(
            'slug' => $tag_slug
        ));

        if (is_wp_error($term)) {
            echo "ERROR: $tag_name - " . $term->get_error_message() . "\n";
            $errors++;
        } else {
            echo "OK: $tag_name (ID: {$term['term_id']})\n";
            $created++;
        }
    }

    echo "\n========================================\n";
    echo "Tags creados: $created\n";
    echo "Tags existentes: $skipped\n";
    echo "Errores: $errors\n";
    echo "========================================\n";

    echo "\n<a href='migrate-tags.php?step=2' style='font-size:20px; background:#4CAF50; color:white; padding:10px 20px; text-decoration:none;'>>>> PASO 2: Asignar tags a posts <<<</a>\n";
}

// ============================================
// PASO 2: Asignar tags a los posts
// ============================================
if ($step == 2) {
    echo "=== PASO 2: Asignando tags a posts ===\n\n";

    $batch_size = 500;
    $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;

    // Contar total de relaciones
    $count_result = $joomla_db->query("SELECT COUNT(*) as total FROM jos_zoo_tag");
    $total = $count_result->fetch_assoc()['total'];

    echo "Total de relaciones tag-item: $total\n";
    echo "Procesando desde offset: $offset\n\n";

    // Obtener relaciones tag-item
    $query = "SELECT t.item_id, t.name as tag_name, i.alias as item_alias
              FROM jos_zoo_tag t
              JOIN jos_zoo_item i ON t.item_id = i.id
              ORDER BY t.item_id
              LIMIT $batch_size OFFSET $offset";

    $result = $joomla_db->query($query);

    $assigned = 0;
    $not_found = 0;
    $tag_not_found = 0;

    global $wpdb;

    while ($row = $result->fetch_assoc()) {
        $item_alias = $row['item_alias'];
        $tag_name = trim($row['tag_name']);

        // Obtener el post de WordPress por su slug
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'post'",
            $item_alias
        ));

        if (!$post_id) {
            $not_found++;
            continue;
        }

        // Obtener el tag de WordPress
        $tag_slug = sanitize_title($tag_name);
        $tag = get_term_by('slug', $tag_slug, 'post_tag');

        if (!$tag) {
            // Intentar por nombre
            $tag = get_term_by('name', $tag_name, 'post_tag');
        }

        if (!$tag) {
            $tag_not_found++;
            continue;
        }

        // Asignar tag al post (append = true para no eliminar otros)
        wp_set_post_tags($post_id, array($tag->term_id), true);
        $assigned++;
    }

    $progress = min(100, round((($offset + $batch_size) / $total) * 100));

    echo "========================================\n";
    echo "Progreso: $progress%\n";
    echo "Asignados: $assigned\n";
    echo "Posts no encontrados: $not_found\n";
    echo "Tags no encontrados: $tag_not_found\n";
    echo "========================================\n";

    $next_offset = $offset + $batch_size;
    if ($next_offset < $total) {
        echo "\n<a href='migrate-tags.php?step=2&offset=$next_offset' style='font-size:20px; background:#2196F3; color:white; padding:10px 20px; text-decoration:none;'>>>> CONTINUAR <<<</a>\n";
        echo "<script>setTimeout(function(){ window.location.href='migrate-tags.php?step=2&offset=$next_offset'; }, 2000);</script>";
    } else {
        echo "\n========================================\n";
        echo "¡¡¡ MIGRACIÓN DE TAGS COMPLETADA !!!\n";
        echo "========================================\n";
    }
}

// ============================================
// ESTADÍSTICAS (opcional)
// ============================================
if ($step == 3 || isset($_GET['stats'])) {
    echo "=== ESTADÍSTICAS DE TAGS ===\n\n";

    // Tags en Joomla
    $joomla_tags = $joomla_db->query("SELECT COUNT(DISTINCT name) as total FROM jos_zoo_tag")->fetch_assoc()['total'];
    $joomla_relations = $joomla_db->query("SELECT COUNT(*) as total FROM jos_zoo_tag")->fetch_assoc()['total'];

    echo "Joomla ZOO:\n";
    echo "  - Tags únicos: $joomla_tags\n";
    echo "  - Relaciones tag-item: $joomla_relations\n\n";

    // Tags en WordPress
    $wp_tags = wp_count_terms('post_tag');
    global $wpdb;
    $wp_relations = $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
        JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
        WHERE tt.taxonomy = 'post_tag'
    ");

    echo "WordPress:\n";
    echo "  - Tags: $wp_tags\n";
    echo "  - Relaciones tag-post: $wp_relations\n";
}

echo "\n</pre>";

echo "<p><a href='migrate-tags.php?stats=1'>Ver estadísticas</a></p>";

$joomla_db->close();
?>
