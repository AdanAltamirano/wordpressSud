<?php
/**
 * Joomla -> WordPress Sync Script v2
 *
 * CORREGIDO para el sitio Sudcalifornios:
 * - Ruta Joomla correcta: C:/Sudcalifornios (no C:/Sudcaliforniosjoomla)
 * - Imágenes ya importadas por FG plugin están en wp-content/uploads/joomla-images/
 * - Arregla rutas "images/X" → URL correcta de WordPress
 * - Re-extrae contenido de posts vacíos desde Joomla Zoo
 *
 * Acciones:
 *   ?action=audit           -> Ver estado actual sin cambiar nada
 *   ?action=fix_image_urls  -> Corregir src="images/..." en posts existentes
 *   ?action=fix_empty       -> Re-extraer contenido de posts vacíos
 *   ?action=sync_categories -> Sincronizar categorías faltantes
 *   ?action=sync_posts      -> Importar posts faltantes
 *   ?action=fix_categories  -> Reasignar categorías en posts existentes
 *   ?action=sync_all        -> Todo en secuencia
 */

set_time_limit(600);
ini_set('memory_limit', '1024M');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . '/wp-load.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/media.php');

// ============================================================
// CORRECTED CONFIGURATION
// ============================================================
// Override the migration-config path if it's wrong
if (!defined('JOOMLA_DB_HOST')) {
    define('JOOMLA_DB_HOST', '127.0.0.1');
    define('JOOMLA_DB_PORT', 3306);
    define('JOOMLA_DB_USER', 'root');
    define('JOOMLA_DB_PASS', 'yue02');
    define('JOOMLA_DB_NAME', 'sudcalifornios');
}

// CORRECT Joomla local path
define('JOOMLA_PATH', 'C:/Sudcalifornios');

// WordPress uploads base
define('WP_UPLOADS_URL', site_url('/wp-content/uploads'));
define('WP_UPLOADS_DIR', ABSPATH . 'wp-content/uploads');
define('JOOMLA_IMAGES_DIR', WP_UPLOADS_DIR . '/joomla-images');
define('JOOMLA_IMAGES_URL', WP_UPLOADS_URL . '/joomla-images');

$action  = isset($_GET['action']) ? $_GET['action'] : 'audit';
$offset  = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
$batch   = isset($_GET['batch']) ? intval($_GET['batch']) : 50;
$dry_run = isset($_GET['dry_run']) && $_GET['dry_run'] == '1';

// ============================================================
// DB CONNECTION
// ============================================================
function get_joomla_db() {
    $conn = new mysqli(JOOMLA_DB_HOST, JOOMLA_DB_USER, JOOMLA_DB_PASS, JOOMLA_DB_NAME, JOOMLA_DB_PORT);
    if ($conn->connect_error) die("Joomla DB Error: " . $conn->connect_error);
    $conn->set_charset("utf8");
    return $conn;
}

// ============================================================
// HTML HELPERS
// ============================================================
function page_header($title) {
    echo "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'><title>$title</title>";
    echo "<style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #1a1a2e; color: #eee; padding: 20px; margin: 0; }
        .container { max-width: 1200px; margin: 0 auto; }
        .card { background: #16213e; border: 1px solid #0f3460; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        h1, h2 { color: #e94560; margin-top: 0; }
        .log { background: #0a0a1a; color: #50c878; padding: 15px; border-radius: 4px; max-height: 600px; overflow-y: auto; font-family: Consolas, monospace; font-size: 13px; line-height: 1.6; }
        .log .error { color: #ff6b6b; }
        .log .warn { color: #ffd93d; }
        .log .info { color: #6bcbff; }
        .log .success { color: #50c878; }
        .log .skip { color: #666; }
        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 20px; }
        .stat-box { background: #0f3460; padding: 15px; border-radius: 6px; text-align: center; }
        .stat-box .number { font-size: 2em; font-weight: bold; color: #e94560; }
        .stat-box .label { color: #a0a0a0; font-size: 0.85em; margin-top: 5px; }
        .stat-box.ok .number { color: #50c878; }
        .stat-box.warn .number { color: #ffd93d; }
        .btn { display: inline-block; padding: 10px 18px; border-radius: 5px; text-decoration: none; font-weight: 600; margin: 4px; font-size: 13px; border: none; cursor: pointer; transition: all 0.2s; }
        .btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.3); }
        .btn-primary { background: #533483; color: #fff; }
        .btn-success { background: #00a32a; color: #fff; }
        .btn-warning { background: #dba617; color: #000; }
        .btn-danger { background: #e94560; color: #fff; }
        .btn-secondary { background: #2a2a4a; color: #6bcbff; border: 1px solid #6bcbff; }
        .progress-bar { background: #0a0a1a; height: 28px; border-radius: 14px; overflow: hidden; margin: 15px 0; }
        .progress-bar .fill { background: linear-gradient(90deg, #533483, #e94560); height: 100%; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 12px; font-weight: bold; }
        .nav { margin-bottom: 20px; padding: 12px; background: #16213e; border-radius: 8px; border: 1px solid #0f3460; display: flex; flex-wrap: wrap; align-items: center; gap: 4px; }
        .dry-banner { background: #533483; border: 2px solid #e94560; padding: 10px 20px; border-radius: 4px; margin-bottom: 15px; font-weight: bold; color: #ffd93d; }
        hr { border: none; border-top: 1px solid #0f3460; margin: 15px 0; }
    </style></head><body><div class='container'>";
    echo "<div class='nav'>";
    echo "<strong style='color:#e94560;'>Sync v2:&nbsp;</strong>";
    echo "<a href='?action=audit' class='btn btn-primary'>Auditoría</a>";
    echo "<a href='?action=fix_image_urls' class='btn btn-warning'>Fix URLs Imágenes</a>";
    echo "<a href='?action=fix_empty' class='btn btn-warning'>Fix Posts Vacíos</a>";
    echo "<a href='?action=sync_categories' class='btn btn-success'>Sync Categorías</a>";
    echo "<a href='?action=sync_posts' class='btn btn-success'>Sync Posts</a>";
    echo "<a href='?action=fix_categories' class='btn btn-success'>Fix Categorías</a>";
    echo "<a href='?action=fix_ids' class='btn btn-warning'>Fix IDs Visibles</a>";
    echo "<a href='?action=sync_all' class='btn btn-danger'>Sync Completo</a>";
    echo "</div>";
}

function page_footer() { echo "</div></body></html>"; }

function log_msg($msg, $class = 'info') {
    echo "<div class='$class'>" . htmlspecialchars("[$class] $msg") . "</div>\n";
    if (ob_get_level()) ob_flush();
    flush();
}

// ============================================================
// FIND IMAGE IN WORDPRESS UPLOADS
// ============================================================
function find_image_in_uploads($relative_path) {
    // Given a path like "images/00mar.jpg" or "masajes/lunes12/photo.jpg"
    // Find it in wp-content/uploads/joomla-images/

    $relative_path = urldecode($relative_path);
    $relative_path = str_replace('images/', '', $relative_path);
    $filename = basename($relative_path);

    // Try exact path match first
    $try_paths = [
        JOOMLA_IMAGES_DIR . '/' . $relative_path,
        JOOMLA_IMAGES_DIR . '/' . $filename,
    ];

    foreach ($try_paths as $path) {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if (file_exists($path)) {
            // Convert to URL
            $rel = str_replace(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, WP_UPLOADS_DIR), '', $path);
            $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
            return WP_UPLOADS_URL . $rel;
        }
    }

    // Search recursively for the filename
    if (is_dir(JOOMLA_IMAGES_DIR)) {
        $di = new RecursiveDirectoryIterator(JOOMLA_IMAGES_DIR, RecursiveDirectoryIterator::SKIP_DOTS);
        $it = new RecursiveIteratorIterator($di);
        foreach ($it as $file) {
            if (strtolower(basename($file->getPathname())) === strtolower($filename)) {
                // Skip " - copia" directories
                if (strpos($file->getPathname(), ' - copia') !== false) continue;
                $rel = str_replace(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, WP_UPLOADS_DIR), '', $file->getPathname());
                $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
                return WP_UPLOADS_URL . $rel;
            }
        }
    }

    return false;
}

// ============================================================
// IMPORT IMAGE FROM JOOMLA SOURCE
// ============================================================
function import_joomla_image($img_path, $post_id = 0) {
    $img_path = urldecode(ltrim($img_path, '/'));

    // Try to find in Joomla source
    $try_paths = [
        JOOMLA_PATH . '/' . $img_path,
        JOOMLA_PATH . '/images/' . str_replace('images/', '', $img_path),
    ];

    $source = null;
    foreach ($try_paths as $p) {
        $p = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $p);
        if (file_exists($p)) { $source = $p; break; }
    }

    if (!$source) return ['success' => false, 'error' => "Not found in Joomla: $img_path"];

    $filename = basename($img_path);
    $upload_dir = wp_upload_dir();
    $dest_filename = wp_unique_filename($upload_dir['path'], $filename);
    $dest_path = $upload_dir['path'] . '/' . $dest_filename;

    if (!copy($source, $dest_path)) return ['success' => false, 'error' => "Copy failed"];

    $filetype = wp_check_filetype($dest_filename, null);
    $attach_id = wp_insert_attachment([
        'guid'           => $upload_dir['url'] . '/' . $dest_filename,
        'post_mime_type' => $filetype['type'],
        'post_title'     => preg_replace('/\.[^.]+$/', '', $filename),
        'post_content'   => '',
        'post_status'    => 'inherit'
    ], $dest_path, $post_id);

    if (is_wp_error($attach_id)) return ['success' => false, 'error' => $attach_id->get_error_message()];

    wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $dest_path));

    return ['success' => true, 'attach_id' => $attach_id, 'url' => $upload_dir['url'] . '/' . $dest_filename];
}

// ============================================================
// EXTRACT CONTENT FROM ZOO ELEMENTS
// ============================================================
function extract_zoo_content($elements_json) {
    $elements = json_decode($elements_json, true);
    if (!$elements || !is_array($elements)) {
        return ['content' => '', 'subtitle' => '', 'images' => []];
    }

    $content = '';
    $subtitle = '';
    $images = [];

    // Known content UUID
    $content_uuid = '2e3c9e69-1f9e-4647-8d13-4e88094d2790';
    $subtitle_uuid = '08795744-c2dc-4a68-8252-4e21c4c4c774';

    // Extract main content
    if (isset($elements[$content_uuid]) && is_array($elements[$content_uuid])) {
        foreach ($elements[$content_uuid] as $item) {
            if (isset($item['value']) && is_string($item['value']) && strlen($item['value']) > 10) {
                $content .= $item['value'] . "\n";
            }
        }
    }

    // Extract subtitle
    if (isset($elements[$subtitle_uuid]) && is_array($elements[$subtitle_uuid])) {
        foreach ($elements[$subtitle_uuid] as $item) {
            if (isset($item['value']) && is_string($item['value']) && !empty(trim($item['value']))) {
                $subtitle = trim($item['value']);
                break;
            }
        }
    }

    // Fallback: if no content from known UUID, extract all long values
    if (empty(trim(strip_tags($content)))) {
        foreach ($elements as $uuid => $data) {
            if ($uuid === $subtitle_uuid) continue;
            if (!is_array($data)) continue;
            foreach ($data as $item) {
                if (!is_array($item) || !isset($item['value'])) continue;
                $val = $item['value'];
                if (!is_string($val)) continue;
                // Only take substantial content (HTML or long text)
                if (strlen($val) > 100 || (strlen($val) > 30 && $val !== strip_tags($val))) {
                    $content .= $val . "\n";
                }
            }
        }
    }

    // Extract all image references from content
    preg_match_all('/src=["\']([^"\']+)["\']/i', $content, $m);
    if (!empty($m[1])) {
        foreach ($m[1] as $src) {
            if (strpos($src, 'data:image') !== 0) {
                $images[] = $src;
            }
        }
    }

    return [
        'content' => trim($content),
        'subtitle' => $subtitle,
        'images' => array_unique($images)
    ];
}

// ============================================================
// FIX IMAGE URLS IN CONTENT STRING
// ============================================================
function fix_image_urls_in_content($content) {
    $fixed = 0;

    // Find all src="images/..." references
    preg_match_all('/src=["\'](?:\.\/)?images\/([^"\']+)["\']/i', $content, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $original_full = $match[0];
        $relative_path = urldecode($match[1]);

        $new_url = find_image_in_uploads('images/' . $relative_path);

        if ($new_url) {
            // Preserve the quote style
            $quote = substr($original_full, 4, 1); // " or '
            $replacement = 'src=' . $quote . $new_url . $quote;
            $content = str_replace($original_full, $replacement, $content);
            $fixed++;
        } else {
            // Try importing from Joomla source
            $result = import_joomla_image('images/' . $relative_path);
            if ($result['success']) {
                $quote = substr($original_full, 4, 1);
                $replacement = 'src=' . $quote . $result['url'] . $quote;
                $content = str_replace($original_full, $replacement, $content);
                $fixed++;
            }
        }
    }

    return ['content' => $content, 'fixed' => $fixed];
}

// ============================================================
// ACTION: AUDIT
// ============================================================
function do_audit() {
    global $wpdb;
    page_header('Auditoría v2');
    echo "<div class='card'><h2>Auditoría Completa</h2><div class='log'>";

    $jdb = get_joomla_db();

    // Joomla counts
    $j_published = $jdb->query("SELECT COUNT(*) as c FROM jos_zoo_item WHERE type IN ('article','page') AND state=1")->fetch_assoc()['c'];
    $j_all = $jdb->query("SELECT COUNT(*) as c FROM jos_zoo_item WHERE type IN ('article','page')")->fetch_assoc()['c'];
    $j_cats = $jdb->query("SELECT COUNT(*) as c FROM jos_zoo_category WHERE published=1")->fetch_assoc()['c'];

    // WordPress counts
    $wp_published = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status='publish'");
    $wp_tracked = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key='_joomla_zoo_id'");
    $wp_visible_ids = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key='joomla_original_id'");
    $wp_cats = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->term_taxonomy WHERE taxonomy='category'");
    $wp_attachments = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='attachment'");

    log_msg("=== JOOMLA ===", 'info');
    log_msg("Artículos publicados: $j_published", 'info');
    log_msg("Artículos total: $j_all", 'info');
    log_msg("Categorías: $j_cats", 'info');

    log_msg("", 'info');
    log_msg("=== WORDPRESS ===", 'info');
    log_msg("Posts publicados: $wp_published", 'info');
    log_msg("Posts con _joomla_zoo_id: $wp_tracked", 'info');
    log_msg("Posts con joomla_original_id (visible): $wp_visible_ids", $wp_visible_ids >= $wp_tracked ? 'success' : 'warn');
    log_msg("Categorías: $wp_cats", 'info');
    log_msg("Attachments: $wp_attachments", 'info');

    // Missing posts
    log_msg("", 'info');
    log_msg("=== POSTS FALTANTES ===", 'info');
    $imported_ids = array_flip($wpdb->get_col("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key='_joomla_zoo_id'"));

    $j_items = $jdb->query("SELECT id, name, created FROM jos_zoo_item WHERE type IN ('article','page') AND state=1 ORDER BY id DESC");
    $missing = [];
    while ($row = $j_items->fetch_assoc()) {
        if (!isset($imported_ids[$row['id']])) $missing[] = $row;
    }
    $missing_count = count($missing);
    if ($missing_count > 0) {
        log_msg("$missing_count posts faltantes en WordPress", 'warn');
        foreach (array_slice($missing, 0, 15) as $mp) {
            log_msg("  ID {$mp['id']}: {$mp['name']} ({$mp['created']})", 'error');
        }
        if ($missing_count > 15) log_msg("  ... y " . ($missing_count - 15) . " más", 'warn');
    } else {
        log_msg("Todos los posts están sincronizados", 'success');
    }

    // Broken image URLs
    log_msg("", 'info');
    log_msg("=== IMÁGENES ROTAS EN CONTENIDO ===", 'info');
    $broken_img = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status='publish' AND post_content LIKE '%src=\"images/%'");
    log_msg("Posts con src=\"images/...\" (rutas Joomla sin convertir): $broken_img", $broken_img > 0 ? 'error' : 'success');

    // Empty content posts
    log_msg("", 'info');
    log_msg("=== POSTS CON CONTENIDO VACÍO ===", 'info');
    $empty = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status='publish' AND LENGTH(post_content) < 50");
    log_msg("Posts con contenido menor a 50 caracteres: $empty", $empty > 0 ? 'error' : 'success');

    // Posts without thumbnail
    $no_thumb = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts p WHERE p.post_type='post' AND p.post_status='publish' AND NOT EXISTS (SELECT 1 FROM $wpdb->postmeta pm WHERE pm.post_id=p.ID AND pm.meta_key='_thumbnail_id')");
    log_msg("Posts sin imagen destacada: $no_thumb", 'info');

    // Missing categories
    log_msg("", 'info');
    log_msg("=== CATEGORÍAS FALTANTES ===", 'info');
    $cat_map = get_option('joomla_zoo_category_map', []);
    $j_cats_list = $jdb->query("SELECT id, name, alias FROM jos_zoo_category WHERE published=1");
    $missing_cats = [];
    while ($cat = $j_cats_list->fetch_assoc()) {
        if (!isset($cat_map[$cat['id']])) {
            if (!get_term_by('slug', $cat['alias'], 'category')) {
                $missing_cats[] = $cat;
            }
        }
    }
    $mc = count($missing_cats);
    log_msg("Categorías faltantes: $mc", $mc > 0 ? 'warn' : 'success');

    // Joomla source path
    log_msg("", 'info');
    log_msg("=== VERIFICACIÓN DE RUTAS ===", 'info');
    log_msg("Joomla path: " . JOOMLA_PATH . " -> " . (is_dir(JOOMLA_PATH) ? "OK" : "NO EXISTE"), is_dir(JOOMLA_PATH) ? 'success' : 'error');
    log_msg("Joomla images: " . JOOMLA_PATH . "/images -> " . (is_dir(JOOMLA_PATH . '/images') ? "OK" : "NO EXISTE"), is_dir(JOOMLA_PATH . '/images') ? 'success' : 'error');
    log_msg("WP joomla-images: " . JOOMLA_IMAGES_DIR . " -> " . (is_dir(JOOMLA_IMAGES_DIR) ? "OK" : "NO EXISTE"), is_dir(JOOMLA_IMAGES_DIR) ? 'success' : 'error');

    $jdb->close();
    echo "</div></div>";

    // Stats cards
    echo "<div class='stat-grid'>";
    echo "<div class='stat-box " . ($missing_count > 0 ? '' : 'ok') . "'><div class='number'>$missing_count</div><div class='label'>Posts Faltantes</div></div>";
    echo "<div class='stat-box " . ($broken_img > 0 ? '' : 'ok') . "'><div class='number'>$broken_img</div><div class='label'>URLs Imagen Rotas</div></div>";
    echo "<div class='stat-box " . ($empty > 0 ? '' : 'ok') . "'><div class='number'>$empty</div><div class='label'>Posts Vacíos</div></div>";
    echo "<div class='stat-box " . ($mc > 0 ? 'warn' : 'ok') . "'><div class='number'>$mc</div><div class='label'>Categorías Faltantes</div></div>";
    echo "</div>";

    // Actions
    echo "<div class='card'><h2>Acciones Recomendadas</h2>";
    echo "<p>Ejecuta en este orden:</p>";
    echo "<ol style='color:#a0a0a0;'>";
    if ($broken_img > 0) echo "<li><strong style='color:#ffd93d;'>Corregir $broken_img URLs de imágenes</strong> - Reemplaza rutas Joomla por rutas WordPress</li>";
    if ($empty > 0) echo "<li><strong style='color:#ffd93d;'>Reparar $empty posts vacíos</strong> - Re-extrae contenido desde Joomla Zoo</li>";
    if ($mc > 0) echo "<li><strong style='color:#50c878;'>Sincronizar $mc categorías</strong></li>";
    if ($missing_count > 0) echo "<li><strong style='color:#50c878;'>Importar $missing_count posts faltantes</strong></li>";
    echo "<li>Reasignar categorías en posts existentes</li>";
    if ($wp_visible_ids < $wp_tracked) echo "<li><strong style='color:#ffd93d;'>Poblar joomla_original_id (visible)</strong></li>";
    echo "</ol><hr>";

    if ($broken_img > 0) echo "<a href='?action=fix_image_urls' class='btn btn-warning'>1. Fix $broken_img URLs Imágenes</a>";
    if ($empty > 0) echo "<a href='?action=fix_empty' class='btn btn-warning'>2. Fix $empty Posts Vacíos</a>";
    if ($mc > 0) echo "<a href='?action=sync_categories' class='btn btn-success'>3. Sync Categorías</a>";
    if ($missing_count > 0) {
        echo "<a href='?action=sync_posts' class='btn btn-success'>4. Sync Posts</a>";
        echo "<a href='?action=sync_posts&dry_run=1' class='btn btn-secondary'>Dry Run</a>";
    }
    echo "<a href='?action=fix_categories' class='btn btn-success'>5. Fix Categorías</a>";
    if ($wp_visible_ids < $wp_tracked) echo "<a href='?action=fix_ids' class='btn btn-warning'>Fix IDs Visibles</a>";
    echo "<br><br><a href='?action=sync_all' class='btn btn-danger'>Ejecutar Todo en Secuencia</a>";
    echo "</div>";

    page_footer();
}

// ============================================================
// ACTION: FIX IMAGE URLS (the main fix for broken images)
// ============================================================
function do_fix_image_urls($offset, $batch, $dry_run) {
    global $wpdb;
    page_header('Corrigiendo URLs de Imágenes');
    echo "<div class='card'><h2>Reemplazando rutas Joomla por rutas WordPress</h2>";
    if ($dry_run) echo "<div class='dry-banner'>MODO DRY RUN - No se harán cambios</div>";
    echo "<div class='log'>";

    $total = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status='publish' AND post_content LIKE '%src=\"images/%'");

    $posts = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_title, post_content FROM $wpdb->posts
         WHERE post_type='post' AND post_status='publish' AND post_content LIKE '%%src=\"images/%%'
         ORDER BY ID ASC LIMIT %d, %d",
        $offset, $batch
    ));

    $fixed_total = 0;
    $posts_fixed = 0;
    $not_found = 0;

    foreach ($posts as $post) {
        $result = fix_image_urls_in_content($post->post_content);

        if ($result['fixed'] > 0) {
            if (!$dry_run) {
                $wpdb->update($wpdb->posts, ['post_content' => $result['content']], ['ID' => $post->ID]);
                clean_post_cache($post->ID);
            }
            log_msg("Reparadas {$result['fixed']} imgs en: {$post->post_title} (ID: {$post->ID})", 'success');
            $fixed_total += $result['fixed'];
            $posts_fixed++;
        } else {
            // Check if images couldn't be found
            preg_match_all('/src=["\']images\/([^"\']+)["\']/i', $post->post_content, $still_broken);
            if (!empty($still_broken[1])) {
                foreach ($still_broken[1] as $sb) {
                    log_msg("NO ENCONTRADA: {$post->post_title} -> images/$sb", 'error');
                }
                $not_found += count($still_broken[1]);
            }
        }
    }

    echo "</div></div>";

    echo "<div class='stat-grid'>";
    echo "<div class='stat-box ok'><div class='number'>$posts_fixed</div><div class='label'>Posts Reparados</div></div>";
    echo "<div class='stat-box ok'><div class='number'>$fixed_total</div><div class='label'>URLs Corregidas</div></div>";
    echo "<div class='stat-box " . ($not_found > 0 ? '' : 'ok') . "'><div class='number'>$not_found</div><div class='label'>No Encontradas</div></div>";
    echo "</div>";

    // Pagination
    $next = $offset + $batch;
    if (count($posts) >= $batch) {
        $dry_p = $dry_run ? '&dry_run=1' : '';
        $url = "?action=fix_image_urls&offset=$next&batch=$batch$dry_p";
        echo "<div class='card'><p>Siguiente lote en 2s...</p>";
        echo "<script>setTimeout(function(){ window.location.href='$url'; }, 2000);</script>";
        echo "<a href='$url' class='btn btn-primary'>Continuar</a> ";
        echo "<a href='?action=audit' class='btn btn-secondary'>Detener</a></div>";
    } else {
        echo "<div class='card'><h3 style='color:#50c878;'>Corrección de URLs completada</h3>";
        echo "<a href='?action=audit' class='btn btn-primary'>Ver Auditoría</a></div>";
    }

    page_footer();
}

// ============================================================
// ACTION: FIX EMPTY POSTS
// ============================================================
function do_fix_empty($offset, $batch, $dry_run) {
    global $wpdb;
    page_header('Reparando Posts Vacíos');
    echo "<div class='card'><h2>Re-extrayendo contenido desde Joomla</h2>";
    if ($dry_run) echo "<div class='dry-banner'>MODO DRY RUN</div>";
    echo "<div class='log'>";

    $jdb = get_joomla_db();

    // Get empty posts that have a joomla zoo id
    $total = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID=pm.post_id WHERE p.post_type='post' AND p.post_status='publish' AND LENGTH(p.post_content) < 50 AND pm.meta_key='_joomla_zoo_id'");

    $posts = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_title, pm.meta_value as zoo_id, LENGTH(p.post_content) as content_len
         FROM $wpdb->posts p
         JOIN $wpdb->postmeta pm ON p.ID=pm.post_id
         WHERE p.post_type='post' AND p.post_status='publish'
         AND LENGTH(p.post_content) < 50 AND pm.meta_key='_joomla_zoo_id'
         ORDER BY p.ID ASC LIMIT %d, %d",
        $offset, $batch
    ));

    $fixed = 0;
    $still_empty = 0;

    foreach ($posts as $post) {
        $zoo_id = intval($post->zoo_id);
        $j_item = $jdb->query("SELECT elements FROM jos_zoo_item WHERE id = $zoo_id");

        if (!$j_item || $j_item->num_rows == 0) {
            log_msg("No encontrado en Joomla: {$post->post_title} (Zoo ID: $zoo_id)", 'error');
            $still_empty++;
            continue;
        }

        $j_row = $j_item->fetch_assoc();
        $extracted = extract_zoo_content($j_row['elements']);

        if (empty(trim(strip_tags($extracted['content'])))) {
            log_msg("Sin contenido extraíble: {$post->post_title} (Zoo ID: $zoo_id)", 'warn');
            $still_empty++;
            continue;
        }

        $new_content = $extracted['content'];

        // Fix image URLs in the new content
        $img_fix = fix_image_urls_in_content($new_content);
        $new_content = $img_fix['content'];

        if (!empty($extracted['subtitle'])) {
            $new_content = '<h2>' . esc_html($extracted['subtitle']) . '</h2>' . "\n" . $new_content;
        }

        if (!$dry_run) {
            $wpdb->update($wpdb->posts, ['post_content' => $new_content], ['ID' => $post->ID]);
            clean_post_cache($post->ID);
        }

        $new_len = strlen($new_content);
        log_msg("Reparado: {$post->post_title} ({$post->content_len} -> $new_len chars)", 'success');
        $fixed++;
    }

    $jdb->close();
    echo "</div></div>";

    echo "<div class='stat-grid'>";
    echo "<div class='stat-box ok'><div class='number'>$fixed</div><div class='label'>Posts Reparados</div></div>";
    echo "<div class='stat-box'><div class='number'>$still_empty</div><div class='label'>Sin Contenido</div></div>";
    echo "</div>";

    $next = $offset + $batch;
    if (count($posts) >= $batch) {
        $dry_p = $dry_run ? '&dry_run=1' : '';
        $url = "?action=fix_empty&offset=$next&batch=$batch$dry_p";
        echo "<div class='card'><script>setTimeout(function(){ window.location.href='$url'; }, 2000);</script>";
        echo "<a href='$url' class='btn btn-primary'>Continuar</a> <a href='?action=audit' class='btn btn-secondary'>Detener</a></div>";
    } else {
        echo "<div class='card'><h3 style='color:#50c878;'>Completado</h3><a href='?action=audit' class='btn btn-primary'>Auditoría</a></div>";
    }

    page_footer();
}

// ============================================================
// ACTION: SYNC CATEGORIES
// ============================================================
function do_sync_categories($dry_run) {
    page_header('Sync Categorías');
    echo "<div class='card'><h2>Sincronizando Categorías</h2>";
    if ($dry_run) echo "<div class='dry-banner'>MODO DRY RUN</div>";
    echo "<div class='log'>";

    $jdb = get_joomla_db();
    $cat_map = get_option('joomla_zoo_category_map', []);

    $j_cats = $jdb->query("SELECT * FROM jos_zoo_category WHERE published=1 ORDER BY parent ASC, id ASC");

    $created = 0; $existing = 0; $errors = 0;

    while ($row = $j_cats->fetch_assoc()) {
        $j_id = $row['id'];
        $name = $row['name'];
        $slug = $row['alias'];

        $parent_wp_id = 0;
        if ($row['parent'] > 0 && isset($cat_map[$row['parent']])) {
            $parent_wp_id = $cat_map[$row['parent']];
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
                $new = wp_insert_term($name, 'category', ['slug' => $slug, 'parent' => $parent_wp_id]);
                if (is_wp_error($new)) {
                    $ex = get_term_by('slug', $slug, 'category');
                    if ($ex) { $cat_map[$j_id] = $ex->term_id; $existing++; }
                    else { log_msg("Error: $name - " . $new->get_error_message(), 'error'); $errors++; }
                } else {
                    $cat_map[$j_id] = $new['term_id'];
                    log_msg("Creada: $name (ID: {$new['term_id']})", 'success');
                    $created++;
                }
            }
        }
    }

    if (!$dry_run) update_option('joomla_zoo_category_map', $cat_map);
    $jdb->close();
    echo "</div></div>";

    echo "<div class='stat-grid'>";
    echo "<div class='stat-box ok'><div class='number'>$created</div><div class='label'>Nuevas</div></div>";
    echo "<div class='stat-box'><div class='number'>$existing</div><div class='label'>Existentes</div></div>";
    echo "<div class='stat-box'><div class='number'>$errors</div><div class='label'>Errores</div></div>";
    echo "</div>";

    echo "<div class='card'><a href='?action=audit' class='btn btn-primary'>Auditoría</a> ";
    echo "<a href='?action=sync_posts' class='btn btn-success'>Sync Posts</a></div>";
    page_footer();
}

// ============================================================
// ACTION: SYNC POSTS
// ============================================================
function do_sync_posts($offset, $batch, $dry_run) {
    global $wpdb;
    page_header('Sync Posts');
    echo "<div class='card'><h2>Importando Posts Faltantes (Lote: $offset)</h2>";
    if ($dry_run) echo "<div class='dry-banner'>MODO DRY RUN</div>";
    echo "<div class='log'>";

    $jdb = get_joomla_db();

    $imported_ids = array_flip($wpdb->get_col("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key='_joomla_zoo_id'"));
    $total = $jdb->query("SELECT COUNT(*) as c FROM jos_zoo_item WHERE type IN ('article','page') AND state=1")->fetch_assoc()['c'];
    $cat_map = get_option('joomla_zoo_category_map', []);

    $result = $jdb->query("SELECT * FROM jos_zoo_item WHERE type IN ('article','page') AND state=1 ORDER BY id ASC LIMIT $offset, $batch");

    $created = 0; $skipped = 0; $updated = 0; $errors = 0;

    while ($row = $result->fetch_assoc()) {
        $j_id = $row['id'];

        if (isset($imported_ids[$j_id])) {
            // Check if needs update
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT p.ID, p.post_modified FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID=pm.post_id WHERE pm.meta_key='_joomla_zoo_id' AND pm.meta_value=%s LIMIT 1", $j_id
            ));
            if ($existing && strtotime($row['modified']) <= strtotime($existing->post_modified)) {
                $skipped++;
                continue;
            }
        }

        $extracted = extract_zoo_content($row['elements']);
        $content = $extracted['content'];

        // Fix image URLs
        $img_fix = fix_image_urls_in_content($content);
        $content = $img_fix['content'];

        if (!empty($extracted['subtitle'])) {
            $content = '<h2>' . esc_html($extracted['subtitle']) . '</h2>' . "\n" . $content;
        }

        if ($dry_run) {
            $status = isset($imported_ids[$j_id]) ? 'UPDATE' : 'NEW';
            log_msg("[$status] ID $j_id: {$row['name']} (" . count($extracted['images']) . " imgs)", 'info');
            $created++;
            continue;
        }

        // Author
        $wp_author = 1;
        if ($row['created_by'] > 0) {
            $u = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $wpdb->usermeta WHERE meta_key='_joomla_user_id' AND meta_value=%s LIMIT 1", $row['created_by']));
            if ($u) $wp_author = $u;
        }

        $post_data = [
            'post_title'        => $row['name'],
            'post_name'         => $row['alias'],
            'post_content'      => $content,
            'post_status'       => 'publish',
            'post_date'         => $row['created'],
            'post_date_gmt'     => get_gmt_from_date($row['created']),
            'post_modified'     => $row['modified'],
            'post_modified_gmt' => get_gmt_from_date($row['modified']),
            'post_type'         => 'post',
            'post_author'       => $wp_author,
        ];

        if (isset($imported_ids[$j_id])) {
            $ex_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_joomla_zoo_id' AND meta_value=%s LIMIT 1", $j_id));
            $post_data['ID'] = $ex_id;
            $wp_id = wp_update_post($post_data, true);
            if (is_wp_error($wp_id)) { log_msg("Error update: " . $wp_id->get_error_message(), 'error'); $errors++; continue; }

            // Ensure visible ID reference
            update_post_meta($ex_id, 'joomla_original_id', $j_id);

            log_msg("Actualizado: {$row['name']} (WP:$ex_id)", 'success');
            $updated++;
            $wp_id = $ex_id;
        } else {
            $wp_id = wp_insert_post($post_data, true);
            if (is_wp_error($wp_id)) { log_msg("Error insert: " . $wp_id->get_error_message(), 'error'); $errors++; continue; }
            update_post_meta($wp_id, '_joomla_zoo_id', $j_id);

            // Add visible ID reference
            update_post_meta($wp_id, 'joomla_original_id', $j_id);

            log_msg("Creado: {$row['name']} (J:$j_id -> WP:$wp_id)", 'success');
            $created++;
        }

        // Categories
        $cq = $jdb->query("SELECT category_id FROM jos_zoo_category_item WHERE item_id = " . intval($j_id));
        $wp_cats = [];
        if ($cq) { while ($cr = $cq->fetch_assoc()) { if (isset($cat_map[$cr['category_id']])) $wp_cats[] = intval($cat_map[$cr['category_id']]); } }
        if (!empty($wp_cats)) wp_set_post_categories($wp_id, $wp_cats);

        // Tags
        $tq = $jdb->query("SELECT name FROM jos_zoo_tag WHERE item_id = " . intval($j_id));
        $tags = [];
        if ($tq) { while ($tr = $tq->fetch_assoc()) $tags[] = $tr['name']; }
        if (!empty($tags)) wp_set_post_tags($wp_id, $tags, true);
    }

    $jdb->close();
    echo "</div></div>";

    echo "<div class='stat-grid'>";
    echo "<div class='stat-box ok'><div class='number'>$created</div><div class='label'>Nuevos</div></div>";
    echo "<div class='stat-box'><div class='number'>$updated</div><div class='label'>Actualizados</div></div>";
    echo "<div class='stat-box'><div class='number'>$skipped</div><div class='label'>Sin Cambios</div></div>";
    echo "<div class='stat-box'><div class='number'>$errors</div><div class='label'>Errores</div></div>";
    echo "</div>";

    $next = $offset + $batch;
    $progress = min(100, round(($next / max($total, 1)) * 100));

    echo "<div class='card'>";
    echo "<div class='progress-bar'><div class='fill' style='width:{$progress}%'>$progress%</div></div>";
    echo "<p>" . min($next, $total) . " / $total</p>";

    if ($next < $total) {
        $dry_p = $dry_run ? '&dry_run=1' : '';
        $url = "?action=sync_posts&offset=$next&batch=$batch$dry_p";
        echo "<script>setTimeout(function(){ window.location.href='$url'; }, 3000);</script>";
        echo "<a href='$url' class='btn btn-primary'>Continuar</a> <a href='?action=audit' class='btn btn-secondary'>Detener</a>";
    } else {
        echo "<h3 style='color:#50c878;'>Completado</h3><a href='?action=audit' class='btn btn-primary'>Auditoría</a>";
    }
    echo "</div>";
    page_footer();
}

// ============================================================
// ACTION: FIX CATEGORIES
// ============================================================
function do_fix_categories($offset, $batch, $dry_run) {
    global $wpdb;
    page_header('Fix Categorías');
    echo "<div class='card'><h2>Reasignando Categorías</h2><div class='log'>";

    $jdb = get_joomla_db();
    $cat_map = get_option('joomla_zoo_category_map', []);

    if (empty($cat_map)) {
        log_msg("Sin mapa de categorías. Ejecuta Sync Categorías primero.", 'error');
        echo "</div></div>"; page_footer(); return;
    }

    $total = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key='_joomla_zoo_id'");

    $posts = $wpdb->get_results($wpdb->prepare(
        "SELECT pm.post_id, pm.meta_value as zoo_id, p.post_title FROM $wpdb->postmeta pm JOIN $wpdb->posts p ON pm.post_id=p.ID WHERE pm.meta_key='_joomla_zoo_id' AND p.post_type='post' AND p.post_status='publish' ORDER BY pm.post_id ASC LIMIT %d, %d",
        $offset, $batch
    ));

    $fixed = 0; $ok = 0;

    foreach ($posts as $post) {
        $zoo_id = intval($post->zoo_id);
        $cq = $jdb->query("SELECT category_id FROM jos_zoo_category_item WHERE item_id = $zoo_id");
        $expected = [];
        if ($cq) { while ($cr = $cq->fetch_assoc()) { if (isset($cat_map[$cr['category_id']])) $expected[] = intval($cat_map[$cr['category_id']]); } }
        if (empty($expected)) continue;

        $current = wp_get_post_categories($post->post_id);
        $missing = array_diff($expected, $current);

        if (!empty($missing)) {
            if (!$dry_run) {
                wp_set_post_categories($post->post_id, array_unique(array_merge($current, $expected)));
                // Tags too
                $tq = $jdb->query("SELECT name FROM jos_zoo_tag WHERE item_id = $zoo_id");
                if ($tq && $tq->num_rows > 0) {
                    $tags = [];
                    while ($tr = $tq->fetch_assoc()) $tags[] = $tr['name'];
                    if (!empty($tags)) wp_set_post_tags($post->post_id, $tags, true);
                }
            }
            log_msg("+" . count($missing) . " cats: {$post->post_title}", 'success');
            $fixed++;
        } else { $ok++; }
    }

    $jdb->close();
    echo "</div></div>";

    echo "<div class='stat-grid'>";
    echo "<div class='stat-box ok'><div class='number'>$fixed</div><div class='label'>Actualizados</div></div>";
    echo "<div class='stat-box'><div class='number'>$ok</div><div class='label'>Correctos</div></div>";
    echo "</div>";

    $next = $offset + $batch;
    $progress = min(100, round(($next / max($total, 1)) * 100));

    echo "<div class='card'><div class='progress-bar'><div class='fill' style='width:{$progress}%'>$progress%</div></div>";
    if ($next < $total) {
        $url = "?action=fix_categories&offset=$next&batch=$batch";
        echo "<script>setTimeout(function(){ window.location.href='$url'; }, 1000);</script>";
        echo "<a href='$url' class='btn btn-primary'>Continuar</a> <a href='?action=audit' class='btn btn-secondary'>Detener</a>";
    } else {
        echo "<h3 style='color:#50c878;'>Completado</h3><a href='?action=audit' class='btn btn-primary'>Auditoría</a>";
    }
    echo "</div>";
    page_footer();
}

// ============================================================
// ACTION: FIX IDS (Populate visible ID)
// ============================================================
function do_fix_ids($offset, $batch, $dry_run) {
    global $wpdb;
    page_header('Fix IDs Visibles');
    echo "<div class='card'><h2>Poblando joomla_original_id</h2>";
    if ($dry_run) echo "<div class='dry-banner'>MODO DRY RUN</div>";
    echo "<div class='log'>";

    $total = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key='_joomla_zoo_id'");

    $posts = $wpdb->get_results($wpdb->prepare(
        "SELECT pm.post_id, pm.meta_value as zoo_id, p.post_title
         FROM $wpdb->postmeta pm
         JOIN $wpdb->posts p ON pm.post_id=p.ID
         WHERE pm.meta_key='_joomla_zoo_id' AND p.post_type='post'
         ORDER BY pm.post_id ASC LIMIT %d, %d",
        $offset, $batch
    ));

    $fixed = 0;
    $ok = 0;

    foreach ($posts as $post) {
        $zoo_id = $post->zoo_id;
        $wp_id = $post->post_id;

        $current_visible = get_post_meta($wp_id, 'joomla_original_id', true);

        if ($current_visible != $zoo_id) {
            if (!$dry_run) {
                update_post_meta($wp_id, 'joomla_original_id', $zoo_id);
                log_msg("Agregado ID visible $zoo_id a: {$post->post_title}", 'success');
            } else {
                log_msg("AGREGARÍA ID visible $zoo_id a: {$post->post_title}", 'info');
            }
            $fixed++;
        } else {
            $ok++;
        }
    }

    echo "</div></div>";

    echo "<div class='stat-grid'>";
    echo "<div class='stat-box ok'><div class='number'>$fixed</div><div class='label'>Actualizados</div></div>";
    echo "<div class='stat-box'><div class='number'>$ok</div><div class='label'>Correctos</div></div>";
    echo "</div>";

    $next = $offset + $batch;
    $progress = min(100, round(($next / max($total, 1)) * 100));

    echo "<div class='card'><div class='progress-bar'><div class='fill' style='width:{$progress}%'>$progress%</div></div>";
    if ($next < $total) {
        $dry_p = $dry_run ? '&dry_run=1' : '';
        $url = "?action=fix_ids&offset=$next&batch=$batch$dry_p";
        echo "<script>setTimeout(function(){ window.location.href='$url'; }, 500);</script>";
        echo "<a href='$url' class='btn btn-primary'>Continuar</a> <a href='?action=audit' class='btn btn-secondary'>Detener</a>";
    } else {
        echo "<h3 style='color:#50c878;'>Completado</h3><a href='?action=audit' class='btn btn-primary'>Auditoría</a>";
    }
    echo "</div>";
    page_footer();
}

// ============================================================
// ACTION: SYNC ALL
// ============================================================
function do_sync_all() {
    $step = isset($_GET['step']) ? $_GET['step'] : 'fix_urls';
    $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
    $batch = isset($_GET['batch']) ? intval($_GET['batch']) : 50;

    switch ($step) {
        case 'fix_urls':
            do_fix_image_urls($offset, $batch, false);
            echo "<script>setTimeout(function(){ window.location.href='?action=sync_all&step=fix_empty&offset=0&batch=$batch'; }, 2000);</script>";
            break;
        case 'fix_empty':
            do_fix_empty($offset, $batch, false);
            echo "<script>setTimeout(function(){ window.location.href='?action=sync_all&step=categories&offset=0&batch=$batch'; }, 2000);</script>";
            break;
        case 'categories':
            do_sync_categories(false);
            echo "<script>setTimeout(function(){ window.location.href='?action=sync_all&step=fix_ids&offset=0&batch=$batch'; }, 2000);</script>";
            break;
        case 'fix_ids':
            do_fix_ids($offset, $batch, false);
            echo "<script>setTimeout(function(){ window.location.href='?action=sync_all&step=posts&offset=0&batch=$batch'; }, 2000);</script>";
            break;
        case 'posts':
            do_sync_posts($offset, $batch, false);
            break;
    }
}

// ============================================================
// ROUTER
// ============================================================
switch ($action) {
    case 'audit':           do_audit(); break;
    case 'fix_image_urls':  do_fix_image_urls($offset, $batch, $dry_run); break;
    case 'fix_empty':       do_fix_empty($offset, $batch, $dry_run); break;
    case 'sync_categories': do_sync_categories($dry_run); break;
    case 'sync_posts':      do_sync_posts($offset, $batch, $dry_run); break;
    case 'fix_categories':  do_fix_categories($offset, $batch, $dry_run); break;
    case 'fix_ids':         do_fix_ids($offset, $batch, $dry_run); break;
    case 'sync_all':        do_sync_all(); break;
    default:                do_audit();
}
