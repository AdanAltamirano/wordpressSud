<?php
/**
 * Joomla -> WordPress Incremental Sync Script
 *
 * Ejecutar desde el navegador: http://localhost/sync-joomla.php
 *
 * Modos de operación (via GET):
 *   ?action=audit          -> Analizar diferencias sin modificar nada
 *   ?action=sync_categories -> Sincronizar categorías faltantes
 *   ?action=sync_posts     -> Sincronizar posts faltantes (con imágenes)
 *   ?action=fix_images     -> Detectar y reparar imágenes rotas/faltantes
 *   ?action=fix_categories -> Reparar asignaciones de categorías en posts existentes
 *   ?action=sync_all       -> Ejecutar todo en secuencia
 *
 * Parámetros adicionales:
 *   &offset=N    -> Empezar desde el registro N (para paginación)
 *   &batch=N     -> Procesar N registros por lote (default: 50)
 *   &dry_run=1   -> Solo mostrar qué se haría, sin ejecutar cambios
 */

set_time_limit(600);
ini_set('memory_limit', '1024M');
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Load WordPress
require_once(__DIR__ . '/wp-load.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/media.php');
require_once(__DIR__ . '/migration-config.php');

// ============================================================
// CONFIGURATION
// ============================================================
$action    = isset($_GET['action']) ? $_GET['action'] : 'audit';
$offset    = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
$batch     = isset($_GET['batch']) ? intval($_GET['batch']) : 50;
$dry_run   = isset($_GET['dry_run']) && $_GET['dry_run'] == '1';

// ============================================================
// HTML OUTPUT HELPERS
// ============================================================
function page_header($title) {
    echo "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'><title>$title</title>";
    echo "<style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f0f1; padding: 20px; margin: 0; }
        .container { max-width: 1200px; margin: 0 auto; }
        .card { background: #fff; border: 1px solid #ddd; padding: 20px; border-radius: 6px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        h1 { color: #1d2327; margin-top: 0; }
        h2 { color: #2271b1; margin-top: 0; }
        .log { background: #1d2327; color: #50c878; padding: 15px; border-radius: 4px; max-height: 500px; overflow-y: auto; font-family: 'Consolas', 'Courier New', monospace; font-size: 13px; line-height: 1.5; }
        .log .error { color: #ff6b6b; }
        .log .warn { color: #ffd93d; }
        .log .info { color: #6bcbff; }
        .log .success { color: #50c878; }
        .log .skip { color: #888; }
        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-box { background: #f9f9f9; border: 1px solid #eee; padding: 15px; border-radius: 4px; text-align: center; }
        .stat-box .number { font-size: 2em; font-weight: bold; color: #2271b1; }
        .stat-box .label { color: #646970; font-size: 0.9em; margin-top: 5px; }
        .stat-box.danger .number { color: #d63638; }
        .stat-box.success .number { color: #00a32a; }
        .btn { display: inline-block; padding: 10px 20px; border-radius: 4px; text-decoration: none; font-weight: 600; margin: 5px; font-size: 14px; border: none; cursor: pointer; }
        .btn-primary { background: #2271b1; color: #fff; }
        .btn-primary:hover { background: #135e96; }
        .btn-warning { background: #dba617; color: #fff; }
        .btn-warning:hover { background: #c09300; }
        .btn-danger { background: #d63638; color: #fff; }
        .btn-danger:hover { background: #b32d2f; }
        .btn-success { background: #00a32a; color: #fff; }
        .btn-success:hover { background: #008a20; }
        .btn-secondary { background: #f0f0f1; color: #2271b1; border: 1px solid #2271b1; }
        .progress-bar { background: #ddd; height: 25px; border-radius: 12px; overflow: hidden; margin: 15px 0; }
        .progress-bar .fill { background: linear-gradient(90deg, #2271b1, #00a32a); height: 100%; transition: width 0.5s; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 12px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f6f7f7; font-weight: 600; color: #1d2327; }
        .nav { margin-bottom: 20px; padding: 15px; background: #fff; border-radius: 6px; border: 1px solid #ddd; display: flex; flex-wrap: wrap; align-items: center; gap: 5px; }
        .dry-run-banner { background: #fff3cd; border: 2px solid #ffc107; padding: 10px 20px; border-radius: 4px; margin-bottom: 20px; font-weight: bold; color: #856404; }
    </style></head><body><div class='container'>";
    echo "<div class='nav'>";
    echo "<strong>Sync Joomla:&nbsp;</strong>";
    echo "<a href='sync-joomla.php?action=audit' class='btn btn-primary'>Auditoría</a>";
    echo "<a href='sync-joomla.php?action=sync_categories' class='btn btn-success'>Sync Categorías</a>";
    echo "<a href='sync-joomla.php?action=sync_posts' class='btn btn-success'>Sync Posts</a>";
    echo "<a href='sync-joomla.php?action=fix_images' class='btn btn-warning'>Reparar Imágenes</a>";
    echo "<a href='sync-joomla.php?action=fix_categories' class='btn btn-warning'>Reparar Categorías</a>";
    echo "<a href='sync-joomla.php?action=sync_all' class='btn btn-danger'>Sync Completo</a>";
    echo "<a href='wp-backup.php' class='btn btn-secondary'>Backup</a>";
    echo "<a href='migration-dashboard.php' class='btn btn-secondary'>Dashboard Anterior</a>";
    echo "</div>";
}

function page_footer() {
    echo "</div></body></html>";
}

function log_msg($msg, $class = 'info') {
    echo "<div class='$class'>[$class] $msg</div>\n";
    if (ob_get_level()) ob_flush();
    flush();
}

// ============================================================
// ZOO CONTENT EXTRACTION (Improved)
// ============================================================
function extract_zoo_content($elements_json) {
    $elements = json_decode($elements_json, true);
    if (!$elements || !is_array($elements)) {
        return ['content' => '', 'subtitle' => '', 'images' => [], 'teaser_image' => ''];
    }

    $content = '';
    $subtitle = '';
    $images = [];
    $teaser_image = '';

    // Recursively extract all content and images
    $all_values = [];
    $all_files = [];

    array_walk_recursive($elements, function($item, $key) use (&$all_values, &$all_files) {
        if ($key === 'value' && is_string($item) && strlen($item) > 0) {
            $all_values[] = $item;
        }
        if ($key === 'file' && is_string($item) && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $item)) {
            $all_files[] = $item;
        }
    });

    // Separate long HTML content from short metadata values
    foreach ($all_values as $val) {
        $stripped = strip_tags($val);
        if (strlen($val) > 200 || (strlen($val) > 50 && $val !== $stripped)) {
            // Likely article body (contains HTML or is long)
            $content .= $val . "\n";
        } elseif (strlen($stripped) > 5 && strlen($stripped) < 200 && empty($subtitle)) {
            // Could be a subtitle
            $subtitle = $stripped;
        }
    }

    // If we got no content, try joining all values that have some substance
    if (empty(trim(strip_tags($content)))) {
        foreach ($all_values as $val) {
            if (strlen($val) > 30) {
                $content .= $val . "\n";
            }
        }
    }

    $images = $all_files;

    // Also extract images from HTML content
    preg_match_all('#(?:src=["\']|url\(["\']?)([^"\')\s]+\.(?:jpg|jpeg|png|gif|webp))#i', $content . $elements_json, $html_imgs);
    if (!empty($html_imgs[1])) {
        $images = array_merge($images, $html_imgs[1]);
    }

    $images = array_unique($images);

    // First image is teaser
    if (!empty($all_files)) {
        $teaser_image = $all_files[0];
    } elseif (!empty($images)) {
        $teaser_image = reset($images);
    }

    return [
        'content' => trim($content),
        'subtitle' => $subtitle,
        'images' => $images,
        'teaser_image' => $teaser_image
    ];
}

// ============================================================
// IMAGE IMPORT HELPER
// ============================================================
function import_single_image($img_path, $post_id = 0) {
    $img_path = ltrim($img_path, '/');

    // Build possible source paths
    $paths_to_try = [
        JOOMLA_LOCAL_PATH . '/' . $img_path,
        JOOMLA_LOCAL_PATH . '/images/' . $img_path,
    ];

    $source_path = null;
    foreach ($paths_to_try as $try) {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $try);
        if (file_exists($normalized)) {
            $source_path = $normalized;
            break;
        }
    }

    if (!$source_path) {
        return ['success' => false, 'error' => "No encontrado: $img_path"];
    }

    // Check if already imported by filename
    global $wpdb;
    $original_filename = basename($img_path);
    $title = preg_replace('/\.[^.]+$/', '', $original_filename);
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM $wpdb->posts WHERE post_type='attachment' AND post_title = %s LIMIT 1",
        $title
    ));

    if ($existing) {
        $existing_file = get_attached_file($existing);
        if ($existing_file && file_exists($existing_file)) {
            return ['success' => true, 'attach_id' => intval($existing), 'url' => wp_get_attachment_url($existing), 'skipped' => true];
        }
    }

    $upload_dir = wp_upload_dir();
    $filename = wp_unique_filename($upload_dir['path'], $original_filename);
    $destination_path = $upload_dir['path'] . '/' . $filename;
    $destination_url = $upload_dir['url'] . '/' . $filename;

    if (!copy($source_path, $destination_path)) {
        return ['success' => false, 'error' => "No se pudo copiar: $source_path"];
    }

    $filetype = wp_check_filetype($filename, null);
    $attachment = [
        'guid'           => $destination_url,
        'post_mime_type' => $filetype['type'],
        'post_title'     => $title,
        'post_content'   => '',
        'post_status'    => 'inherit'
    ];

    $attach_id = wp_insert_attachment($attachment, $destination_path, $post_id);
    if (is_wp_error($attach_id)) {
        return ['success' => false, 'error' => $attach_id->get_error_message()];
    }

    $attach_data = wp_generate_attachment_metadata($attach_id, $destination_path);
    wp_update_attachment_metadata($attach_id, $attach_data);

    return ['success' => true, 'attach_id' => $attach_id, 'url' => $destination_url, 'skipped' => false];
}

// ============================================================
// REPLACE IMAGES IN CONTENT
// ============================================================
function replace_content_images($content, $elements_json, $post_id) {
    $image_replacements = 0;
    $featured_image_id = 0;

    $search_text = $content . ' ' . $elements_json;

    // Find all image references
    $all_images = [];
    $patterns = [
        '#(?:src=["\']|url\(["\']?)/?images/([^"\')\s]+\.(?:jpg|jpeg|png|gif|webp))#i',
        '#(?:src=["\']|url\(["\']?)(media/zoo/[^"\')\s]+\.(?:jpg|jpeg|png|gif|webp))#i',
        '#(?:src=["\']|url\(["\']?)(media/[^"\')\s]+\.(?:jpg|jpeg|png|gif|webp))#i',
    ];

    foreach ($patterns as $idx => $pattern) {
        preg_match_all($pattern, $search_text, $matches);
        if (!empty($matches[1])) {
            foreach ($matches[1] as $img) {
                $all_images[] = $img;
            }
        }
    }

    $all_images = array_unique($all_images);

    foreach ($all_images as $img_rel) {
        $result = import_single_image('images/' . $img_rel, $post_id);
        if (!$result['success']) {
            $result = import_single_image($img_rel, $post_id);
        }

        if ($result['success']) {
            $new_url = $result['url'];
            $content = str_replace('/images/' . $img_rel, $new_url, $content);
            $content = str_replace('images/' . $img_rel, $new_url, $content);
            $content = str_replace($img_rel, $new_url, $content);
            $image_replacements++;

            if (!$featured_image_id && isset($result['attach_id'])) {
                $featured_image_id = $result['attach_id'];
            }
        }
    }

    return [
        'content' => $content,
        'featured_image_id' => $featured_image_id,
        'replacements' => $image_replacements
    ];
}

// ============================================================
// ACTION: AUDIT
// ============================================================
function do_audit() {
    global $wpdb;

    page_header('Auditoría de Sincronización');
    echo "<div class='card'><h2>Auditoría Completa: Joomla vs WordPress</h2>";
    echo "<div class='log'>";

    $jdb = get_joomla_connection();

    $j_total = $jdb->query("SELECT COUNT(*) as c FROM jos_zoo_item WHERE type IN ('article','page') AND state=1")->fetch_assoc()['c'];
    $j_all = $jdb->query("SELECT COUNT(*) as c FROM jos_zoo_item WHERE type IN ('article','page')")->fetch_assoc()['c'];
    $j_cats = $jdb->query("SELECT COUNT(*) as c FROM jos_zoo_category WHERE published=1")->fetch_assoc()['c'];
    $j_tags_q = $jdb->query("SELECT COUNT(DISTINCT name) as c FROM jos_zoo_tag");
    $j_tags = $j_tags_q ? $j_tags_q->fetch_assoc()['c'] : 0;

    $wp_posts = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status='publish'");
    $wp_attachments = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='attachment'");
    $wp_cats = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->term_taxonomy WHERE taxonomy='category'");
    $wp_tags = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->term_taxonomy WHERE taxonomy='post_tag'");
    $wp_imported = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key='_joomla_zoo_id'");

    log_msg("=== CONTEO GENERAL ===", 'info');
    log_msg("Joomla Zoo artículos (publicados): $j_total", 'info');
    log_msg("Joomla Zoo artículos (todos): $j_all", 'info');
    log_msg("Joomla categorías publicadas: $j_cats", 'info');
    log_msg("Joomla tags únicos: $j_tags", 'info');
    log_msg("", 'info');
    log_msg("WordPress posts publicados: $wp_posts", 'info');
    log_msg("WordPress attachments: $wp_attachments", 'info');
    log_msg("WordPress categorías: $wp_cats", 'info');
    log_msg("WordPress tags: $wp_tags", 'info');
    log_msg("WordPress con _joomla_zoo_id: $wp_imported", 'info');

    // Find missing posts
    log_msg("", 'info');
    log_msg("=== BUSCANDO POSTS FALTANTES ===", 'info');

    $imported_ids_raw = $wpdb->get_col("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key='_joomla_zoo_id'");
    $imported_ids = array_flip($imported_ids_raw);

    $j_items = $jdb->query("SELECT id, name, created, modified, state FROM jos_zoo_item WHERE type IN ('article','page') AND state=1 ORDER BY id ASC");

    $missing_posts = [];
    while ($row = $j_items->fetch_assoc()) {
        if (!isset($imported_ids[$row['id']])) {
            $missing_posts[] = $row;
        }
    }

    $missing_count = count($missing_posts);
    if ($missing_count > 0) {
        log_msg("ENCONTRADOS $missing_count posts faltantes en WordPress", 'warn');
        $show = array_slice($missing_posts, 0, 30);
        foreach ($show as $mp) {
            log_msg("  Faltante: ID {$mp['id']} - {$mp['name']} ({$mp['created']})", 'error');
        }
        if ($missing_count > 30) {
            log_msg("  ... y " . ($missing_count - 30) . " más", 'warn');
        }
    } else {
        log_msg("Todos los posts publicados de Joomla están en WordPress", 'success');
    }

    // Find missing categories
    log_msg("", 'info');
    log_msg("=== BUSCANDO CATEGORÍAS FALTANTES ===", 'info');

    $cat_map = get_option('joomla_zoo_category_map', []);
    $j_cats_list = $jdb->query("SELECT id, name, alias FROM jos_zoo_category WHERE published=1 ORDER BY name ASC");

    $missing_cats = [];
    while ($cat = $j_cats_list->fetch_assoc()) {
        if (!isset($cat_map[$cat['id']])) {
            $exists = get_term_by('slug', $cat['alias'], 'category');
            if (!$exists) {
                $missing_cats[] = $cat;
            }
        }
    }

    if (count($missing_cats) > 0) {
        log_msg("ENCONTRADAS " . count($missing_cats) . " categorías faltantes", 'warn');
        foreach ($missing_cats as $mc) {
            log_msg("  Faltante: ID {$mc['id']} - {$mc['name']} (slug: {$mc['alias']})", 'error');
        }
    } else {
        log_msg("Todas las categorías están sincronizadas", 'success');
    }

    // Broken images (sample check)
    log_msg("", 'info');
    log_msg("=== VERIFICANDO IMÁGENES ROTAS (muestra de 500) ===", 'info');

    $attachments = $wpdb->get_results("SELECT ID, guid FROM $wpdb->posts WHERE post_type='attachment' AND post_mime_type LIKE 'image/%' ORDER BY ID DESC LIMIT 500");
    $broken_images = 0;
    $checked_images = count($attachments);
    foreach ($attachments as $att) {
        $file = get_attached_file($att->ID);
        if ($file && !file_exists($file)) {
            $broken_images++;
            if ($broken_images <= 10) {
                log_msg("  Rota: ID {$att->ID} - $file", 'error');
            }
        }
    }
    log_msg("Verificadas $checked_images, encontradas $broken_images rotas", $broken_images > 0 ? 'warn' : 'success');

    // Posts without featured images
    log_msg("", 'info');
    $no_thumb = $wpdb->get_var("
        SELECT COUNT(*) FROM $wpdb->posts p
        WHERE p.post_type='post' AND p.post_status='publish'
        AND NOT EXISTS (SELECT 1 FROM $wpdb->postmeta pm WHERE pm.post_id=p.ID AND pm.meta_key='_thumbnail_id')
    ");
    log_msg("Posts sin imagen destacada: $no_thumb", $no_thumb > 1000 ? 'warn' : 'info');

    // Posts with Joomla image paths still in content
    $joomla_img_refs = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status='publish' AND post_content LIKE '%images/%' AND post_content LIKE '%.jpg%'");
    log_msg("Posts con rutas de imagen de Joomla sin convertir: $joomla_img_refs", $joomla_img_refs > 0 ? 'warn' : 'success');

    $jdb->close();
    echo "</div></div>";

    // Summary cards
    echo "<div class='stat-grid'>";
    echo "<div class='stat-box " . ($missing_count > 0 ? 'danger' : 'success') . "'><div class='number'>$missing_count</div><div class='label'>Posts Faltantes</div></div>";
    echo "<div class='stat-box " . (count($missing_cats) > 0 ? 'danger' : 'success') . "'><div class='number'>" . count($missing_cats) . "</div><div class='label'>Categorías Faltantes</div></div>";
    echo "<div class='stat-box " . ($broken_images > 0 ? 'danger' : 'success') . "'><div class='number'>$broken_images</div><div class='label'>Imágenes Rotas (muestra)</div></div>";
    echo "<div class='stat-box " . ($joomla_img_refs > 0 ? 'danger' : 'success') . "'><div class='number'>$joomla_img_refs</div><div class='label'>Posts con URLs Joomla</div></div>";
    echo "</div>";

    // Actions
    echo "<div class='card'><h2>Acciones</h2>";
    echo "<p><strong>Antes de sincronizar, haz un backup:</strong> <a href='wp-backup.php' class='btn btn-warning'>Crear Backup</a></p>";
    echo "<hr>";
    if ($missing_count > 0 || count($missing_cats) > 0) {
        if (count($missing_cats) > 0) {
            echo "<a href='sync-joomla.php?action=sync_categories' class='btn btn-success'>Sync " . count($missing_cats) . " Categorías</a>";
        }
        if ($missing_count > 0) {
            echo "<a href='sync-joomla.php?action=sync_posts' class='btn btn-success'>Sync $missing_count Posts</a>";
            echo "<a href='sync-joomla.php?action=sync_posts&dry_run=1' class='btn btn-secondary'>Dry Run Posts</a>";
        }
    }
    if ($broken_images > 0 || $joomla_img_refs > 0) {
        echo "<a href='sync-joomla.php?action=fix_images' class='btn btn-warning'>Reparar Imágenes</a>";
    }
    echo "<a href='sync-joomla.php?action=fix_categories' class='btn btn-warning'>Reasignar Categorías</a>";
    echo "<br><br>";
    echo "<a href='sync-joomla.php?action=sync_all' class='btn btn-danger'>Sync Completo (Categorías + Posts + Imágenes)</a>";
    echo "</div>";

    page_footer();
}

// ============================================================
// ACTION: SYNC CATEGORIES
// ============================================================
function do_sync_categories($dry_run = false) {
    page_header('Sincronizar Categorías');
    echo "<div class='card'><h2>Sincronizando Categorías</h2>";
    if ($dry_run) echo "<div class='dry-run-banner'>MODO DRY RUN - No se realizarán cambios</div>";
    echo "<div class='log'>";

    $jdb = get_joomla_connection();
    $cat_map = get_option('joomla_zoo_category_map', []);

    $j_cats = $jdb->query("SELECT * FROM jos_zoo_category WHERE published=1 ORDER BY parent ASC, id ASC");

    $created = 0;
    $existing = 0;
    $errors = 0;

    while ($row = $j_cats->fetch_assoc()) {
        $j_id = $row['id'];
        $name = $row['name'];
        $slug = $row['alias'];
        $parent_j_id = $row['parent'];

        $parent_wp_id = 0;
        if ($parent_j_id > 0 && isset($cat_map[$parent_j_id])) {
            $parent_wp_id = $cat_map[$parent_j_id];
        }

        $term = get_term_by('slug', $slug, 'category');

        if ($term) {
            $cat_map[$j_id] = $term->term_id;
            $existing++;
        } else {
            if ($dry_run) {
                log_msg("CREARÍA: $name (slug: $slug)", 'info');
                $created++;
            } else {
                $new_term = wp_insert_term($name, 'category', [
                    'slug' => $slug,
                    'parent' => $parent_wp_id,
                ]);

                if (is_wp_error($new_term)) {
                    $existing_term = get_term_by('slug', $slug, 'category');
                    if ($existing_term) {
                        $cat_map[$j_id] = $existing_term->term_id;
                        $existing++;
                    } else {
                        log_msg("Error: $name - " . $new_term->get_error_message(), 'error');
                        $errors++;
                    }
                } else {
                    $cat_map[$j_id] = $new_term['term_id'];
                    log_msg("Creada: $name (ID: {$new_term['term_id']})", 'success');
                    $created++;
                }
            }
        }
    }

    if (!$dry_run) {
        update_option('joomla_zoo_category_map', $cat_map);
    }

    $jdb->close();
    echo "</div></div>";

    echo "<div class='stat-grid'>";
    echo "<div class='stat-box success'><div class='number'>$created</div><div class='label'>Nuevas</div></div>";
    echo "<div class='stat-box'><div class='number'>$existing</div><div class='label'>Existentes</div></div>";
    echo "<div class='stat-box danger'><div class='number'>$errors</div><div class='label'>Errores</div></div>";
    echo "</div>";

    echo "<div class='card'><a href='sync-joomla.php?action=audit' class='btn btn-primary'>Auditoría</a> ";
    echo "<a href='sync-joomla.php?action=sync_posts' class='btn btn-success'>Continuar: Sync Posts</a></div>";

    page_footer();
}

// ============================================================
// ACTION: SYNC POSTS
// ============================================================
function do_sync_posts($offset, $batch, $dry_run = false) {
    global $wpdb;

    page_header('Sincronizar Posts');
    echo "<div class='card'><h2>Sincronizando Posts e Imágenes (Lote: $offset - " . ($offset + $batch) . ")</h2>";
    if ($dry_run) echo "<div class='dry-run-banner'>MODO DRY RUN</div>";
    echo "<div class='log'>";

    $jdb = get_joomla_connection();

    $imported_ids_raw = $wpdb->get_col("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key='_joomla_zoo_id'");
    $imported_ids = array_flip($imported_ids_raw);

    $total_joomla = $jdb->query("SELECT COUNT(*) as c FROM jos_zoo_item WHERE type IN ('article','page') AND state=1")->fetch_assoc()['c'];

    $q = "SELECT * FROM jos_zoo_item WHERE type IN ('article','page') AND state=1 ORDER BY id ASC LIMIT $offset, $batch";
    $result = $jdb->query($q);

    if (!$result) {
        log_msg("Error DB: " . $jdb->error, 'error');
        echo "</div></div>";
        page_footer();
        return;
    }

    $cat_map = get_option('joomla_zoo_category_map', []);
    $created = 0;
    $skipped = 0;
    $updated = 0;
    $errors = 0;
    $images_imported = 0;

    while ($row = $result->fetch_assoc()) {
        $j_id = $row['id'];
        $title = $row['name'];
        $slug = $row['alias'];
        $created_date = $row['created'];
        $modified_date = $row['modified'];

        // Already imported?
        if (isset($imported_ids[$j_id])) {
            $existing_post = $wpdb->get_row($wpdb->prepare(
                "SELECT p.ID, p.post_modified FROM $wpdb->posts p
                 JOIN $wpdb->postmeta pm ON p.ID=pm.post_id
                 WHERE pm.meta_key='_joomla_zoo_id' AND pm.meta_value=%s LIMIT 1",
                $j_id
            ));

            if ($existing_post && strtotime($modified_date) <= strtotime($existing_post->post_modified)) {
                $skipped++;
                continue;
            }
            log_msg("Actualización detectada: ID $j_id - $title", 'warn');
        }

        $extracted = extract_zoo_content($row['elements']);
        $content = $extracted['content'];

        if (!empty($extracted['subtitle'])) {
            $content = '<h2>' . esc_html($extracted['subtitle']) . '</h2>' . "\n" . $content;
        }

        if ($dry_run) {
            $img_count = count($extracted['images']);
            $status = isset($imported_ids[$j_id]) ? 'ACTUALIZARÍA' : 'IMPORTARÍA';
            log_msg("$status: ID $j_id - $title ($img_count imgs)", 'info');
            $created++;
            continue;
        }

        // Import images
        $img_result = replace_content_images($content, $row['elements'], 0);
        $content = $img_result['content'];
        $images_imported += $img_result['replacements'];

        // Teaser image
        $teaser_attach_id = 0;
        if (!empty($extracted['teaser_image'])) {
            $t_res = import_single_image($extracted['teaser_image']);
            if ($t_res['success']) $teaser_attach_id = $t_res['attach_id'];
        }

        // Author mapping
        $wp_author = 1;
        if ($row['created_by'] > 0) {
            $wp_user = $wpdb->get_var($wpdb->prepare(
                "SELECT user_id FROM $wpdb->usermeta WHERE meta_key='_joomla_user_id' AND meta_value=%s LIMIT 1",
                $row['created_by']
            ));
            if ($wp_user) $wp_author = $wp_user;
        }

        $post_data = [
            'post_title'       => $title,
            'post_name'        => $slug,
            'post_content'     => $content,
            'post_status'      => 'publish',
            'post_date'        => $created_date,
            'post_date_gmt'    => get_gmt_from_date($created_date),
            'post_modified'    => $modified_date,
            'post_modified_gmt' => get_gmt_from_date($modified_date),
            'post_type'        => 'post',
            'post_author'      => $wp_author,
        ];

        if (isset($imported_ids[$j_id])) {
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_joomla_zoo_id' AND meta_value=%s LIMIT 1",
                $j_id
            ));
            $post_data['ID'] = $existing_id;
            $wp_post_id = wp_update_post($post_data, true);
            if (is_wp_error($wp_post_id)) {
                log_msg("Error update $j_id: " . $wp_post_id->get_error_message(), 'error');
                $errors++;
                continue;
            }
            log_msg("Actualizado: $title (WP:$existing_id)", 'success');
            $updated++;
            $wp_post_id = $existing_id;
        } else {
            $wp_post_id = wp_insert_post($post_data, true);
            if (is_wp_error($wp_post_id)) {
                log_msg("Error insert '$title': " . $wp_post_id->get_error_message(), 'error');
                $errors++;
                continue;
            }
            update_post_meta($wp_post_id, '_joomla_zoo_id', $j_id);
            log_msg("Creado: $title (J:$j_id -> WP:$wp_post_id)", 'success');
            $created++;
        }

        // Featured image
        $featured_id = $teaser_attach_id ?: $img_result['featured_image_id'];
        if ($featured_id && $wp_post_id) {
            set_post_thumbnail($wp_post_id, $featured_id);
        }

        // Categories
        $cat_q = $jdb->query("SELECT category_id FROM jos_zoo_category_item WHERE item_id = " . intval($j_id));
        $wp_cats = [];
        if ($cat_q) {
            while ($cr = $cat_q->fetch_assoc()) {
                if (isset($cat_map[$cr['category_id']])) {
                    $wp_cats[] = intval($cat_map[$cr['category_id']]);
                }
            }
        }
        if (!empty($wp_cats)) {
            wp_set_post_categories($wp_post_id, $wp_cats);
        }

        // Tags
        $tag_q = $jdb->query("SELECT name FROM jos_zoo_tag WHERE item_id = " . intval($j_id));
        $wp_tags = [];
        if ($tag_q) {
            while ($tr = $tag_q->fetch_assoc()) {
                $wp_tags[] = $tr['name'];
            }
        }
        if (!empty($wp_tags)) {
            wp_set_post_tags($wp_post_id, $wp_tags, true);
        }
    }

    $jdb->close();
    echo "</div></div>";

    echo "<div class='stat-grid'>";
    echo "<div class='stat-box success'><div class='number'>$created</div><div class='label'>Nuevos</div></div>";
    echo "<div class='stat-box'><div class='number'>$updated</div><div class='label'>Actualizados</div></div>";
    echo "<div class='stat-box'><div class='number'>$skipped</div><div class='label'>Sin cambios</div></div>";
    echo "<div class='stat-box'><div class='number'>$images_imported</div><div class='label'>Imágenes</div></div>";
    echo "<div class='stat-box danger'><div class='number'>$errors</div><div class='label'>Errores</div></div>";
    echo "</div>";

    $next_offset = $offset + $batch;
    $progress = min(100, round(($next_offset / max($total_joomla, 1)) * 100));

    echo "<div class='card'>";
    echo "<div class='progress-bar'><div class='fill' style='width:{$progress}%'>$progress%</div></div>";
    echo "<p>Progreso: " . min($next_offset, $total_joomla) . " / $total_joomla</p>";

    if ($next_offset < $total_joomla) {
        $dry_param = $dry_run ? '&dry_run=1' : '';
        $next_url = "sync-joomla.php?action=sync_posts&offset=$next_offset&batch=$batch$dry_param";
        echo "<p>Siguiente lote en 3 segundos...</p>";
        echo "<script>setTimeout(function(){ window.location.href='$next_url'; }, 3000);</script>";
        echo "<a href='$next_url' class='btn btn-primary'>Continuar</a> ";
        echo "<a href='sync-joomla.php?action=audit' class='btn btn-secondary'>Detener</a>";
    } else {
        echo "<h3 style='color:#00a32a;'>Sincronización completada</h3>";
        echo "<a href='sync-joomla.php?action=audit' class='btn btn-primary'>Ver Auditoría</a>";
    }
    echo "</div>";

    page_footer();
}

// ============================================================
// ACTION: FIX IMAGES
// ============================================================
function do_fix_images($offset, $batch, $dry_run = false) {
    global $wpdb;

    page_header('Reparar Imágenes');
    echo "<div class='card'><h2>Reparando Imágenes en Posts Existentes</h2>";
    if ($dry_run) echo "<div class='dry-run-banner'>MODO DRY RUN</div>";
    echo "<div class='log'>";

    $jdb = get_joomla_connection();

    $total = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key='_joomla_zoo_id'");

    $posts = $wpdb->get_results($wpdb->prepare(
        "SELECT pm.post_id, pm.meta_value as zoo_id, p.post_content, p.post_title
         FROM $wpdb->postmeta pm
         JOIN $wpdb->posts p ON pm.post_id = p.ID
         WHERE pm.meta_key='_joomla_zoo_id' AND p.post_type='post'
         ORDER BY pm.post_id ASC LIMIT %d, %d",
        $offset, $batch
    ));

    $fixed_content = 0;
    $fixed_featured = 0;
    $already_ok = 0;

    foreach ($posts as $post) {
        $zoo_id = intval($post->zoo_id);
        $wp_id = $post->post_id;
        $has_thumbnail = has_post_thumbnail($wp_id);
        $content_changed = false;
        $new_content = $post->post_content;

        $j_item = $jdb->query("SELECT elements FROM jos_zoo_item WHERE id = $zoo_id");
        if (!$j_item || $j_item->num_rows == 0) continue;
        $j_row = $j_item->fetch_assoc();
        $extracted = extract_zoo_content($j_row['elements']);

        // Check for unreplaced Joomla image URLs
        if (preg_match('#(?:src=["\']|url\(["\']?)/?images/#i', $new_content)) {
            if (!$dry_run) {
                $img_result = replace_content_images($new_content, $j_row['elements'], $wp_id);
                if ($img_result['replacements'] > 0) {
                    $new_content = $img_result['content'];
                    $content_changed = true;
                    $fixed_content += $img_result['replacements'];
                    log_msg("Reparadas {$img_result['replacements']} imgs: {$post->post_title}", 'success');

                    if (!$has_thumbnail && $img_result['featured_image_id']) {
                        set_post_thumbnail($wp_id, $img_result['featured_image_id']);
                        $fixed_featured++;
                    }
                }
            } else {
                log_msg("REPARARÍA: {$post->post_title} (ID: $wp_id)", 'info');
                $fixed_content++;
            }
        }

        // Fix missing featured image
        if (!$has_thumbnail && !empty($extracted['teaser_image'])) {
            if (!$dry_run) {
                $t_res = import_single_image($extracted['teaser_image'], $wp_id);
                if ($t_res['success']) {
                    set_post_thumbnail($wp_id, $t_res['attach_id']);
                    $fixed_featured++;
                    log_msg("Destacada restaurada: {$post->post_title}", 'success');
                }
            } else {
                log_msg("RESTAURARÍA destacada: {$post->post_title}", 'info');
                $fixed_featured++;
            }
        }

        // Fix broken featured image file
        if ($has_thumbnail) {
            $thumb_id = get_post_thumbnail_id($wp_id);
            $thumb_file = get_attached_file($thumb_id);
            if ($thumb_file && !file_exists($thumb_file) && !empty($extracted['teaser_image'])) {
                if (!$dry_run) {
                    $t_res = import_single_image($extracted['teaser_image'], $wp_id);
                    if ($t_res['success']) {
                        set_post_thumbnail($wp_id, $t_res['attach_id']);
                        $fixed_featured++;
                        log_msg("Archivo restaurado: {$post->post_title}", 'success');
                    }
                } else {
                    log_msg("RESTAURARÍA archivo: {$post->post_title}", 'info');
                    $fixed_featured++;
                }
            } else {
                $already_ok++;
            }
        }

        if ($content_changed && !$dry_run) {
            $wpdb->update($wpdb->posts, ['post_content' => $new_content], ['ID' => $wp_id]);
            clean_post_cache($wp_id);
        }
    }

    $jdb->close();
    echo "</div></div>";

    echo "<div class='stat-grid'>";
    echo "<div class='stat-box success'><div class='number'>$fixed_content</div><div class='label'>URLs Reparadas</div></div>";
    echo "<div class='stat-box success'><div class='number'>$fixed_featured</div><div class='label'>Destacadas Reparadas</div></div>";
    echo "<div class='stat-box'><div class='number'>$already_ok</div><div class='label'>Ya Correctas</div></div>";
    echo "</div>";

    $next_offset = $offset + $batch;
    $progress = min(100, round(($next_offset / max($total, 1)) * 100));

    echo "<div class='card'>";
    echo "<div class='progress-bar'><div class='fill' style='width:{$progress}%'>$progress%</div></div>";
    echo "<p>Procesados: " . min($next_offset, $total) . " / $total</p>";

    if ($next_offset < $total) {
        $dry_param = $dry_run ? '&dry_run=1' : '';
        $next_url = "sync-joomla.php?action=fix_images&offset=$next_offset&batch=$batch$dry_param";
        echo "<script>setTimeout(function(){ window.location.href='$next_url'; }, 2000);</script>";
        echo "<a href='$next_url' class='btn btn-primary'>Continuar</a> ";
        echo "<a href='sync-joomla.php?action=audit' class='btn btn-secondary'>Detener</a>";
    } else {
        echo "<h3 style='color:#00a32a;'>Reparación completada</h3>";
        echo "<a href='sync-joomla.php?action=audit' class='btn btn-primary'>Ver Auditoría</a>";
    }
    echo "</div>";

    page_footer();
}

// ============================================================
// ACTION: FIX CATEGORIES
// ============================================================
function do_fix_categories($offset, $batch, $dry_run = false) {
    global $wpdb;

    page_header('Reparar Categorías');
    echo "<div class='card'><h2>Reasignando Categorías</h2>";
    if ($dry_run) echo "<div class='dry-run-banner'>MODO DRY RUN</div>";
    echo "<div class='log'>";

    $jdb = get_joomla_connection();
    $cat_map = get_option('joomla_zoo_category_map', []);

    if (empty($cat_map)) {
        log_msg("Sin mapa de categorías. Ejecuta Sync Categorías primero.", 'error');
        echo "</div></div>";
        page_footer();
        return;
    }

    $total = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key='_joomla_zoo_id'");

    $posts = $wpdb->get_results($wpdb->prepare(
        "SELECT pm.post_id, pm.meta_value as zoo_id, p.post_title
         FROM $wpdb->postmeta pm
         JOIN $wpdb->posts p ON pm.post_id = p.ID
         WHERE pm.meta_key='_joomla_zoo_id' AND p.post_type='post' AND p.post_status='publish'
         ORDER BY pm.post_id ASC LIMIT %d, %d",
        $offset, $batch
    ));

    $fixed = 0;
    $already_ok = 0;

    foreach ($posts as $post) {
        $zoo_id = intval($post->zoo_id);

        $cat_q = $jdb->query("SELECT category_id FROM jos_zoo_category_item WHERE item_id = $zoo_id");
        $expected = [];
        if ($cat_q) {
            while ($cr = $cat_q->fetch_assoc()) {
                if (isset($cat_map[$cr['category_id']])) {
                    $expected[] = intval($cat_map[$cr['category_id']]);
                }
            }
        }

        if (empty($expected)) continue;

        $current = wp_get_post_categories($post->post_id);
        $missing = array_diff($expected, $current);

        if (!empty($missing)) {
            if (!$dry_run) {
                $merged = array_unique(array_merge($current, $expected));
                wp_set_post_categories($post->post_id, $merged);
                log_msg("+" . count($missing) . " cats: {$post->post_title}", 'success');
            } else {
                log_msg("AGREGARÍA " . count($missing) . " cats: {$post->post_title}", 'info');
            }
            $fixed++;
        } else {
            $already_ok++;
        }

        // Tags
        $tag_q = $jdb->query("SELECT name FROM jos_zoo_tag WHERE item_id = $zoo_id");
        if ($tag_q && $tag_q->num_rows > 0) {
            $tags = [];
            while ($tr = $tag_q->fetch_assoc()) $tags[] = $tr['name'];
            if (!empty($tags) && !$dry_run) {
                wp_set_post_tags($post->post_id, $tags, true);
            }
        }
    }

    $jdb->close();
    echo "</div></div>";

    echo "<div class='stat-grid'>";
    echo "<div class='stat-box success'><div class='number'>$fixed</div><div class='label'>Actualizados</div></div>";
    echo "<div class='stat-box'><div class='number'>$already_ok</div><div class='label'>Correctos</div></div>";
    echo "</div>";

    $next_offset = $offset + $batch;
    $progress = min(100, round(($next_offset / max($total, 1)) * 100));

    echo "<div class='card'>";
    echo "<div class='progress-bar'><div class='fill' style='width:{$progress}%'>$progress%</div></div>";

    if ($next_offset < $total) {
        $dry_param = $dry_run ? '&dry_run=1' : '';
        $next_url = "sync-joomla.php?action=fix_categories&offset=$next_offset&batch=$batch$dry_param";
        echo "<script>setTimeout(function(){ window.location.href='$next_url'; }, 1000);</script>";
        echo "<a href='$next_url' class='btn btn-primary'>Continuar</a> ";
        echo "<a href='sync-joomla.php?action=audit' class='btn btn-secondary'>Detener</a>";
    } else {
        echo "<h3 style='color:#00a32a;'>Completado</h3>";
        echo "<a href='sync-joomla.php?action=audit' class='btn btn-primary'>Ver Auditoría</a>";
    }
    echo "</div>";

    page_footer();
}

// ============================================================
// ACTION: SYNC ALL
// ============================================================
function do_sync_all() {
    $step = isset($_GET['step']) ? $_GET['step'] : 'categories';
    $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
    $batch = isset($_GET['batch']) ? intval($_GET['batch']) : 50;

    if ($step === 'categories') {
        do_sync_categories(false);
        echo "<script>setTimeout(function(){ window.location.href='sync-joomla.php?action=sync_all&step=posts&offset=0&batch=$batch'; }, 3000);</script>";
    } else {
        do_sync_posts($offset, $batch, false);
    }
}

// ============================================================
// ROUTER
// ============================================================
switch ($action) {
    case 'audit':          do_audit(); break;
    case 'sync_categories': do_sync_categories($dry_run); break;
    case 'sync_posts':      do_sync_posts($offset, $batch, $dry_run); break;
    case 'fix_images':      do_fix_images($offset, $batch, $dry_run); break;
    case 'fix_categories':  do_fix_categories($offset, $batch, $dry_run); break;
    case 'sync_all':        do_sync_all(); break;
    default:                do_audit();
}
