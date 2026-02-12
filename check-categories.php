<?php
/**
 * Script para verificar el estado de las categorías en WordPress
 * Especialmente la categoría "Temas" y sus subcategorías
 *
 * COPIAR A: C:\inetpub\wwwroot\wordpress\check-categories.php
 * EJECUTAR: http://localhost/wordpress/check-categories.php
 */

require_once('wp-load.php');

echo "<h1>Diagnóstico de Categorías en WordPress</h1>";
echo "<pre>";

// Conexión a Joomla para comparar
$joomla_db = new mysqli('127.0.0.1', 'root', 'yue02', 'sudcalifornios', 3306);
if ($joomla_db->connect_error) {
    die("Error conectando a Joomla: " . $joomla_db->connect_error);
}
$joomla_db->set_charset("utf8");

echo "===========================================\n";
echo "1. CATEGORÍAS EN WORDPRESS\n";
echo "===========================================\n\n";

// Obtener todas las categorías de WordPress
$wp_categories = get_categories(array(
    'hide_empty' => false,
    'orderby' => 'name',
    'order' => 'ASC'
));

echo "Total de categorías en WordPress: " . count($wp_categories) . "\n\n";

// Buscar específicamente "Temas"
$temas_cat = get_term_by('slug', 'temas', 'category');
if ($temas_cat) {
    echo "✓ Categoría 'Temas' encontrada:\n";
    echo "  - ID: {$temas_cat->term_id}\n";
    echo "  - Nombre: {$temas_cat->name}\n";
    echo "  - Slug: {$temas_cat->slug}\n";
    echo "  - Posts directos: {$temas_cat->count}\n";
    echo "  - Parent ID: {$temas_cat->parent}\n\n";

    // Buscar subcategorías de Temas
    $subcats = get_categories(array(
        'hide_empty' => false,
        'parent' => $temas_cat->term_id
    ));

    echo "Subcategorías de 'Temas' en WordPress: " . count($subcats) . "\n";
    foreach ($subcats as $subcat) {
        echo "  - {$subcat->name} (slug: {$subcat->slug}, posts: {$subcat->count})\n";
    }
} else {
    echo "✗ Categoría 'Temas' NO encontrada en WordPress\n";
}

echo "\n===========================================\n";
echo "2. CATEGORÍAS EN JOOMLA ZOO (Temas)\n";
echo "===========================================\n\n";

// Buscar "Temas" en Joomla ZOO
$query = "SELECT id, name, alias, parent FROM jos_zoo_category WHERE alias = 'temas' OR name LIKE '%Temas%'";
$result = $joomla_db->query($query);

$temas_joomla_id = null;
while ($row = $result->fetch_assoc()) {
    echo "Encontrado: ID={$row['id']}, Nombre='{$row['name']}', Alias='{$row['alias']}', Parent={$row['parent']}\n";
    if ($row['alias'] == 'temas') {
        $temas_joomla_id = $row['id'];
    }
}

if ($temas_joomla_id) {
    echo "\n--- Subcategorías de 'Temas' (parent=$temas_joomla_id) en Joomla ---\n";
    $query = "SELECT id, name, alias, parent FROM jos_zoo_category WHERE parent = $temas_joomla_id ORDER BY name";
    $result = $joomla_db->query($query);

    $joomla_subcats = array();
    while ($row = $result->fetch_assoc()) {
        $joomla_subcats[] = $row;
        echo "  - ID={$row['id']}: {$row['name']} (alias: {$row['alias']})\n";
    }
    echo "\nTotal subcategorías de Temas en Joomla: " . count($joomla_subcats) . "\n";

    // Contar artículos en subcategorías de Temas
    echo "\n--- Artículos en subcategorías de Temas ---\n";
    $query = "SELECT c.name, c.alias, COUNT(ci.item_id) as total
              FROM jos_zoo_category c
              LEFT JOIN jos_zoo_category_item ci ON c.id = ci.category_id
              WHERE c.parent = $temas_joomla_id
              GROUP BY c.id
              ORDER BY total DESC
              LIMIT 20";
    $result = $joomla_db->query($query);

    $total_items_temas = 0;
    while ($row = $result->fetch_assoc()) {
        echo "  - {$row['name']}: {$row['total']} artículos\n";
        $total_items_temas += $row['total'];
    }
    echo "\nTotal artículos en subcategorías de Temas: $total_items_temas\n";
}

echo "\n===========================================\n";
echo "3. COMPARACIÓN DE MAPEO\n";
echo "===========================================\n\n";

// Verificar si el mapeo se guardó
$category_map = get_option('zoo_category_map', array());
if (!empty($category_map)) {
    echo "✓ Mapeo de categorías encontrado (" . count($category_map) . " entradas)\n";
    if ($temas_joomla_id && isset($category_map[$temas_joomla_id])) {
        echo "  - Temas (Joomla ID $temas_joomla_id) -> WordPress ID {$category_map[$temas_joomla_id]}\n";
    }
} else {
    echo "✗ No hay mapeo de categorías guardado (se borró al completar migración)\n";
}

echo "\n===========================================\n";
echo "4. POSTS EN WORDPRESS CON CATEGORÍA 'TEMAS'\n";
echo "===========================================\n\n";

if ($temas_cat) {
    // Posts directamente en "Temas"
    $posts_temas = get_posts(array(
        'category' => $temas_cat->term_id,
        'numberposts' => -1,
        'post_status' => 'publish'
    ));
    echo "Posts directamente en 'Temas': " . count($posts_temas) . "\n";

    // Posts en subcategorías de Temas
    $subcats = get_categories(array(
        'hide_empty' => false,
        'parent' => $temas_cat->term_id
    ));

    $all_temas_ids = array($temas_cat->term_id);
    foreach ($subcats as $subcat) {
        $all_temas_ids[] = $subcat->term_id;
    }

    $posts_all_temas = get_posts(array(
        'category__in' => $all_temas_ids,
        'numberposts' => -1,
        'post_status' => 'publish'
    ));
    echo "Posts en 'Temas' + subcategorías: " . count($posts_all_temas) . "\n";
}

echo "\n===========================================\n";
echo "5. RESUMEN DEL PROBLEMA\n";
echo "===========================================\n\n";

global $wpdb;
$total_posts_wp = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'");
$posts_sin_categoria = $wpdb->get_var("
    SELECT COUNT(*) FROM {$wpdb->posts} p
    LEFT JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
    LEFT JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'category'
    WHERE p.post_type = 'post' AND p.post_status = 'publish' AND tt.term_id IS NULL
");

echo "Total posts publicados en WordPress: $total_posts_wp\n";
echo "Posts SIN ninguna categoría: $posts_sin_categoria\n";

// Categoría por defecto (Uncategorized)
$uncategorized = get_term_by('slug', 'uncategorized', 'category');
if ($uncategorized) {
    echo "Posts en 'Uncategorized': {$uncategorized->count}\n";
}

echo "\n</pre>";

$joomla_db->close();
?>
