<?php
/**
 * Script para reparar la jerarquía de categorías en WordPress
 * Asigna las subcategorías de "Categorías" (ID=3 en Joomla) como hijas de "temas" en WordPress
 *
 * COPIAR A: C:\inetpub\wwwroot\wordpress\fix-category-hierarchy.php
 * EJECUTAR: http://localhost/wordpress/fix-category-hierarchy.php
 */

set_time_limit(600);
ini_set('max_execution_time', 600);
ini_set('memory_limit', '512M');

require_once('wp-load.php');

// Conexión a Joomla (MySQL 5.7 en puerto 3306)
$joomla_db = new mysqli('127.0.0.1', 'root', 'yue02', 'sudcalifornios', 3306);

// Nota: WordPress usa MySQL 8 en puerto 3308 con usuario 'wpuser'
// pero usamos wp-load.php que ya tiene la conexión correcta
if ($joomla_db->connect_error) {
    die("Error conectando a Joomla: " . $joomla_db->connect_error);
}
$joomla_db->set_charset("utf8");

$step = isset($_GET['step']) ? intval($_GET['step']) : 0;

echo "<h1>Reparación de Jerarquía de Categorías</h1>";
echo "<pre>";

// ============================================
// PASO 0: Diagnóstico inicial
// ============================================
if ($step == 0) {
    echo "=== DIAGNÓSTICO INICIAL ===\n\n";

    // Verificar categoría "temas" en WordPress
    $temas_wp = get_term_by('slug', 'temas', 'category');
    if (!$temas_wp) {
        die("ERROR: No existe la categoría 'temas' en WordPress. Créala primero.");
    }

    echo "Categoría 'temas' en WordPress:\n";
    echo "  - ID: {$temas_wp->term_id}\n";
    echo "  - Nombre: {$temas_wp->name}\n";
    echo "  - Slug: {$temas_wp->slug}\n\n";

    // Contar subcategorías de ID=3 en Joomla
    $query = "SELECT COUNT(*) as total FROM jos_zoo_category WHERE parent = 3 AND published = 1";
    $result = $joomla_db->query($query);
    $joomla_count = $result->fetch_assoc()['total'];

    echo "Subcategorías de 'Categorías' (ID=3) en Joomla: $joomla_count\n\n";

    // Contar categorías sin padre en WordPress (excluyendo "Uncategorized" y "temas")
    global $wpdb;
    $wp_root_cats = $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->term_taxonomy} tt
        JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
        WHERE tt.taxonomy = 'category'
        AND tt.parent = 0
        AND t.slug NOT IN ('uncategorized', 'temas')
    ");

    echo "Categorías raíz en WordPress (sin padre): $wp_root_cats\n\n";

    echo "===========================================\n";
    echo "Este script hará lo siguiente:\n";
    echo "1. Leer las subcategorías de ID=3 en Joomla\n";
    echo "2. Buscar cada una en WordPress por su slug\n";
    echo "3. Asignarlas como hijas de 'temas' (ID: {$temas_wp->term_id})\n";
    echo "===========================================\n\n";

    echo "<a href='fix-category-hierarchy.php?step=1' style='font-size:20px; background:#4CAF50; color:white; padding:10px 20px; text-decoration:none;'>>>> INICIAR REPARACIÓN <<<</a>\n";
}

// ============================================
// PASO 1: Reparar jerarquía
// ============================================
if ($step == 1) {
    echo "=== REPARANDO JERARQUÍA ===\n\n";

    $batch_size = 200;
    $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;

    // Obtener categoría "temas" en WordPress
    $temas_wp = get_term_by('slug', 'temas', 'category');
    $temas_wp_id = $temas_wp->term_id;

    echo "Categoría destino: 'temas' (ID: $temas_wp_id)\n";
    echo "Procesando desde offset: $offset\n\n";

    // Contar total
    $count_result = $joomla_db->query("SELECT COUNT(*) as total FROM jos_zoo_category WHERE parent = 3 AND published = 1");
    $total = $count_result->fetch_assoc()['total'];

    // Obtener subcategorías de ID=3 en Joomla
    $query = "SELECT id, name, alias FROM jos_zoo_category
              WHERE parent = 3 AND published = 1
              ORDER BY id
              LIMIT $batch_size OFFSET $offset";
    $result = $joomla_db->query($query);

    $updated = 0;
    $not_found = 0;
    $already_ok = 0;
    $errors = 0;

    global $wpdb;

    while ($row = $result->fetch_assoc()) {
        $joomla_alias = $row['alias'];
        $joomla_name = $row['name'];

        // Buscar en WordPress por slug
        $wp_cat = get_term_by('slug', $joomla_alias, 'category');

        if (!$wp_cat) {
            echo "NO ENCONTRADA: '$joomla_name' (slug: $joomla_alias)\n";
            $not_found++;
            continue;
        }

        // Verificar si ya tiene el padre correcto
        if ($wp_cat->parent == $temas_wp_id) {
            $already_ok++;
            continue;
        }

        // Actualizar el padre
        $result_update = wp_update_term($wp_cat->term_id, 'category', array(
            'parent' => $temas_wp_id
        ));

        if (is_wp_error($result_update)) {
            echo "ERROR: '$joomla_name' - " . $result_update->get_error_message() . "\n";
            $errors++;
        } else {
            echo "OK: '$joomla_name' -> ahora es hija de 'temas'\n";
            $updated++;
        }
    }

    // Limpiar caché de términos
    clean_term_cache($temas_wp_id, 'category');

    $progress = min(100, round((($offset + $batch_size) / $total) * 100));

    echo "\n========================================\n";
    echo "Progreso: $progress% ($offset + $batch_size de $total)\n";
    echo "Actualizadas: $updated\n";
    echo "Ya correctas: $already_ok\n";
    echo "No encontradas: $not_found\n";
    echo "Errores: $errors\n";
    echo "========================================\n";

    $next_offset = $offset + $batch_size;
    if ($next_offset < $total) {
        echo "\n<a href='fix-category-hierarchy.php?step=1&offset=$next_offset' style='font-size:20px; background:#2196F3; color:white; padding:10px 20px; text-decoration:none;'>>>> CONTINUAR <<<</a>\n";
        echo "<script>setTimeout(function(){ window.location.href='fix-category-hierarchy.php?step=1&offset=$next_offset'; }, 1500);</script>";
    } else {
        echo "\n<a href='fix-category-hierarchy.php?step=2' style='font-size:20px; background:#FF9800; color:white; padding:10px 20px; text-decoration:none;'>>>> VERIFICAR RESULTADOS <<<</a>\n";
    }
}

// ============================================
// PASO 2: Verificación final
// ============================================
if ($step == 2) {
    echo "=== VERIFICACIÓN FINAL ===\n\n";

    $temas_wp = get_term_by('slug', 'temas', 'category');

    // Contar subcategorías de "temas" en WordPress
    $subcats = get_categories(array(
        'hide_empty' => false,
        'parent' => $temas_wp->term_id
    ));

    echo "Subcategorías de 'temas' en WordPress: " . count($subcats) . "\n\n";

    // Contar posts en temas + subcategorías
    $all_ids = array($temas_wp->term_id);
    foreach ($subcats as $subcat) {
        $all_ids[] = $subcat->term_id;
    }

    $posts = get_posts(array(
        'category__in' => $all_ids,
        'numberposts' => -1,
        'post_status' => 'publish'
    ));

    echo "Posts en 'temas' + subcategorías: " . count($posts) . "\n\n";

    // Mostrar primeras 20 subcategorías
    echo "Primeras 20 subcategorías de 'temas':\n";
    $count = 0;
    foreach ($subcats as $subcat) {
        if ($count >= 20) break;
        echo "  - {$subcat->name} ({$subcat->count} posts)\n";
        $count++;
    }

    echo "\n========================================\n";
    echo "¡REPARACIÓN COMPLETADA!\n";
    echo "========================================\n";

    echo "\nAhora ve a: <a href='/wordpress/index.php/temas/'>http://localhost/wordpress/index.php/temas/</a>\n";
}

echo "</pre>";
$joomla_db->close();
?>
