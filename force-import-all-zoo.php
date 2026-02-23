<?php
/**
 * FORCE IMPORT ALL ZOO - Joomla -> WordPress
 *
 * Script dedicado para importar TODOS los artículos de Joomla Zoo sin excepción.
 * Incluye lógica para recuperar imágenes base64, asignar imágenes destacadas,
 * y forzar la sincronización de contenido incluso si ya existe.
 */

set_time_limit(600);
ini_set('memory_limit', '2048M');
ini_set('pcre.backtrack_limit', '10000000');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . '/wp-load.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/media.php');

// Load shared functions and config
require_once(__DIR__ . '/migration-functions.php');

$action = isset($_GET['action']) ? $_GET['action'] : 'dashboard';
$offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
$batch  = isset($_GET['batch'])  ? intval($_GET['batch'])  : 50;

// ============================================================
// DASHBOARD
// ============================================================
function do_dashboard() {
    global $wpdb;
    page_header('Force Import Dashboard');
    $j = jdb();

    // Stats
    $j_count = $j->query("SELECT COUNT(*) c FROM jos_zoo_item")->fetch_assoc()['c'];
    $wp_count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key='_joomla_zoo_id'");

    // Types breakdown
    $types_res = $j->query("SELECT type, COUNT(*) c FROM jos_zoo_item GROUP BY type");

    echo "<div class='stat-grid'>";
    echo "<div class='stat-box'><div class='number'>$j_count</div><div class='label'>Total Joomla Items</div></div>";
    echo "<div class='stat-box'><div class='number'>$wp_count</div><div class='label'>Total Imported (WP)</div></div>";
    echo "</div>";

    echo "<div class='card'><h2>Tipos en Joomla</h2><div class='log'>";
    while($row = $types_res->fetch_assoc()) {
        lm("Type: {$row['type']} - Count: {$row['c']}", 'info');
    }
    echo "</div></div>";

    echo "<div class='card'><h2>Acciones</h2>";
    echo "<p>Este script importará <strong>TODOS</strong> los items de Zoo, sin importar el estado o tipo.</p>";
    echo "<p class='warn'>Atención: Si el artículo ya existe en WordPress, se <strong>sobreescribirá</strong> el contenido para asegurar que esté completo (imágenes, base64, etc).</p>";
    echo "<a href='?action=import_all' class='btn btn-red'>Iniciar Importación Masiva</a>";
    echo "</div>";

    pf();
}

// ============================================================
// IMPORT ALL
// ============================================================
function do_import_all($offset, $batch) {
    global $wpdb;
    page_header('Importando TODO...');
    $j = jdb();

    // Get ALL items
    $total = $j->query("SELECT COUNT(*) c FROM jos_zoo_item")->fetch_assoc()['c'];

    // Select batch
    // Ordered by ID to keep consistency
    $sql = "SELECT * FROM jos_zoo_item ORDER BY id ASC LIMIT $offset, $batch";
    $result = $j->query($sql);

    echo "<div class='card'><h2>Procesando lote $offset - " . ($offset + $batch) . " de $total</h2><div class='log'>";

    $processed = 0;
    $updated = 0;
    $inserted = 0;

    $cat_map = get_option('joomla_zoo_category_map', []);

    while ($row = $result->fetch_assoc()) {
        $processed++;
        $zoo_id = intval($row['id']);

        // Extract content
        $content = extract_content($row['elements']);

        // Fix images paths
        $img_r = fix_joomla_image_urls($content);
        $content = $img_r['html'];

        // Determine WP status
        $wp_status = joomla_state_to_wp($row['state']);

        // Check if exists
        $existing_post_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_joomla_zoo_id' AND meta_value=%s LIMIT 1", $zoo_id));

        $post_data = [
            'post_title'    => $row['name'],
            'post_name'     => $row['alias'],
            'post_content'  => $content, // Base64 still in string, will fix after insert/update to have ID
            'post_status'   => $wp_status,
            'post_date'     => $row['created'],
            'post_date_gmt' => get_gmt_from_date($row['created']),
            'post_modified' => $row['modified'],
            'post_modified_gmt' => get_gmt_from_date($row['modified']),
            'post_type'     => 'post', // Default to post, could verify mapping if needed
        ];

        // Author mapping
        if ($row['created_by'] > 0) {
            $u = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $wpdb->usermeta WHERE meta_key='_joomla_user_id' AND meta_value=%s LIMIT 1", $row['created_by']));
            if ($u) $post_data['post_author'] = $u;
        }

        $pid = 0;
        if ($existing_post_id) {
            // Update
            $post_data['ID'] = $existing_post_id;
            wp_update_post($post_data);
            $pid = $existing_post_id;
            $updated++;
            lm("Updated: {$row['name']} (ID: $pid)", 'info');
        } else {
            // Insert
            $pid = wp_insert_post($post_data);
            if (!is_wp_error($pid)) {
                update_post_meta($pid, '_joomla_zoo_id', $zoo_id);
                $inserted++;
                lm("Inserted: {$row['name']} (ID: $pid)", 'success');
            } else {
                lm("Error inserting {$row['name']}: " . $pid->get_error_message(), 'error');
                continue;
            }
        }

        // NOW Fix Base64 and Featured Image
        // We do this AFTER insert/update so we have a valid Post ID
        if ($pid > 0) {
            // Re-read content to be sure (or use local variable)
            // fix_base64_in_string returns ['html' => ..., 'fixed' => ...]
            // Pass true for set_featured to ensure thumbnails are generated
            $b64_res = fix_base64_in_string($content, $pid, true);

            if ($b64_res['fixed'] > 0) {
                // Update content with fixed image URLs
                $wpdb->update($wpdb->posts, ['post_content' => $b64_res['html']], ['ID' => $pid]);
                lm("  -> Fixed {$b64_res['fixed']} base64 images & checked featured.", 'warn');
            }

            // Assign Categories
            $cq = $j->query("SELECT category_id FROM jos_zoo_category_item WHERE item_id=$zoo_id");
            $cats = [];
            if ($cq) {
                while ($cr = $cq->fetch_assoc()) {
                    if (isset($cat_map[$cr['category_id']])) {
                        $cats[] = intval($cat_map[$cr['category_id']]);
                    }
                }
            }
            if (!empty($cats)) {
                wp_set_post_categories($pid, $cats);
            }
        }
    }

    echo "</div></div>";

    // Progress bar
    $next_offset = $offset + $batch;
    $pct = min(100, round(($next_offset / $total) * 100));
    echo "<div class='card'><div class='progress'><div class='fill' style='width:{$pct}%'>$pct%</div></div>";

    if ($next_offset < $total) {
        $url = "?action=import_all&offset=$next_offset&batch=$batch";
        echo "<script>setTimeout(function(){window.location.href='$url';}, 1000);</script>";
        echo "<a href='$url' class='btn btn-blue'>Siguiente Lote ($next_offset)</a>";
        echo "<a href='?action=dashboard' class='btn btn-gray'>Pausar</a>";
    } else {
        echo "<p style='color:#7ee787;font-weight:bold'>IMPORTACIÓN COMPLETA.</p>";
        echo "<a href='?action=dashboard' class='btn btn-blue'>Volver al Dashboard</a>";
    }
    echo "</div>";

    pf();
}

// ============================================================
// ROUTER
// ============================================================
switch ($action) {
    case 'dashboard':  do_dashboard(); break;
    case 'import_all': do_import_all($offset, $batch); break;
    default:           do_dashboard();
}
