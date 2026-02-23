<?php
/**
 * FIX DEFINITIVO - Joomla -> WordPress
 *
 * Orden de ejecución:
 *   PASO 1: ?action=dedup          -> Eliminar duplicados por zoo_id (conserva el mejor)
 *   PASO 2: ?action=recover        -> Recuperar contenido vacío desde Joomla (incluye base64->archivo)
 *   PASO 3: ?action=fix_images     -> Corregir src="images/..." en todos los posts
 *   PASO 4: ?action=fix_base64     -> Extraer base64 embebidas y guardar como archivos
 *   PASO 5: ?action=import_missing -> Importar los posts que faltan
 *
 *   ?action=audit    -> Diagnóstico completo
 *   ?action=fix_all  -> Ejecutar todo en secuencia
 */

set_time_limit(300);
ini_set('memory_limit', '1024M');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . '/wp-load.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/media.php');

define('J_HOST', '127.0.0.1'); define('J_PORT', 3306);
define('J_USER', 'root'); define('J_PASS', 'yue02'); define('J_DB', 'sudcalifornios');
define('UPLOADS_DIR', ABSPATH . 'wp-content/uploads');
define('UPLOADS_URL', site_url('/wp-content/uploads'));
define('JIMAGES_DIR', UPLOADS_DIR . '/joomla-images');

$action = isset($_GET['action']) ? $_GET['action'] : 'audit';
$offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
$batch  = isset($_GET['batch'])  ? intval($_GET['batch'])  : 50;
$step   = isset($_GET['step'])   ? $_GET['step']           : '';

function jdb() {
    static $conn = null;
    if (!$conn) {
        $conn = new mysqli(J_HOST, J_USER, J_PASS, J_DB, J_PORT);
        if ($conn->connect_error) die("Error Joomla DB: " . $conn->connect_error);
        $conn->set_charset("utf8");
    }
    return $conn;
}

// ============================================================
// FILE INDEX
// ============================================================
function build_file_index() {
    static $index = null;
    if ($index !== null) return $index;
    $index = [];
    if (!is_dir(JIMAGES_DIR)) return $index;
    $di = new RecursiveDirectoryIterator(JIMAGES_DIR, RecursiveDirectoryIterator::SKIP_DOTS);
    $it = new RecursiveIteratorIterator($di);
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $path = $file->getPathname();
        if (strpos($path, ' - copia') !== false) continue;
        $fname = strtolower(basename($path));
        $rel = str_replace([UPLOADS_DIR . '/', UPLOADS_DIR . '\\'], '', $path);
        $rel = str_replace('\\', '/', $rel);
        if (!isset($index[$fname])) $index[$fname] = $rel;
    }
    return $index;
}

// ============================================================
// EXTRACT ZOO CONTENT (mejorado: busca en TODOS los UUIDs)
// ============================================================
function extract_content($elements_json) {
    $e = json_decode($elements_json, true);
    if (!$e || !is_array($e)) return '';

    // Lista de UUIDs conocidos para contenido principal (textarea/wysiwyg)
    $content_uuids = [
        '2e3c9e69-1f9e-4647-8d13-4e88094d2790', // UUID principal conocido
    ];

    $content = '';

    // PASO 1: Intentar con UUIDs conocidos
    foreach ($content_uuids as $uuid) {
        if (isset($e[$uuid]) && is_array($e[$uuid])) {
            foreach ($e[$uuid] as $item) {
                if (isset($item['value']) && is_string($item['value']) && strlen($item['value']) > 10)
                    $content .= $item['value'] . "\n";
            }
        }
    }

    // PASO 2: Si no encontró con UUID conocido, buscar en TODOS los UUIDs
    // Busca arrays que contengan items con 'value' que sea HTML largo (probable textarea)
    if (empty(trim(strip_tags($content)))) {
        $candidates = [];
        foreach ($e as $uuid => $items) {
            if (!is_array($items)) continue;
            foreach ($items as $item) {
                if (!is_array($item)) continue;
                if (isset($item['value']) && is_string($item['value'])) {
                    $val = $item['value'];
                    $text_len = strlen(trim(strip_tags($val)));
                    $is_html = ($val !== strip_tags($val));
                    // Candidato: tiene HTML y más de 30 chars, o texto plano > 100 chars
                    if (($is_html && $text_len > 20) || $text_len > 100) {
                        $candidates[] = ['uuid' => $uuid, 'value' => $val, 'len' => $text_len, 'html' => $is_html];
                    }
                }
            }
        }
        // Ordenar por longitud descendente y tomar el más largo (es el contenido principal)
        usort($candidates, function($a, $b) { return $b['len'] - $a['len']; });
        foreach ($candidates as $c) {
            $content .= $c['value'] . "\n";
        }
    }

    // PASO 3: Último recurso - walk recursivo
    if (empty(trim(strip_tags($content)))) {
        array_walk_recursive($e, function($v, $k) use (&$content) {
            if ($k === 'value' && is_string($v) && (strlen($v) > 100 || ($v !== strip_tags($v) && strlen($v) > 30)))
                $content .= $v . "\n";
        });
    }

    return trim($content);
}

// ============================================================
// FIX src="images/..." URLS
// ============================================================
function fix_joomla_image_urls($html) {
    $index = build_file_index();
    $count = 0;
    $not_found = [];
    $html = preg_replace_callback(
        '/src=["\'](?:\.\/)?images\/([^"\']+)["\']/i',
        function($m) use ($index, &$count, &$not_found) {
            $fname = strtolower(basename(urldecode($m[1])));
            if (isset($index[$fname])) {
                $count++;
                return 'src="' . UPLOADS_URL . '/' . $index[$fname] . '"';
            }
            $not_found[] = urldecode($m[1]);
            return $m[0];
        },
        $html
    );
    return ['html' => $html, 'fixed' => $count, 'not_found' => $not_found];
}

// ============================================================
// SAVE BASE64 IMAGE AS FILE
// ============================================================
function save_base64_image($base64_data, $mime_type, $post_id = 0) {
    // Determine extension
    $ext_map = [
        'image/jpeg' => 'jpg', 'image/jpg' => 'jpg',
        'image/png' => 'png', 'image/gif' => 'gif',
        'image/webp' => 'webp', 'image/bmp' => 'bmp',
    ];
    $ext = isset($ext_map[$mime_type]) ? $ext_map[$mime_type] : 'jpg';

    $decoded = base64_decode($base64_data);
    if ($decoded === false || strlen($decoded) < 100) return false;

    $upload_dir = wp_upload_dir();
    $filename = 'joomla-b64-' . $post_id . '-' . substr(md5($base64_data), 0, 8) . '.' . $ext;
    $filename = wp_unique_filename($upload_dir['path'], $filename);
    $filepath = $upload_dir['path'] . '/' . $filename;

    if (file_put_contents($filepath, $decoded) === false) return false;

    $filetype = wp_check_filetype($filename, null);
    $attach_id = wp_insert_attachment([
        'guid'           => $upload_dir['url'] . '/' . $filename,
        'post_mime_type' => $filetype['type'] ?: $mime_type,
        'post_title'     => pathinfo($filename, PATHINFO_FILENAME),
        'post_content'   => '',
        'post_status'    => 'inherit'
    ], $filepath, $post_id);

    if (is_wp_error($attach_id)) {
        @unlink($filepath);
        return false;
    }

    wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $filepath));

    return $upload_dir['url'] . '/' . $filename;
}

// ============================================================
// FIX BASE64 IMAGES IN HTML
// ============================================================
function fix_base64_in_string($html, $post_id = 0) {
    $count = 0;
    $html = preg_replace_callback(
        '/src=["\']data:image\/([\w\+]+);base64,([A-Za-z0-9+\/=\s]+)["\']/i',
        function($m) use (&$count, $post_id) {
            $mime = 'image/' . $m[1];
            $data = preg_replace('/\s+/', '', $m[2]);
            $url = save_base64_image($data, $mime, $post_id);
            if ($url) {
                $count++;
                return 'src="' . $url . '"';
            }
            return $m[0];
        },
        $html
    );
    return ['html' => $html, 'fixed' => $count];
}

// ============================================================
// HTML UI
// ============================================================
function page_header($title) {
    echo "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'><title>$title</title><style>
        *{box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0d1117;color:#c9d1d9;padding:20px;margin:0}
        .container{max-width:1200px;margin:0 auto}.card{background:#161b22;border:1px solid #30363d;padding:20px;border-radius:8px;margin-bottom:16px}
        h1,h2{color:#58a6ff;margin-top:0}.log{background:#010409;color:#7ee787;padding:15px;border-radius:6px;max-height:600px;overflow-y:auto;font-family:Consolas,monospace;font-size:13px;line-height:1.6;white-space:pre-wrap}
        .log .error{color:#f85149}.log .warn{color:#d29922}.log .info{color:#58a6ff}.log .success{color:#7ee787}
        .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:16px}
        .stat-box{background:#21262d;padding:12px;border-radius:6px;text-align:center;border:1px solid #30363d}
        .stat-box .number{font-size:1.8em;font-weight:bold;color:#f85149}.stat-box .label{color:#8b949e;font-size:.85em;margin-top:4px}
        .stat-box.ok .number{color:#7ee787}.stat-box.warn .number{color:#d29922}
        .btn{display:inline-block;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:600;margin:4px;font-size:14px;border:1px solid #30363d;cursor:pointer}
        .btn-blue{background:#1f6feb;color:#fff;border-color:#1f6feb}.btn-green{background:#238636;color:#fff;border-color:#238636}
        .btn-yellow{background:#9e6a03;color:#fff;border-color:#9e6a03}.btn-red{background:#da3633;color:#fff;border-color:#da3633}
        .btn-gray{background:#21262d;color:#58a6ff}
        .nav{margin-bottom:16px;padding:12px;background:#161b22;border-radius:8px;border:1px solid #30363d;display:flex;flex-wrap:wrap;align-items:center;gap:6px}
        .progress{background:#21262d;height:24px;border-radius:12px;overflow:hidden;margin:12px 0}
        .progress .fill{background:linear-gradient(90deg,#1f6feb,#238636);height:100%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:bold}
        table{width:100%;border-collapse:collapse}th,td{padding:6px 10px;text-align:left;border-bottom:1px solid #21262d;font-size:13px}th{color:#8b949e}
        .step{display:inline-block;width:28px;height:28px;line-height:28px;text-align:center;border-radius:50%;background:#21262d;color:#58a6ff;font-weight:bold;margin-right:6px;font-size:13px}
        .step.done{background:#238636;color:#fff}.step.active{background:#1f6feb;color:#fff}
    </style></head><body><div class='container'>";
    echo "<div class='nav'><strong style='color:#58a6ff;font-size:15px'>Fix Definitivo&nbsp;&nbsp;</strong>";
    echo "<a href='?action=audit' class='btn btn-blue'>Auditoría</a>";
    echo "<a href='?action=dedup' class='btn btn-red'>1. Dedup</a>";
    echo "<a href='?action=recover' class='btn btn-green'>2. Recuperar</a>";
    echo "<a href='?action=fix_images' class='btn btn-yellow'>3. Fix Images</a>";
    echo "<a href='?action=fix_base64' class='btn btn-yellow'>4. Fix Base64</a>";
    echo "<a href='?action=import_missing' class='btn btn-green'>5. Importar</a>";
    echo "<a href='?action=fix_all' class='btn btn-green' style='background:#1f6feb'>Fix Todo</a>";
    echo "<a href='?action=diagnose_empty' class='btn btn-gray' style='margin-left:12px'>Diagnosticar vacíos</a>";
    echo "</div>";
}
function pf() { echo "</div></body></html>"; }
function lm($m, $c='info') { echo "<div class='$c'>$m</div>\n"; @ob_flush(); @flush(); }

// ============================================================
// AUDIT
// ============================================================
function do_audit() {
    global $wpdb;
    page_header('Auditoría Definitiva');
    $j = jdb();

    // ---- JOOMLA: desglose completo por tipo y estado ----
    $j_art_pub   = $j->query("SELECT COUNT(*) c FROM jos_zoo_item WHERE type='article' AND state=1")->fetch_assoc()['c'];
    $j_art_unpub = $j->query("SELECT COUNT(*) c FROM jos_zoo_item WHERE type='article' AND state=0")->fetch_assoc()['c'];
    $j_art_other = $j->query("SELECT COUNT(*) c FROM jos_zoo_item WHERE type='article' AND state NOT IN(0,1)")->fetch_assoc()['c'];
    $j_art_total = $j_art_pub + $j_art_unpub + $j_art_other;
    $j_page_pub  = $j->query("SELECT COUNT(*) c FROM jos_zoo_item WHERE type='page' AND state=1")->fetch_assoc()['c'];
    $j_page_unpub= $j->query("SELECT COUNT(*) c FROM jos_zoo_item WHERE type='page' AND state!=1")->fetch_assoc()['c'];
    $j_auth_pub  = $j->query("SELECT COUNT(*) c FROM jos_zoo_item WHERE type='author' AND state=1")->fetch_assoc()['c'];
    $j_auth_unpub= $j->query("SELECT COUNT(*) c FROM jos_zoo_item WHERE type='author' AND state!=1")->fetch_assoc()['c'];
    $j_auth_total= $j_auth_pub + $j_auth_unpub;
    $j_all_items = $j->query("SELECT COUNT(*) c FROM jos_zoo_item")->fetch_assoc()['c'];
    // Todos los artículos+páginas (cualquier estado, excluyendo autores)
    $j_content_total = $j_art_total + $j_page_pub + $j_page_unpub;
    $j_b64_all   = $j->query("SELECT COUNT(*) c FROM jos_zoo_item WHERE type IN('article','page') AND elements LIKE '%data:image%'")->fetch_assoc()['c'];

    // ---- WORDPRESS ----
    $wp_pub     = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status='publish'");
    $wp_draft   = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status='draft'");
    $wp_trash   = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status='trash'");
    $wp_pending = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status='pending'");
    $wp_total   = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post'");
    $wp_tracked = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key='_joomla_zoo_id'");

    // Duplicates by zoo_id (EXCLUDING trashed posts - dedup already handled them)
    $dup_zoo    = $wpdb->get_var("SELECT COUNT(*) FROM (
        SELECT pm.meta_value, COUNT(*) cnt FROM $wpdb->postmeta pm
        JOIN $wpdb->posts p ON p.ID=pm.post_id
        WHERE pm.meta_key='_joomla_zoo_id' AND p.post_status NOT IN('trash','inherit')
        GROUP BY pm.meta_value HAVING cnt>1) t");
    $dup_excess = $wpdb->get_var("SELECT COALESCE(SUM(cnt-1),0) FROM (
        SELECT pm.meta_value, COUNT(*) cnt FROM $wpdb->postmeta pm
        JOIN $wpdb->posts p ON p.ID=pm.post_id
        WHERE pm.meta_key='_joomla_zoo_id' AND p.post_status NOT IN('trash','inherit')
        GROUP BY pm.meta_value HAVING cnt>1) t");

    // Empty content (any status except trash)
    $empty = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id' WHERE p.post_type='post' AND p.post_status NOT IN('trash','inherit') AND LENGTH(p.post_content)<50");

    // Broken images (any status)
    $broken_img = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status NOT IN('trash','inherit') AND post_content LIKE '%src=\"images/%'");

    // Base64 in WP (any status)
    $wp_b64 = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status NOT IN('trash','inherit') AND post_content LIKE '%data:image%'");

    // Missing posts: ALL articles+pages from Joomla (any state), not just published
    // Only count zoo_ids from NON-trashed posts as "imported"
    $imported = array_flip($wpdb->get_col("
        SELECT pm.meta_value FROM $wpdb->postmeta pm
        JOIN $wpdb->posts p ON p.ID=pm.post_id
        WHERE pm.meta_key='_joomla_zoo_id' AND p.post_status NOT IN('trash','inherit')
    "));
    $r = $j->query("SELECT id, state FROM jos_zoo_item WHERE type IN('article','page')");
    $missing_pub = 0; $missing_unpub = 0; $missing_total = 0;
    while ($row = $r->fetch_assoc()) {
        if (!isset($imported[$row['id']])) {
            $missing_total++;
            if ($row['state'] == 1) $missing_pub++; else $missing_unpub++;
        }
    }

    // ---- STAT BOXES ----
    echo "<div class='stat-grid'>";
    echo "<div class='stat-box" . ($dup_excess > 0 ? '' : ' ok') . "'><div class='number'>$dup_excess</div><div class='label'>Duplicados (zoo_id)</div></div>";
    echo "<div class='stat-box" . ($empty > 0 ? ' warn' : ' ok') . "'><div class='number'>$empty</div><div class='label'>Posts vacíos</div></div>";
    echo "<div class='stat-box" . ($broken_img > 0 ? '' : ' ok') . "'><div class='number'>$broken_img</div><div class='label'>src=\"images/...\"</div></div>";
    echo "<div class='stat-box" . ($wp_b64 > 0 ? ' warn' : ' ok') . "'><div class='number'>$wp_b64 / $j_b64_all</div><div class='label'>Base64 WP/Joomla</div></div>";
    echo "<div class='stat-box" . ($missing_total > 0 ? '' : ' ok') . "'><div class='number'>$missing_total</div><div class='label'>Faltan importar</div></div>";
    echo "<div class='stat-box ok'><div class='number'>$wp_pub</div><div class='label'>WP publicados</div></div>";
    echo "</div>";

    // ---- DESGLOSE JOOMLA ----
    echo "<div class='card'><h2>Inventario Joomla Zoo (TODOS los items)</h2><div class='log'>";
    lm("=== ARTÍCULOS (type=article) ===", 'info');
    lm("  Publicados (state=1):    $j_art_pub", 'success');
    lm("  No publicados (state=0): $j_art_unpub", $j_art_unpub > 0 ? 'warn' : 'info');
    if ($j_art_other > 0) lm("  Otros estados:           $j_art_other", 'warn');
    lm("  TOTAL artículos:         $j_art_total", 'info');

    lm("", 'info');
    lm("=== PÁGINAS (type=page) ===", 'info');
    lm("  Publicadas: $j_page_pub | No publicadas: $j_page_unpub", 'info');

    lm("", 'info');
    lm("=== AUTORES (type=author) ===", 'info');
    lm("  Publicados: $j_auth_pub | No publicados: $j_auth_unpub | Total: $j_auth_total", 'info');

    lm("", 'info');
    lm("=== RESUMEN JOOMLA ===", 'info');
    lm("  Total items en Zoo: $j_all_items", 'info');
    lm("  Artículos+Páginas (TODOS los estados): $j_content_total", 'info');
    lm("  Con base64 embebida: $j_b64_all", 'info');
    echo "</div></div>";

    // ---- DESGLOSE WORDPRESS ----
    echo "<div class='card'><h2>Inventario WordPress</h2><div class='log'>";
    lm("=== POSTS POR ESTADO ===", 'info');
    lm("  Publicados:  $wp_pub", 'success');
    lm("  Borradores:  $wp_draft", $wp_draft > 0 ? 'warn' : 'info');
    lm("  Pendientes:  $wp_pending", $wp_pending > 0 ? 'warn' : 'info');
    lm("  Papelera:    $wp_trash", $wp_trash > 0 ? 'warn' : 'info');
    lm("  TOTAL posts: $wp_total", 'info');
    lm("  Con _joomla_zoo_id: $wp_tracked", 'info');
    echo "</div></div>";

    // ---- COMPARACIÓN ----
    echo "<div class='card'><h2>Comparación 1 a 1</h2><div class='log'>";
    lm("=== FALTANTES ===", $missing_total > 0 ? 'error' : 'success');
    lm("  Publicados en Joomla, no en WP: $missing_pub", $missing_pub > 0 ? 'error' : 'success');
    lm("  No publicados en Joomla, no en WP: $missing_unpub", $missing_unpub > 0 ? 'warn' : 'info');
    lm("  TOTAL faltantes: $missing_total", $missing_total > 0 ? 'error' : 'success');

    lm("", 'info');
    lm("=== DUPLICADOS ===", $dup_excess > 0 ? 'error' : 'success');
    lm("  Zoo_ids repetidos: $dup_zoo grupos = $dup_excess posts sobrantes", $dup_excess > 0 ? 'error' : 'success');

    lm("", 'info');
    lm("=== CONTENIDO ===", 'info');
    lm("  Posts vacíos (<50 chars): $empty", $empty > 0 ? 'warn' : 'success');
    lm("  Imágenes rotas (src=\"images/...\"): $broken_img", $broken_img > 0 ? 'error' : 'success');
    lm("  Base64: $j_b64_all en Joomla, $wp_b64 en WP", ($j_b64_all > $wp_b64) ? 'warn' : 'success');

    // Show some missing if any
    if ($missing_total > 0) {
        lm("", 'info');
        lm("=== MUESTRA DE FALTANTES ===", 'warn');
        $r = $j->query("SELECT id, name, type, state FROM jos_zoo_item WHERE type IN('article','page') ORDER BY id DESC");
        $shown = 0;
        while ($row = $r->fetch_assoc()) {
            if (!isset($imported[$row['id']])) {
                $st = $row['state'] == 1 ? 'PUB' : 'NOPUB';
                lm("  Zoo:{$row['id']} [$st] {$row['type']} | {$row['name']}", $row['state'] == 1 ? 'error' : 'warn');
                $shown++;
                if ($shown >= 20) { lm("  ... y " . ($missing_total - 20) . " más", 'warn'); break; }
            }
        }
    }

    echo "</div></div>";

    // ---- PLAN DE EJECUCIÓN ----
    echo "<div class='card'><h2>Plan de ejecución</h2>";
    echo "<div style='line-height:2.5;'>";

    $s1 = $dup_excess > 0 ? 'active' : 'done';
    $s2 = $empty > 0 ? 'active' : 'done';
    $s3 = $broken_img > 0 ? 'active' : 'done';
    $s4 = ($wp_b64 > 0 || $j_b64_all > $wp_b64) ? 'active' : 'done';
    $s5 = $missing_total > 0 ? 'active' : 'done';

    echo "<span class='step $s1'>1</span><strong>Deduplicar</strong> — $dup_excess posts sobrantes (mismo zoo_id importado varias veces) ";
    if ($dup_excess > 0) echo "<a href='?action=dedup' class='btn btn-red'>Ejecutar</a>";
    echo "<br>";

    echo "<span class='step $s2'>2</span><strong>Recuperar contenido</strong> — $empty posts vacíos → re-extraer desde Joomla ";
    if ($empty > 0) echo "<a href='?action=recover' class='btn btn-green'>Ejecutar</a>";
    echo "<br>";

    echo "<span class='step $s3'>3</span><strong>Fix rutas imágenes</strong> — $broken_img posts con src=\"images/...\" ";
    if ($broken_img > 0) echo "<a href='?action=fix_images' class='btn btn-yellow'>Ejecutar</a>";
    echo "<br>";

    echo "<span class='step $s4'>4</span><strong>Fix base64</strong> — $j_b64_all en Joomla → extraer a archivos ";
    if ($j_b64_all > 0) echo "<a href='?action=fix_base64' class='btn btn-yellow'>Ejecutar</a>";
    echo "<br>";

    echo "<span class='step $s5'>5</span><strong>Importar faltantes</strong> — $missing_pub publicados + $missing_unpub no publicados = $missing_total total ";
    if ($missing_total > 0) echo "<a href='?action=import_missing' class='btn btn-green'>Ejecutar</a>";
    echo "<br>";

    echo "<br><a href='?action=fix_all' class='btn btn-green' style='background:#1f6feb;font-size:16px;padding:12px 24px'>Ejecutar TODO en secuencia</a>";
    echo "</div></div>";

    pf();
}

// ============================================================
// PASO 1: DEDUP BY ZOO_ID
// ============================================================
function do_dedup($offset, $batch) {
    global $wpdb;
    page_header('Paso 1: Deduplicar');

    // Only count non-trashed posts for dedup (trashed already handled)
    $total_groups = $wpdb->get_var("SELECT COUNT(*) FROM (
        SELECT pm.meta_value FROM $wpdb->postmeta pm
        JOIN $wpdb->posts p ON p.ID=pm.post_id
        WHERE pm.meta_key='_joomla_zoo_id' AND p.post_status NOT IN('trash','inherit')
        GROUP BY pm.meta_value HAVING COUNT(*)>1) t");
    $total_excess = $wpdb->get_var("SELECT COALESCE(SUM(cnt-1),0) FROM (
        SELECT pm.meta_value, COUNT(*) cnt FROM $wpdb->postmeta pm
        JOIN $wpdb->posts p ON p.ID=pm.post_id
        WHERE pm.meta_key='_joomla_zoo_id' AND p.post_status NOT IN('trash','inherit')
        GROUP BY pm.meta_value HAVING cnt>1) t");

    echo "<div class='card'><h2>Eliminando duplicados por zoo_id (lote $offset)</h2><div class='log'>";
    lm("Grupos duplicados (excl. papelera): $total_groups | Posts sobrantes: $total_excess", 'info');

    // Get batch of duplicate zoo_ids (excluding trashed)
    $dups = $wpdb->get_results($wpdb->prepare(
        "SELECT pm.meta_value as zoo_id, COUNT(*) cnt FROM $wpdb->postmeta pm
         JOIN $wpdb->posts p ON p.ID=pm.post_id
         WHERE pm.meta_key='_joomla_zoo_id' AND p.post_status NOT IN('trash','inherit')
         GROUP BY pm.meta_value HAVING cnt>1 ORDER BY cnt DESC LIMIT %d, %d",
        $offset, $batch
    ));

    $removed = 0;

    foreach ($dups as $d) {
        // Get all WP posts for this zoo_id, order by content length DESC
        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, LENGTH(p.post_content) as clen, p.post_status
             FROM $wpdb->posts p
             JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id'
             WHERE pm.meta_value=%s
             ORDER BY LENGTH(p.post_content) DESC, p.ID ASC",
            $d->zoo_id
        ));

        if (count($posts) < 2) continue;

        $keep = $posts[0]; // Keep the one with most content
        for ($i = 1; $i < count($posts); $i++) {
            $rid = $posts[$i]->ID;
            $wpdb->update($wpdb->posts, ['post_status' => 'trash'], ['ID' => $rid]);
            clean_post_cache($rid);
            $removed++;
        }

        $keep_info = "ID:{$keep->ID} ({$keep->clen}chars)";
        lm("Zoo:{$d->zoo_id} x{$d->cnt} -> conserva $keep_info, trash " . ($d->cnt - 1) . " | {$keep->post_title}", 'success');
    }

    echo "</div></div>";

    echo "<div class='stat-grid'>";
    echo "<div class='stat-box ok'><div class='number'>$removed</div><div class='label'>Movidos a papelera</div></div>";
    echo "<div class='stat-box'><div class='number'>$total_excess</div><div class='label'>Total a limpiar</div></div>";
    echo "</div>";

    $processed = $offset + count($dups);
    $pct = $total_groups > 0 ? min(100, round(($processed / $total_groups) * 100)) : 100;
    echo "<div class='card'><div class='progress'><div class='fill' style='width:{$pct}%'>$pct%</div></div>";

    if (count($dups) >= $batch) {
        $next = $offset + $batch;
        $url = "?action=dedup&offset=$next&batch=$batch";
        echo "<script>setTimeout(function(){window.location.href='$url';},1500);</script>";
        echo "<a href='$url' class='btn btn-blue'>Siguiente</a> <a href='?action=audit' class='btn btn-gray'>Detener</a>";
    } else {
        echo "<p style='color:#7ee787;font-weight:bold'>Deduplicación completada.</p>";
        echo "<a href='?action=audit' class='btn btn-blue'>Auditoría</a> <a href='?action=recover' class='btn btn-green'>Paso 2: Recuperar</a>";
    }
    echo "</div>";
    pf();
}

// ============================================================
// PASO 2: RECOVER CONTENT
// ============================================================
function do_recover($offset, $batch) {
    global $wpdb;
    page_header('Paso 2: Recuperar Contenido');
    $j = jdb();

    // Search ALL statuses except trash
    $total = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts p
        JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id'
        WHERE p.post_type='post' AND p.post_status NOT IN('trash','inherit') AND LENGTH(p.post_content)<50");

    echo "<div class='card'><h2>Recuperando contenido ($offset / $total)</h2><div class='log'>";

    $posts = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_title, p.post_status, pm.meta_value as zoo_id
         FROM $wpdb->posts p
         JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id'
         WHERE p.post_type='post' AND p.post_status NOT IN('trash','inherit') AND LENGTH(p.post_content)<50
         ORDER BY p.ID ASC LIMIT %d, %d",
        $offset, $batch
    ));

    $recovered = 0; $skipped = 0; $b64_saved = 0;

    foreach ($posts as $post) {
        $zoo_id = intval($post->zoo_id);

        // Check Joomla type - skip authors
        $jr = $j->query("SELECT type, elements FROM jos_zoo_item WHERE id=$zoo_id");
        if (!$jr || $jr->num_rows == 0) { $skipped++; continue; }
        $jrow = $jr->fetch_assoc();
        if ($jrow['type'] === 'author') { $skipped++; continue; }

        $content = extract_content($jrow['elements']);
        if (empty(trim(strip_tags($content)))) { $skipped++; continue; }

        // Fix image URLs
        $img_r = fix_joomla_image_urls($content);
        $content = $img_r['html'];

        // Convert base64 to files
        $b64_r = fix_base64_in_string($content, $post->ID);
        $content = $b64_r['html'];
        $b64_saved += $b64_r['fixed'];

        $wpdb->update($wpdb->posts, ['post_content' => $content], ['ID' => $post->ID]);
        clean_post_cache($post->ID);

        $extras = [];
        if ($img_r['fixed'] > 0) $extras[] = "+{$img_r['fixed']}imgs";
        if ($b64_r['fixed'] > 0) $extras[] = "+{$b64_r['fixed']}b64";
        $extra_txt = !empty($extras) ? ' (' . implode(', ', $extras) . ')' : '';

        lm("Recuperado: {$post->post_title} (" . strlen($content) . "chars)$extra_txt", 'success');
        $recovered++;
    }

    echo "</div></div>";

    echo "<div class='stat-grid'>";
    echo "<div class='stat-box ok'><div class='number'>$recovered</div><div class='label'>Recuperados</div></div>";
    echo "<div class='stat-box'><div class='number'>$skipped</div><div class='label'>Sin contenido / Autores</div></div>";
    echo "<div class='stat-box ok'><div class='number'>$b64_saved</div><div class='label'>Base64 → archivo</div></div>";
    echo "</div>";

    $processed = $offset + count($posts);
    $pct = $total > 0 ? min(100, round(($processed / $total) * 100)) : 100;
    echo "<div class='card'><div class='progress'><div class='fill' style='width:{$pct}%'>$pct%</div></div>";

    if (count($posts) >= $batch) {
        $next = $offset + $batch;
        $url = "?action=recover&offset=$next&batch=$batch";
        echo "<script>setTimeout(function(){window.location.href='$url';},2000);</script>";
        echo "<a href='$url' class='btn btn-blue'>Siguiente</a> <a href='?action=audit' class='btn btn-gray'>Detener</a>";
    } else {
        echo "<p style='color:#7ee787;font-weight:bold'>Recuperación completada.</p>";
        echo "<a href='?action=audit' class='btn btn-blue'>Auditoría</a> <a href='?action=fix_images' class='btn btn-yellow'>Paso 3: Fix Images</a>";
    }
    echo "</div>";
    pf();
}

// ============================================================
// PASO 3: FIX IMAGES (src="images/...")
// ============================================================
function do_fix_images() {
    global $wpdb;
    page_header('Paso 3: Fix Imágenes');

    echo "<div class='card'><h2>Corrigiendo src=\"images/...\"</h2><div class='log'>";

    lm("Construyendo índice de archivos...", 'info');
    $index = build_file_index();
    lm("Indexados " . count($index) . " archivos en joomla-images/", 'success');

    $posts = $wpdb->get_results("SELECT ID, post_title, post_content FROM $wpdb->posts
        WHERE post_type='post' AND post_content LIKE '%src=\"images/%'");
    lm("Posts con src=\"images/...\": " . count($posts), 'info');
    lm("", 'info');

    $fixed_posts = 0; $fixed_urls = 0; $all_nf = [];

    foreach ($posts as $post) {
        $r = fix_joomla_image_urls($post->post_content);
        if ($r['fixed'] > 0) {
            $wpdb->update($wpdb->posts, ['post_content' => $r['html']], ['ID' => $post->ID]);
            clean_post_cache($post->ID);
            lm("Reparadas {$r['fixed']}: {$post->post_title} (ID:{$post->ID})", 'success');
            $fixed_posts++; $fixed_urls += $r['fixed'];
        }
        if (!empty($r['not_found'])) {
            foreach ($r['not_found'] as $nf) {
                $all_nf[] = $nf;
                lm("  No encontrada: images/$nf", 'error');
            }
        }
    }

    echo "</div></div>";

    echo "<div class='stat-grid'>";
    echo "<div class='stat-box ok'><div class='number'>$fixed_posts</div><div class='label'>Posts reparados</div></div>";
    echo "<div class='stat-box ok'><div class='number'>$fixed_urls</div><div class='label'>URLs corregidas</div></div>";
    echo "<div class='stat-box" . (count($all_nf) > 0 ? '' : ' ok') . "'><div class='number'>" . count($all_nf) . "</div><div class='label'>No encontradas</div></div>";
    echo "</div>";

    echo "<div class='card'>";
    $remaining = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_content LIKE '%src=\"images/%'");
    if ($remaining == 0) echo "<p style='color:#7ee787;font-weight:bold'>Todas las rutas corregidas.</p>";
    else echo "<p style='color:#d29922'>Quedan $remaining posts (archivos no encontrados).</p>";
    echo "<a href='?action=audit' class='btn btn-blue'>Auditoría</a> <a href='?action=fix_base64' class='btn btn-yellow'>Paso 4: Fix Base64</a>";
    echo "</div>";
    pf();
}

// ============================================================
// PASO 4: FIX BASE64
// ============================================================
function do_fix_base64($offset, $batch) {
    global $wpdb;
    page_header('Paso 4: Fix Base64');
    $j = jdb();

    // First, fix base64 already in WP posts
    echo "<div class='card'><h2>Convirtiendo base64 a archivos (lote $offset)</h2><div class='log'>";

    // Get ALL posts that have _joomla_zoo_id (any status except trash)
    // We process in batches to avoid timeout
    $total = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id' WHERE p.post_type='post' AND p.post_status NOT IN('trash','inherit')");

    $posts = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_title, p.post_content, pm.meta_value as zoo_id
         FROM $wpdb->posts p
         JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id'
         WHERE p.post_type='post' AND p.post_status NOT IN('trash','inherit')
         ORDER BY p.ID ASC LIMIT %d, %d",
        $offset, $batch
    ));

    $fixed_posts = 0; $fixed_imgs = 0; $recovered_b64 = 0;

    foreach ($posts as $post) {
        $content = $post->post_content;
        $changed = false;

        // A) If WP content has base64, convert them to files
        if (strpos($content, 'data:image') !== false) {
            $r = fix_base64_in_string($content, $post->ID);
            if ($r['fixed'] > 0) {
                $content = $r['html'];
                $fixed_imgs += $r['fixed'];
                $changed = true;
                lm("WP base64 -> archivo: {$r['fixed']} imgs en {$post->post_title}", 'success');
            }
        }

        // B) If WP content is short/empty, check Joomla for base64 OR any content to recover
        if (strlen($post->post_content) < 50) {
            $zoo_id = intval($post->zoo_id);
            $jr = $j->query("SELECT type, elements FROM jos_zoo_item WHERE id=$zoo_id");
            if ($jr && $jr->num_rows > 0) {
                $jrow = $jr->fetch_assoc();
                if ($jrow['type'] !== 'author' && strpos($jrow['elements'], 'data:image') !== false) {
                    $jcontent = extract_content($jrow['elements']);
                    if (!empty(trim(strip_tags($jcontent)))) {
                        // Fix images and base64 in recovered content
                        $img_r = fix_joomla_image_urls($jcontent);
                        $jcontent = $img_r['html'];
                        $b64_r = fix_base64_in_string($jcontent, $post->ID);
                        $jcontent = $b64_r['html'];

                        $content = $jcontent;
                        $recovered_b64 += $b64_r['fixed'];
                        $changed = true;
                        lm("Recuperado con base64: {$post->post_title} ({$b64_r['fixed']} imgs)", 'success');
                    }
                }
            }
        }

        if ($changed) {
            $wpdb->update($wpdb->posts, ['post_content' => $content], ['ID' => $post->ID]);
            clean_post_cache($post->ID);
            $fixed_posts++;
        }
    }

    echo "</div></div>";

    echo "<div class='stat-grid'>";
    echo "<div class='stat-box ok'><div class='number'>$fixed_posts</div><div class='label'>Posts modificados</div></div>";
    echo "<div class='stat-box ok'><div class='number'>$fixed_imgs</div><div class='label'>Base64 existentes → archivo</div></div>";
    echo "<div class='stat-box ok'><div class='number'>$recovered_b64</div><div class='label'>Base64 recuperadas de Joomla</div></div>";
    echo "</div>";

    $processed = $offset + count($posts);
    $pct = $total > 0 ? min(100, round(($processed / $total) * 100)) : 100;
    echo "<div class='card'><div class='progress'><div class='fill' style='width:{$pct}%'>$pct%</div></div>";

    if (count($posts) >= $batch) {
        $next = $offset + $batch;
        $url = "?action=fix_base64&offset=$next&batch=$batch";
        echo "<script>setTimeout(function(){window.location.href='$url';},1500);</script>";
        echo "<a href='$url' class='btn btn-blue'>Siguiente</a> <a href='?action=audit' class='btn btn-gray'>Detener</a>";
    } else {
        echo "<p style='color:#7ee787;font-weight:bold'>Base64 completado.</p>";
        echo "<a href='?action=audit' class='btn btn-blue'>Auditoría</a> <a href='?action=import_missing' class='btn btn-green'>Paso 5: Importar</a>";
    }
    echo "</div>";
    pf();
}

// ============================================================
// PASO 5: IMPORT MISSING
// ============================================================
function joomla_state_to_wp($state) {
    // Joomla: 1=published, 0=unpublished, -1=archived, -2=trashed, 2=pending
    switch (intval($state)) {
        case 1:  return 'publish';
        case 0:  return 'draft';
        case 2:  return 'pending';
        case -1: return 'draft';   // archived -> draft
        case -2: return 'trash';
        default: return 'draft';
    }
}

function do_import_missing($offset, $batch) {
    global $wpdb;
    page_header('Paso 5: Importar Faltantes');
    $j = jdb();

    // Only count non-trashed posts as "already imported"
    $imported = array_flip($wpdb->get_col("
        SELECT pm.meta_value FROM $wpdb->postmeta pm
        JOIN $wpdb->posts p ON p.ID=pm.post_id
        WHERE pm.meta_key='_joomla_zoo_id' AND p.post_status NOT IN('trash','inherit')
    "));
    $cat_map = get_option('joomla_zoo_category_map', []);

    // Rebuild cat_map if empty - include ALL categories, not just published
    if (empty($cat_map)) {
        $cats = $j->query("SELECT * FROM jos_zoo_category ORDER BY parent ASC");
        while ($c = $cats->fetch_assoc()) {
            $term = get_term_by('slug', $c['alias'], 'category');
            if ($term) {
                $cat_map[$c['id']] = $term->term_id;
            } else {
                // Create category if it doesn't exist
                $parent_wp = 0;
                if ($c['parent'] > 0 && isset($cat_map[$c['parent']])) {
                    $parent_wp = $cat_map[$c['parent']];
                }
                $new_term = wp_insert_term($c['name'], 'category', [
                    'slug'   => $c['alias'],
                    'parent' => $parent_wp,
                ]);
                if (!is_wp_error($new_term)) {
                    $cat_map[$c['id']] = $new_term['term_id'];
                }
            }
        }
        update_option('joomla_zoo_category_map', $cat_map);
    }

    // Get ALL missing items (any state, any type except author)
    $all_joomla = $j->query("SELECT * FROM jos_zoo_item WHERE type IN('article','page') ORDER BY id ASC");
    $missing_items = [];
    while ($row = $all_joomla->fetch_assoc()) {
        if (!isset($imported[$row['id']])) $missing_items[] = $row;
    }
    $total = count($missing_items);

    // Count by state for display
    $count_pub = 0; $count_unpub = 0;
    foreach ($missing_items as $mi) { if ($mi['state'] == 1) $count_pub++; else $count_unpub++; }

    echo "<div class='card'><h2>Importando $total faltantes ($count_pub pub + $count_unpub no-pub) desde $offset</h2><div class='log'>";

    $created = 0; $errors = 0; $created_pub = 0; $created_draft = 0;
    $slice = array_slice($missing_items, $offset, $batch);

    foreach ($slice as $row) {
        $content = extract_content($row['elements']);

        // Fix images
        $img_r = fix_joomla_image_urls($content);
        $content = $img_r['html'];

        // Fix base64
        $b64_r = fix_base64_in_string($content, 0);
        $content = $b64_r['html'];

        $wp_author = 1;
        if ($row['created_by'] > 0) {
            $u = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $wpdb->usermeta WHERE meta_key='_joomla_user_id' AND meta_value=%s LIMIT 1", $row['created_by']));
            if ($u) $wp_author = $u;
        }

        $wp_status = joomla_state_to_wp($row['state']);

        $wp_id = wp_insert_post([
            'post_title'        => $row['name'],
            'post_name'         => $row['alias'],
            'post_content'      => $content,
            'post_status'       => $wp_status,
            'post_date'         => $row['created'],
            'post_date_gmt'     => get_gmt_from_date($row['created']),
            'post_modified'     => $row['modified'],
            'post_modified_gmt' => get_gmt_from_date($row['modified']),
            'post_type'         => 'post',
            'post_author'       => $wp_author,
        ], true);

        if (is_wp_error($wp_id)) {
            lm("Error: {$row['name']} - " . $wp_id->get_error_message(), 'error');
            $errors++;
            continue;
        }

        update_post_meta($wp_id, '_joomla_zoo_id', $row['id']);

        // Update base64 attachment parent if created with post_id=0
        if ($b64_r['fixed'] > 0) {
            // Re-save to fix any attachment references
        }

        // Categories
        $cq = $j->query("SELECT category_id FROM jos_zoo_category_item WHERE item_id=" . intval($row['id']));
        $cats = [];
        if ($cq) { while ($cr = $cq->fetch_assoc()) { if (isset($cat_map[$cr['category_id']])) $cats[] = intval($cat_map[$cr['category_id']]); } }
        if (!empty($cats)) wp_set_post_categories($wp_id, $cats);

        // Tags
        $tq = $j->query("SELECT name FROM jos_zoo_tag WHERE item_id=" . intval($row['id']));
        $tags = [];
        if ($tq) { while ($tr = $tq->fetch_assoc()) $tags[] = $tr['name']; }
        if (!empty($tags)) wp_set_post_tags($wp_id, $tags, true);

        $extras = [];
        if ($img_r['fixed'] > 0) $extras[] = "{$img_r['fixed']}imgs";
        if ($b64_r['fixed'] > 0) $extras[] = "{$b64_r['fixed']}b64";
        $extra_txt = !empty($extras) ? ' (' . implode(', ', $extras) . ')' : '';

        $state_label = $wp_status === 'publish' ? 'PUB' : strtoupper($wp_status);
        lm("Creado [$state_label]: {$row['name']} (Zoo:{$row['id']} → WP:$wp_id)$extra_txt", 'success');
        $created++;
        if ($wp_status === 'publish') $created_pub++; else $created_draft++;
    }

    echo "</div></div>";

    echo "<div class='stat-grid'>";
    echo "<div class='stat-box ok'><div class='number'>$created</div><div class='label'>Creados (este lote)</div></div>";
    echo "<div class='stat-box ok'><div class='number'>$created_pub</div><div class='label'>Como publicados</div></div>";
    echo "<div class='stat-box warn'><div class='number'>$created_draft</div><div class='label'>Como borrador</div></div>";
    echo "<div class='stat-box'><div class='number'>$errors</div><div class='label'>Errores</div></div>";
    echo "</div>";

    $processed = $offset + count($slice);
    $pct = $total > 0 ? min(100, round(($processed / $total) * 100)) : 100;
    echo "<div class='card'><div class='progress'><div class='fill' style='width:{$pct}%'>$pct%</div></div>";

    if (count($slice) >= $batch && $processed < $total) {
        $next = $offset + $batch;
        $url = "?action=import_missing&offset=$next&batch=$batch";
        echo "<script>setTimeout(function(){window.location.href='$url';},2000);</script>";
        echo "<a href='$url' class='btn btn-blue'>Siguiente</a> <a href='?action=audit' class='btn btn-gray'>Detener</a>";
    } else {
        echo "<p style='color:#7ee787;font-weight:bold'>Importación completada.</p>";
        echo "<a href='?action=audit' class='btn btn-blue'>Auditoría Final</a>";
    }
    echo "</div>";
    pf();
}

// ============================================================
// FIX ALL (secuencial)
// ============================================================
function do_fix_all($step, $offset, $batch) {
    global $wpdb;

    if (empty($step)) $step = 'dedup';

    switch ($step) {
        case 'dedup':
            $remaining = $wpdb->get_var("SELECT COUNT(*) FROM (SELECT meta_value FROM $wpdb->postmeta WHERE meta_key='_joomla_zoo_id' GROUP BY meta_value HAVING COUNT(*)>1) t");
            if ($remaining > 0 || $offset > 0) {
                // Override next-step redirect
                do_dedup($offset, $batch);
                // After last batch, redirect to next step
                echo "<script>
                    var links = document.querySelectorAll('a.btn-green');
                    links.forEach(function(a){ if(a.textContent.indexOf('Paso 2')>=0) a.href='?action=fix_all&step=recover&offset=0&batch=$batch'; });
                    var scripts = document.querySelectorAll('script');
                </script>";
                return;
            }
            $step = 'recover';
            // fall through

        case 'recover':
            $remaining = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id' WHERE p.post_type='post' AND p.post_status NOT IN('trash','inherit') AND LENGTH(p.post_content)<50");
            if ($remaining > 0 || $offset > 0) {
                do_recover($offset, $batch);
                return;
            }
            $step = 'fix_images';
            $offset = 0;
            // fall through

        case 'fix_images':
            $remaining = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status NOT IN('trash','inherit') AND post_content LIKE '%src=\"images/%'");
            if ($remaining > 0) {
                do_fix_images();
                echo "<script>setTimeout(function(){window.location.href='?action=fix_all&step=fix_base64&offset=0&batch=$batch';},3000);</script>";
                return;
            }
            $step = 'fix_base64';
            $offset = 0;
            // fall through

        case 'fix_base64':
            // Check if there are still base64 to process
            $total_b64 = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id' WHERE p.post_type='post' AND p.post_status NOT IN('trash','inherit')");
            $processed_b64 = $offset + $batch;
            if ($offset < $total_b64) {
                do_fix_base64($offset, $batch);
                return;
            }
            $step = 'import';
            $offset = 0;
            // fall through

        case 'import':
            do_import_missing($offset, $batch);
            return;
    }
}

// ============================================================
// DIAGNOSE EMPTY POSTS
// ============================================================
function do_diagnose_empty() {
    global $wpdb;
    page_header('Diagnóstico: Posts Vacíos');
    $j = jdb();

    $known_uuid = '2e3c9e69-1f9e-4647-8d13-4e88094d2790';

    $empty_posts = $wpdb->get_results("
        SELECT p.ID, p.post_title, p.post_status, LENGTH(p.post_content) as clen, pm.meta_value as zoo_id
        FROM $wpdb->posts p
        JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id'
        WHERE p.post_type='post' AND p.post_status NOT IN('trash','inherit') AND LENGTH(p.post_content)<50
        ORDER BY p.ID ASC
    ");

    echo "<div class='card'><h2>Posts vacíos en WordPress: " . count($empty_posts) . "</h2>";

    $no_joomla = 0; $is_author = 0; $joomla_empty_too = 0; $joomla_has_content = 0;
    $uuid_analysis = []; $sample_elements = [];

    foreach ($empty_posts as $ep) {
        $zoo_id = intval($ep->zoo_id);
        $jr = $j->query("SELECT id, name, type, state, elements FROM jos_zoo_item WHERE id=$zoo_id");
        if (!$jr || $jr->num_rows == 0) { $no_joomla++; continue; }
        $jrow = $jr->fetch_assoc();
        if ($jrow['type'] === 'author') { $is_author++; continue; }

        $elements = json_decode($jrow['elements'], true);
        if (!$elements || !is_array($elements)) { $joomla_empty_too++; continue; }

        $has_known_uuid = isset($elements[$known_uuid]);
        $all_values = [];
        $all_keys = array_keys($elements);

        array_walk_recursive($elements, function($v, $k) use (&$all_values) {
            if ($k === 'value' && is_string($v) && strlen($v) > 10) {
                $all_values[] = ['length' => strlen($v), 'has_html' => ($v !== strip_tags($v)),
                    'preview' => mb_substr(strip_tags($v), 0, 80)];
            }
        });

        foreach ($all_keys as $key) {
            if (!isset($uuid_analysis[$key])) $uuid_analysis[$key] = 0;
            $uuid_analysis[$key]++;
        }

        $has_useful = false;
        foreach ($all_values as $av) { if ($av['length'] > 50 || $av['has_html']) { $has_useful = true; break; } }

        if ($has_useful) {
            $joomla_has_content++;
            if (count($sample_elements) < 8) {
                $sample_elements[] = [
                    'wp_id' => $ep->ID, 'zoo_id' => $zoo_id, 'title' => $jrow['name'],
                    'type' => $jrow['type'], 'state' => $jrow['state'],
                    'has_known_uuid' => $has_known_uuid, 'element_keys' => $all_keys,
                    'values' => $all_values,
                    'raw_preview' => mb_substr($jrow['elements'], 0, 2000),
                ];
            }
        } else {
            $joomla_empty_too++;
        }
    }

    echo "<div class='stat-grid'>";
    echo "<div class='stat-box'><div class='number'>$no_joomla</div><div class='label'>No existe en Joomla</div></div>";
    echo "<div class='stat-box'><div class='number'>$is_author</div><div class='label'>Son autores</div></div>";
    echo "<div class='stat-box warn'><div class='number'>$joomla_empty_too</div><div class='label'>Joomla también vacío</div></div>";
    echo "<div class='stat-box'><div class='number" . ($joomla_has_content > 0 ? "' style='color:#f85149'" : " ok'") . ">$joomla_has_content</div><div class='label'>Joomla SÍ tiene contenido</div></div>";
    echo "</div></div>";

    // UUID frequency
    echo "<div class='card'><h2>UUIDs en los posts vacíos (frecuencia)</h2><div class='log'>";
    arsort($uuid_analysis);
    foreach ($uuid_analysis as $uuid => $count) {
        $marker = ($uuid === $known_uuid) ? ' <span class="ok">← UUID CONOCIDO</span>' : '';
        lm("  $uuid: $count veces$marker", 'info');
    }
    echo "</div></div>";

    // Samples
    if (!empty($sample_elements)) {
        echo "<div class='card'><h2>Muestras: Joomla tiene contenido pero WP está vacío ($joomla_has_content)</h2>";
        foreach ($sample_elements as $s) {
            echo "<div style='border:1px solid #30363d;padding:10px;margin:8px 0;border-radius:6px'>";
            echo "<p class='info'><strong>WP:{$s['wp_id']} | Zoo:{$s['zoo_id']} | {$s['title']}</strong></p>";
            echo "<p>Tipo: {$s['type']} | Estado: {$s['state']} | UUID conocido: " .
                ($s['has_known_uuid'] ? '<span class="ok">SÍ</span>' : '<span class="error">NO</span>') . "</p>";
            echo "<p>UUIDs: <small class='warn'>" . implode(', ', array_map(function($k) use ($known_uuid) {
                return ($k === $known_uuid) ? "<strong style='color:#7ee787'>$k</strong>" : substr($k, 0, 20) . '...';
            }, $s['element_keys'])) . "</small></p>";
            echo "<p>Valores:</p><pre style='font-size:12px'>";
            foreach ($s['values'] as $v) {
                echo "  len={$v['length']} html=" . ($v['has_html'] ? 'SÍ' : 'no') . " | {$v['preview']}\n";
            }
            echo "</pre>";
            echo "<details><summary style='color:#58a6ff;cursor:pointer'>JSON raw (2000 chars)</summary><pre style='font-size:11px'>" .
                htmlspecialchars($s['raw_preview']) . "</pre></details>";
            echo "</div>";
        }
        echo "</div>";
    }

    // Global UUID analysis
    echo "<div class='card'><h2>UUIDs más comunes en TODOS los artículos de Joomla (muestra 1000)</h2><div class='log'>";
    $global_uuids = [];
    $sample_r = $j->query("SELECT elements FROM jos_zoo_item WHERE type IN('article','page') AND LENGTH(elements)>10 LIMIT 1000");
    $sample_count = 0;
    while ($sr = $sample_r->fetch_assoc()) {
        $sample_count++;
        $el = json_decode($sr['elements'], true);
        if ($el && is_array($el)) {
            foreach (array_keys($el) as $k) {
                if (!isset($global_uuids[$k])) $global_uuids[$k] = 0;
                $global_uuids[$k]++;
            }
        }
    }
    arsort($global_uuids);
    $i = 0;
    foreach ($global_uuids as $uuid => $count) {
        $marker = ($uuid === $known_uuid) ? ' <span class="ok">← UUID CONTENIDO</span>' : '';
        $pct = round($count / $sample_count * 100, 1);
        lm("  $uuid: $count/$sample_count ($pct%)$marker", 'info');
        $i++; if ($i >= 30) break;
    }
    echo "</div></div>";

    echo "<div class='card'>";
    if ($joomla_has_content > 0) {
        echo "<p class='error'>Hay $joomla_has_content posts donde Joomla tiene contenido pero no se extrajo correctamente.</p>";
        echo "<p class='info'>Revisa los UUIDs arriba. Si hay un UUID diferente al conocido que contiene contenido, necesitamos agregarlo a extract_content().</p>";
    } else {
        echo "<p class='ok'>Todos los posts vacíos también están vacíos en Joomla. No hay contenido perdido.</p>";
    }
    echo "<a href='?action=audit' class='btn btn-blue'>Volver a Auditoría</a>";
    echo "<a href='?action=recover' class='btn btn-green'>Re-ejecutar Recover</a>";
    echo "</div>";
    pf();
}

// ============================================================
// ROUTER
// ============================================================
switch ($action) {
    case 'audit':          do_audit(); break;
    case 'dedup':          do_dedup($offset, $batch); break;
    case 'recover':        do_recover($offset, $batch); break;
    case 'fix_images':     do_fix_images(); break;
    case 'fix_base64':     do_fix_base64($offset, $batch); break;
    case 'import_missing': do_import_missing($offset, $batch); break;
    case 'fix_all':        do_fix_all($step, $offset, $batch); break;
    case 'diagnose_empty': do_diagnose_empty(); break;
    default:               do_audit();
}
