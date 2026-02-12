<?php
/**
 * Script para migrar categorías de ZOO a WordPress
 * y asignarlas a los posts correspondientes
 * Ejecutar desde: http://localhost/wordpress/migrate-categories.php
 */
 
set_time_limit(600);
ini_set('max_execution_time', 600);
ini_set('memory_limit', '512M');
define('WP_IMPORTING', true);
 
require_once('wp-load.php');
 
// Conexión a Joomla MySQL 5.7
$joomla_db = new mysqli('127.0.0.1', 'root', 'yue02', 'sudcalifornios', 3306);
 
if ($joomla_db->connect_error) {
    die("Error de conexión: " . $joomla_db->connect_error);
}
 
$joomla_db->set_charset("utf8");
 
$step = isset($_GET['step']) ? intval($_GET['step']) : 1;
 
echo "<h1>Migración de Categorías ZOO a WordPress</h1>";
echo "<pre>";
 
// ============================================
// PASO 1: Crear las categorías en WordPress
// ============================================
if ($step == 1) {
    echo "=== PASO 1: Creando categorías ===\n\n";
 
    // Obtener todas las categorías de ZOO
    $query = "SELECT id, name, alias, parent, description
              FROM jos_zoo_category
              WHERE published = 1
              ORDER BY parent, id";
 
    $result = $joomla_db->query($query);
 
    $created = 0;
    $skipped = 0;
    $category_map = array(); // Mapeo de ID Joomla -> ID WordPress
 
    while ($row = $result->fetch_assoc()) {
        $name = $row['name'];
        $slug = $row['alias'];
        $description = $row['description'] ?? '';
 
        // Verificar si ya existe
        $existing = get_term_by('slug', $slug, 'category');
 
        if ($existing) {
            $category_map[$row['id']] = $existing->term_id;
            $skipped++;
            continue;
        }
 
        // Determinar categoría padre en WordPress
        $parent_wp = 0;
        if ($row['parent'] > 0 && isset($category_map[$row['parent']])) {
            $parent_wp = $category_map[$row['parent']];
        }
 
        // Crear categoría
        $term = wp_insert_term($name, 'category', array(
            'slug' => $slug,
            'description' => $description,
            'parent' => $parent_wp
        ));
 
        if (is_wp_error($term)) {
            echo "ERROR: $name - " . $term->get_error_message() . "\n";
        } else {
            $category_map[$row['id']] = $term['term_id'];
            echo "OK: $name (ID: {$term['term_id']})\n";
            $created++;
        }
    }
 
    // Guardar el mapeo en una opción temporal
    update_option('zoo_category_map', $category_map);
 
    echo "\n========================================\n";
    echo "Categorías creadas: $created\n";
    echo "Categorías existentes: $skipped\n";
    echo "========================================\n";
 
    echo "\n<a href='migrate-categories.php?step=2' style='font-size:20px;'>>>> PASO 2: Asignar categorías a posts <<<</a>\n";
}
 
// ============================================
// PASO 2: Asignar categorías a los posts
// ============================================
if ($step == 2) {
    echo "=== PASO 2: Asignando categorías a posts ===\n\n";
 
    $batch_size = 500;
    $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
 
    // Recuperar el mapeo de categorías
    $category_map = get_option('zoo_category_map', array());
 
    if (empty($category_map)) {
        die("Error: No se encontró el mapeo de categorías. Ejecuta el Paso 1 primero.");
    }
 
    // Contar total de relaciones
    $count_result = $joomla_db->query("SELECT COUNT(*) as total FROM jos_zoo_category_item");
    $total = $count_result->fetch_assoc()['total'];
 
    echo "Total de relaciones: $total\n";
    echo "Procesando desde offset: $offset\n\n";
 
    // Obtener relaciones item-categoría
    $query = "SELECT ci.item_id, ci.category_id, i.alias as item_alias
              FROM jos_zoo_category_item ci
              JOIN jos_zoo_item i ON ci.item_id = i.id
              ORDER BY ci.item_id
              LIMIT $batch_size OFFSET $offset";
 
    $result = $joomla_db->query($query);
 
    $assigned = 0;
    $not_found = 0;
 
    global $wpdb;
 
    while ($row = $result->fetch_assoc()) {
        $item_alias = $row['item_alias'];
        $zoo_category_id = $row['category_id'];
 
        // Obtener el post de WordPress por su slug
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'post'",
            $item_alias
        ));
 
        if (!$post_id) {
            $not_found++;
            continue;
        }
 
        // Obtener la categoría de WordPress
        if (!isset($category_map[$zoo_category_id])) {
            continue;
        }
 
        $wp_category_id = $category_map[$zoo_category_id];
 
        // Asignar categoría al post (append = true para no eliminar otras)
        wp_set_post_categories($post_id, array($wp_category_id), true);
        $assigned++;
    }
 
    $progress = min(100, round((($offset + $batch_size) / $total) * 100));
 
    echo "========================================\n";
    echo "Progreso: $progress%\n";
    echo "Asignadas: $assigned | No encontrados: $not_found\n";
    echo "========================================\n";
 
    $next_offset = $offset + $batch_size;
    if ($next_offset < $total) {
        echo "\n<a href='migrate-categories.php?step=2&offset=$next_offset'>>>> CONTINUAR <<<</a>\n";
        echo "<script>setTimeout(function(){ window.location.href='migrate-categories.php?step=2&offset=$next_offset'; }, 1000);</script>";
    } else {
        echo "\n¡¡¡ MIGRACIÓN DE CATEGORÍAS COMPLETADA !!!\n";
 
        // Limpiar opción temporal
        delete_option('zoo_category_map');
    }
}
 
echo "</pre>";
$joomla_db->close();
?>