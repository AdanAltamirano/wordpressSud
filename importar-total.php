<?php
/**
 * IMPORTAR TOTAL - Joomla Zoo → WordPress
 * ========================================
 * Importa TODOS los artículos de Zoo sin excepción.
 * Maneja base64 grandes (>200KB) con parser de strings, NO regex.
 * Modifica DB si es necesario para contenido grande.
 *
 * Acciones:
 *   ?action=audit         → Inventario completo en grid de columnas
 *   ?action=sync          → Sincronizar TODO (crear/actualizar)
 *   ?action=fix_base64    → Re-procesar base64 en posts existentes
 *   ?action=verify        → Verificación 1-a-1 en grid
 */

// ============================================================
// CONFIG
// ============================================================
set_time_limit(600);
ini_set('memory_limit', '2048M');
ini_set('display_errors', 1);
error_reporting(E_ALL);

// CRITICAL: Increase PCRE limits for large base64 strings
ini_set('pcre.backtrack_limit', '50000000');  // 50M (default is 1M)
ini_set('pcre.recursion_limit', '50000000');

require_once(__DIR__ . '/wp-load.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/media.php');

define('J_HOST', '127.0.0.1'); define('J_PORT', 3306);
define('J_USER', 'root'); define('J_PASS', 'yue02'); define('J_DB', 'sudcalifornios');
define('UPLOADS_DIR', ABSPATH . 'wp-content/uploads');
define('UPLOADS_URL', site_url('/wp-content/uploads'));
define('JIMAGES_DIR', UPLOADS_DIR . '/joomla-images');
define('JOOMLA_SRC_IMAGES', 'C:/Sudcalifornios/images'); // Directorio images/ del sitio Joomla original

// Known content UUID
define('CONTENT_UUID', '2e3c9e69-1f9e-4647-8d13-4e88094d2790');

$action = isset($_GET['action']) ? $_GET['action'] : 'audit';
$offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
$batch  = isset($_GET['batch'])  ? intval($_GET['batch'])  : 30;

// ============================================================
// DB CONNECTION
// ============================================================
function jdb() {
    static $conn = null;
    if (!$conn) {
        $conn = new mysqli(J_HOST, J_USER, J_PASS, J_DB, J_PORT);
        if ($conn->connect_error) die("Error Joomla DB: " . $conn->connect_error);
        $conn->set_charset("utf8mb4");
        // Allow large packets for base64
        $conn->query("SET SESSION group_concat_max_len = 10000000");
    }
    return $conn;
}

// ============================================================
// FILE INDEX (joomla-images)
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
// EXTRACT CONTENT FROM ZOO ELEMENTS (busca en TODOS los UUIDs)
// ============================================================
function extract_zoo_content($elements_json) {
    $e = json_decode($elements_json, true);
    if (!$e || !is_array($e)) return '';

    $content = '';

    // PASO 1: UUID conocido de contenido
    if (isset($e[CONTENT_UUID]) && is_array($e[CONTENT_UUID])) {
        // Recolectar TODOS los slots, ordenados por key
        ksort($e[CONTENT_UUID]);
        foreach ($e[CONTENT_UUID] as $slot) {
            if (isset($slot['value']) && is_string($slot['value']) && strlen($slot['value']) > 5) {
                $content .= $slot['value'] . "\n";
            }
        }
    }

    // PASO 2: Si no encontró con UUID conocido, buscar en TODOS los UUIDs
    if (empty(trim(strip_tags($content)))) {
        $candidates = [];
        foreach ($e as $uuid => $items) {
            if (!is_array($items)) continue;
            foreach ($items as $key => $item) {
                if (!is_array($item)) continue;
                if (isset($item['value']) && is_string($item['value'])) {
                    $val = $item['value'];
                    $text_len = strlen(trim(strip_tags($val)));
                    $is_html = ($val !== strip_tags($val));
                    if (($is_html && $text_len > 15) || $text_len > 80) {
                        $candidates[] = ['uuid' => $uuid, 'key' => $key, 'value' => $val, 'len' => $text_len];
                    }
                }
            }
        }
        usort($candidates, function($a, $b) { return $b['len'] - $a['len']; });
        foreach ($candidates as $c) {
            $content .= $c['value'] . "\n";
        }
    }

    return trim($content);
}

// ============================================================
// SAVE BASE64 IMAGE TO FILE (sin regex, parser de strings)
// ============================================================
function save_base64_to_file($base64_data, $mime_type, $post_id = 0) {
    $ext_map = [
        'image/jpeg' => 'jpg', 'image/jpg' => 'jpg',
        'image/png' => 'png', 'image/gif' => 'gif',
        'image/webp' => 'webp', 'image/bmp' => 'bmp',
        'image/svg+xml' => 'svg',
    ];
    $ext = isset($ext_map[$mime_type]) ? $ext_map[$mime_type] : 'jpg';

    // Clean base64 (remove whitespace)
    $clean = preg_replace('/\s+/', '', $base64_data);
    $decoded = base64_decode($clean, true);
    if ($decoded === false || strlen($decoded) < 50) return false;

    $upload_dir = wp_upload_dir();
    $filename = 'zoo-img-' . $post_id . '-' . substr(md5($clean), 0, 10) . '.' . $ext;
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
// FIX BASE64 IMAGES - STRING PARSER (NO regex para datos grandes)
// ============================================================
function fix_base64_images($html, $post_id = 0) {
    $count = 0;
    $search_pos = 0;

    while (true) {
        // Find "data:image/" in the string
        $pos = strpos($html, 'data:image/', $search_pos);
        if ($pos === false) break;

        // Find the mime type (e.g., "png", "jpeg")
        $mime_start = $pos + 11; // after "data:image/"
        $semicol = strpos($html, ';base64,', $mime_start);
        if ($semicol === false || ($semicol - $mime_start) > 20) {
            $search_pos = $pos + 1;
            continue;
        }

        $sub_type = substr($html, $mime_start, $semicol - $mime_start);
        $mime_type = 'image/' . $sub_type;
        $b64_start = $semicol + 8; // after ";base64,"

        // Find the end of base64 data (next quote or < or whitespace)
        $b64_end = $b64_start;
        $len = strlen($html);
        while ($b64_end < $len) {
            $ch = $html[$b64_end];
            if ($ch === '"' || $ch === "'" || $ch === '<' || $ch === ' ' || $ch === ')') break;
            $b64_end++;
        }

        $b64_data = substr($html, $b64_start, $b64_end - $b64_start);

        if (strlen($b64_data) < 100) {
            $search_pos = $b64_end;
            continue;
        }

        // Save as file
        $file_url = save_base64_to_file($b64_data, $mime_type, $post_id);
        if ($file_url) {
            // Replace the entire data:image/...;base64,DATA with the file URL
            $old_str = substr($html, $pos, $b64_end - $pos);
            $html = substr($html, 0, $pos) . $file_url . substr($html, $b64_end);
            $count++;
            $search_pos = $pos + strlen($file_url);
        } else {
            $search_pos = $b64_end;
        }
    }

    return ['html' => $html, 'fixed' => $count];
}

// ============================================================
// FIX src="images/..." URLS
// ============================================================
function fix_image_urls($html, $post_id = 0) {
    $index = build_file_index();
    $count = 0;
    $not_found = [];
    $html = preg_replace_callback(
        '/src=["\'](?:\.\/)?images\/([^"\']+)["\']/i',
        function($m) use ($index, $post_id, &$count, &$not_found) {
            $rel  = urldecode($m[1]);
            $fname = strtolower(basename($rel));

            // 1) Check joomla-images index (already migrated files)
            if (isset($index[$fname])) {
                $count++;
                return 'src="' . UPLOADS_URL . '/' . $index[$fname] . '"';
            }

            // 2) Fallback: import directly from Joomla source images directory
            $result = import_joomla_src_image($rel, $post_id);
            if ($result) {
                $count++;
                return 'src="' . $result['url'] . '"';
            }

            $not_found[] = $rel;
            return $m[0];
        },
        $html
    );
    return ['html' => $html, 'fixed' => $count, 'not_found' => $not_found];
}

// ============================================================
// PROCESS CONTENT: extract + fix base64 + fix image urls
// ============================================================
function process_content($elements_json, $post_id = 0) {
    $content = extract_zoo_content($elements_json);

    // 1) Fix base64 FIRST (using string parser, handles 200KB+ images)
    $b64_result = fix_base64_images($content, $post_id);
    $content = $b64_result['html'];

    // 2) Fix image URLs (with post_id for Joomla source fallback)
    $img_result = fix_image_urls($content, $post_id);
    $content = $img_result['html'];

    return [
        'content'   => $content,
        'b64_fixed' => $b64_result['fixed'],
        'img_fixed' => $img_result['fixed'],
        'img_nf'    => $img_result['not_found'],
        'has_text'  => strlen(trim(strip_tags($content))) > 10,
    ];
}

// ============================================================
// JOOMLA ZOO AUTHOR → WP USER
// ============================================================
// UUID del elemento "Author" (relateditems) en artículos Zoo
define('JOOMLA_AUTHOR_UUID', 'fc5a6788-ffae-41d9-a812-3530331fef64');

/**
 * Build lookup map: zoo_author_item_id → wp_user_id
 * WP users have _joomla_author_id meta (set by fg-joomla-to-wordpress)
 */
function build_author_map() {
    global $wpdb;
    $rows = $wpdb->get_results("SELECT user_id, meta_value FROM $wpdb->usermeta WHERE meta_key='_joomla_author_id'");
    $map = [];
    foreach ($rows as $r) {
        $map[intval($r->meta_value)] = intval($r->user_id);
    }
    return $map;
}

/**
 * Extract Zoo author item ID from article elements JSON.
 * Format: {"fc5a6788-...": {"item": ["11"]}}
 * Returns int ID or null if not found.
 */
function get_zoo_author_id_from_elements($elements_json) {
    $els = json_decode($elements_json, true);
    if (!$els) return null;
    $data = $els[JOOMLA_AUTHOR_UUID] ?? null;
    if (!$data) return null;
    if (isset($data['item']) && is_array($data['item']) && !empty($data['item'])) {
        $id = intval($data['item'][0]);
        return $id > 0 ? $id : null;
    }
    return null;
}

// ============================================================
// JOOMLA STATE → WP STATUS
// ============================================================
function joomla_to_wp_status($state) {
    switch (intval($state)) {
        case 1:  return 'publish';
        case 0:  return 'draft';
        case 2:  return 'pending';
        case -1: return 'draft';
        case -2: return 'trash';
        default: return 'draft';
    }
}

/**
 * Joomla state + fecha → WP status correcto.
 * Si state=1 (publicado) pero la fecha es futura → 'future' (programado en WP).
 * WP publicará el post automáticamente cuando llegue esa fecha.
 */
function joomla_to_wp_status_with_date($state, $created) {
    $base = joomla_to_wp_status($state);
    if ($base === 'publish' && strtotime($created) > time()) {
        return 'future'; // WP schedules it; auto-publishes on that date
    }
    return $base;
}

// ============================================================
// HTML UI
// ============================================================
function page_header($title) {
    echo "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'><title>$title</title>
    <style>
        *{box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0d1117;color:#c9d1d9;padding:20px;margin:0}
        .container{max-width:1400px;margin:0 auto}
        .card{background:#161b22;border:1px solid #30363d;padding:20px;border-radius:8px;margin-bottom:16px}
        h1,h2{color:#58a6ff;margin-top:0}
        .log{background:#010409;color:#7ee787;padding:15px;border-radius:6px;max-height:500px;overflow-y:auto;font-family:Consolas,monospace;font-size:13px;line-height:1.6;white-space:pre-wrap}
        .log .error{color:#f85149}.log .warn{color:#d29922}.log .info{color:#58a6ff}.log .success{color:#7ee787}
        .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:16px}
        .stat-box{background:#21262d;padding:12px;border-radius:6px;text-align:center;border:1px solid #30363d}
        .stat-box .number{font-size:1.8em;font-weight:bold;color:#f85149}.stat-box .label{color:#8b949e;font-size:.85em;margin-top:4px}
        .stat-box.ok .number{color:#7ee787}.stat-box.warn .number{color:#d29922}
        .btn{display:inline-block;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:600;margin:4px;font-size:14px;border:1px solid #30363d;cursor:pointer}
        .btn-blue{background:#1f6feb;color:#fff;border-color:#1f6feb}.btn-green{background:#238636;color:#fff;border-color:#238636}
        .btn-yellow{background:#9e6a03;color:#fff;border-color:#9e6a03}.btn-red{background:#da3633;color:#fff;border-color:#da3633}
        .btn-gray{background:#21262d;color:#58a6ff}
        .nav{margin-bottom:16px;padding:12px;background:#161b22;border-radius:8px;border:1px solid #30363d;display:flex;flex-wrap:wrap;align-items:center;gap:6px}
        .progress{background:#21262d;height:24px;border-radius:12px;overflow:hidden;margin:12px 0}
        .progress .fill{background:linear-gradient(90deg,#1f6feb,#238636);height:100%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:bold;transition:width 0.3s}

        /* ============ GRID DE ARTÍCULOS EN COLUMNAS ============ */
        .article-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:12px 0}
        .article-grid.cols-4{grid-template-columns:repeat(4,1fr)}
        .article-grid.cols-2{grid-template-columns:repeat(2,1fr)}
        .article-cell{background:#21262d;border:1px solid #30363d;border-radius:6px;padding:8px 10px;font-size:12px;overflow:hidden}
        .article-cell .cell-id{color:#58a6ff;font-weight:bold;font-size:13px}
        .article-cell .cell-title{color:#c9d1d9;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}
        .article-cell .cell-meta{color:#8b949e;font-size:11px;margin-top:3px}
        .article-cell .cell-meta .tag{display:inline-block;padding:1px 5px;border-radius:3px;font-size:10px;margin-right:3px}
        .tag-pub{background:#238636;color:#fff}.tag-draft{background:#9e6a03;color:#fff}
        .tag-empty{background:#da3633;color:#fff}.tag-b64{background:#8957e5;color:#fff}
        .tag-ok{background:#238636;color:#fff}.tag-img{background:#1f6feb;color:#fff}
        .article-cell.status-ok{border-left:3px solid #238636}
        .article-cell.status-warn{border-left:3px solid #d29922}
        .article-cell.status-error{border-left:3px solid #f85149}
        .article-cell.status-new{border-left:3px solid #8957e5}

        table.data-table{width:100%;border-collapse:collapse;font-size:13px}
        .data-table th{background:#21262d;color:#8b949e;padding:8px;text-align:left;border-bottom:2px solid #30363d}
        .data-table td{padding:6px 8px;border-bottom:1px solid #21262d;vertical-align:top}
        .data-table tr:hover{background:#1c2128}

        @media(max-width:900px){.article-grid{grid-template-columns:repeat(2,1fr)}}
        @media(max-width:600px){.article-grid{grid-template-columns:1fr}}
    </style></head><body><div class='container'>";
    echo "<div class='nav'><strong style='color:#58a6ff;font-size:15px'>Importar Total&nbsp;&nbsp;</strong>";
    echo "<a href='?action=audit' class='btn btn-blue'>Auditoría</a>";
    echo "<a href='?action=sync' class='btn btn-green'>Sincronizar TODO</a>";
    echo "<a href='?action=fix_base64' class='btn btn-yellow'>Fix Base64</a>";
    echo "<a href='?action=verify' class='btn btn-gray'>Verificar 1:1</a>";
    echo "<a href='?action=diagnose' class='btn btn-red'>Diagnóstico</a>";
    echo "<a href='?action=fix_status' class='btn btn-yellow'>Fix Estado</a>";
    echo "<a href='?action=fix_authors' class='btn btn-yellow' style='margin-left:8px'>Fix Autores</a>";
    echo "<a href='?action=reset_posts' class='btn btn-red' style='margin-left:12px;border:2px solid #f85149'>⚠ Reset Posts</a>";
    echo "</div>";
}
function pf() { echo "</div></body></html>"; }
function lm($m, $c='info') { echo "<div class='$c'>$m</div>\n"; @ob_flush(); @flush(); }

// ============================================================
// AUDIT — Inventario en grid de columnas
// ============================================================
function do_audit() {
    global $wpdb;
    page_header('Importar Total - Auditoría');
    $j = jdb();

    // JOOMLA COUNTS
    $j_total = $j->query("SELECT COUNT(*) c FROM jos_zoo_item WHERE type IN('article','page')")->fetch_assoc()['c'];
    $j_pub   = $j->query("SELECT COUNT(*) c FROM jos_zoo_item WHERE type IN('article','page') AND state=1")->fetch_assoc()['c'];
    $j_unpub = $j->query("SELECT COUNT(*) c FROM jos_zoo_item WHERE type IN('article','page') AND state=0")->fetch_assoc()['c'];
    $j_other = $j->query("SELECT COUNT(*) c FROM jos_zoo_item WHERE type IN('article','page') AND state NOT IN(0,1)")->fetch_assoc()['c'];
    $j_b64   = $j->query("SELECT COUNT(*) c FROM jos_zoo_item WHERE type IN('article','page') AND elements LIKE '%data:image%'")->fetch_assoc()['c'];
    $j_img   = $j->query("SELECT COUNT(*) c FROM jos_zoo_item WHERE type IN('article','page') AND elements LIKE '%\"images/%'")->fetch_assoc()['c'];
    $j_auth  = $j->query("SELECT COUNT(*) c FROM jos_zoo_item WHERE type='author'")->fetch_assoc()['c'];

    // WP COUNTS (non-trash only)
    $wp_total   = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status NOT IN('trash','inherit')");
    $wp_pub     = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status='publish'");
    $wp_draft   = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status='draft'");
    $wp_trash   = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status='trash'");
    $wp_tracked = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->postmeta pm JOIN $wpdb->posts p ON p.ID=pm.post_id WHERE pm.meta_key='_joomla_zoo_id' AND p.post_status NOT IN('trash','inherit')");
    $wp_empty   = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id' WHERE p.post_type='post' AND p.post_status NOT IN('trash','inherit') AND LENGTH(p.post_content)<50");
    $wp_b64     = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status NOT IN('trash','inherit') AND post_content LIKE '%data:image%'");
    $wp_brkimg  = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status NOT IN('trash','inherit') AND post_content LIKE '%src=\"images/%'");

    // COMPARISON
    $wp_zoo_ids = $wpdb->get_col("SELECT pm.meta_value FROM $wpdb->postmeta pm JOIN $wpdb->posts p ON p.ID=pm.post_id WHERE pm.meta_key='_joomla_zoo_id' AND p.post_status NOT IN('trash','inherit')");
    $wp_zoo_map = array_flip($wp_zoo_ids);

    $missing = []; $present = [];
    $jr = $j->query("SELECT id, name, state FROM jos_zoo_item WHERE type IN('article','page') ORDER BY id DESC");
    while ($row = $jr->fetch_assoc()) {
        if (!isset($wp_zoo_map[$row['id']])) $missing[] = $row;
        else $present[] = $row;
    }

    // STAT BOXES
    echo "<div class='stat-grid'>";
    echo "<div class='stat-box'><div class='number'>$j_total</div><div class='label'>Total Joomla</div></div>";
    echo "<div class='stat-box ok'><div class='number'>$j_pub</div><div class='label'>Publicados J</div></div>";
    echo "<div class='stat-box warn'><div class='number'>$j_unpub</div><div class='label'>No publicados J</div></div>";
    echo "<div class='stat-box ok'><div class='number'>$wp_total</div><div class='label'>Total WP (activos)</div></div>";
    echo "<div class='stat-box ok'><div class='number'>$wp_tracked</div><div class='label'>Con zoo_id WP</div></div>";
    echo "<div class='stat-box" . (count($missing) > 0 ? '' : ' ok') . "'><div class='number'>" . count($missing) . "</div><div class='label'>Faltan importar</div></div>";
    echo "<div class='stat-box" . ($wp_empty > 0 ? ' warn' : ' ok') . "'><div class='number'>$wp_empty</div><div class='label'>Posts vacíos</div></div>";
    echo "<div class='stat-box" . ($wp_b64 > 0 ? '' : ' ok') . "'><div class='number'>$j_b64 / $wp_b64</div><div class='label'>Base64 J / WP</div></div>";
    echo "<div class='stat-box" . ($wp_brkimg > 0 ? '' : ' ok') . "'><div class='number'>$wp_brkimg</div><div class='label'>Imgs rotas WP</div></div>";
    echo "</div>";

    // MISSING ARTICLES — GRID DE COLUMNAS
    if (count($missing) > 0) {
        $miss_pub = 0; $miss_unpub = 0;
        foreach ($missing as $m) { if ($m['state'] == 1) $miss_pub++; else $miss_unpub++; }

        echo "<div class='card'><h2>Artículos que FALTAN en WordPress (" . count($missing) . " total: $miss_pub pub + $miss_unpub no-pub)</h2>";
        echo "<div class='article-grid'>";
        $shown = 0;
        foreach ($missing as $m) {
            $st = $m['state'] == 1 ? 'pub' : 'draft';
            $cls = $m['state'] == 1 ? 'status-error' : 'status-warn';
            echo "<div class='article-cell $cls'>";
            echo "<div class='cell-id'>Zoo:{$m['id']}</div>";
            echo "<div class='cell-title' title='" . htmlspecialchars($m['name']) . "'>" . htmlspecialchars(mb_substr($m['name'], 0, 60)) . "</div>";
            echo "<div class='cell-meta'><span class='tag tag-$st'>" . ($m['state'] == 1 ? 'PUB' : 'NOPUB') . "</span></div>";
            echo "</div>";
            $shown++;
            if ($shown >= 150) {
                echo "<div class='article-cell' style='text-align:center;color:#8b949e;grid-column:1/-1'>... y " . (count($missing) - 150) . " más</div>";
                break;
            }
        }
        echo "</div></div>";
    }

    // EMPTY POSTS — GRID
    if ($wp_empty > 0) {
        $empties = $wpdb->get_results("SELECT p.ID, p.post_title, pm.meta_value as zoo_id FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id' WHERE p.post_type='post' AND p.post_status NOT IN('trash','inherit') AND LENGTH(p.post_content)<50 ORDER BY p.ID ASC LIMIT 150");

        echo "<div class='card'><h2>Posts vacíos en WordPress ($wp_empty)</h2>";
        echo "<div class='article-grid'>";
        foreach ($empties as $ep) {
            echo "<div class='article-cell status-error'>";
            echo "<div class='cell-id'>WP:{$ep->ID} → Zoo:{$ep->zoo_id}</div>";
            echo "<div class='cell-title' title='" . htmlspecialchars($ep->post_title) . "'>" . htmlspecialchars(mb_substr($ep->post_title, 0, 55)) . "</div>";
            echo "<div class='cell-meta'><span class='tag tag-empty'>VACÍO</span></div>";
            echo "</div>";
        }
        if ($wp_empty > 150) echo "<div class='article-cell' style='text-align:center;color:#8b949e;grid-column:1/-1'>... y " . ($wp_empty - 150) . " más</div>";
        echo "</div></div>";
    }

    // ACTION BUTTONS
    echo "<div class='card'><h2>Acciones</h2>";
    echo "<p><a href='?action=sync' class='btn btn-green' style='font-size:16px;padding:14px 28px'>SINCRONIZAR TODO (" . count($missing) . " nuevos + $wp_empty vacíos + $j_b64 base64)</a></p>";
    echo "<p style='color:#8b949e;margin-top:8px'>Este botón importa todos los faltantes, recupera contenido vacío, y convierte base64 a archivos. Todo en un solo paso.</p>";
    echo "</div>";

    pf();
}

// ============================================================
// IMPORT IMAGE FROM JOOMLA SOURCE (images/... → WP uploads)
// ============================================================
function import_joomla_src_image($rel_img_path, $post_id = 0) {
    // rel_img_path is like "1/isipd/ISIPD2/v/foto.jpg"
    $rel_img_path = ltrim(str_replace('\\', '/', $rel_img_path), '/');
    $src_path = str_replace('/', DIRECTORY_SEPARATOR, JOOMLA_SRC_IMAGES . '/' . $rel_img_path);

    if (!file_exists($src_path)) return false;

    $upload_dir = wp_upload_dir();
    $basename   = basename($src_path);
    $filename   = wp_unique_filename($upload_dir['path'], $basename);
    $dest_path  = $upload_dir['path'] . '/' . $filename;

    if (!copy($src_path, $dest_path)) return false;

    $filetype  = wp_check_filetype($filename, null);
    $attach_id = wp_insert_attachment([
        'guid'           => $upload_dir['url'] . '/' . $filename,
        'post_mime_type' => $filetype['type'],
        'post_title'     => pathinfo($filename, PATHINFO_FILENAME),
        'post_content'   => '',
        'post_status'    => 'inherit'
    ], $dest_path, $post_id);

    if (is_wp_error($attach_id)) {
        @unlink($dest_path);
        return false;
    }

    wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $dest_path));
    return ['url' => $upload_dir['url'] . '/' . $filename, 'id' => $attach_id];
}

// ============================================================
// HELPER: FEATURED IMAGE
// ============================================================
function ensure_local_attachment($file_url, $post_id) {
    global $wpdb;
    $upload_dir = wp_upload_dir();
    $base_url = $upload_dir['baseurl'];

    // Check if URL is local (starts with uploads URL)
    if (strpos($file_url, $base_url) === false) return false;

    // Get path relative to uploads dir
    $rel_path = str_replace($base_url . '/', '', $file_url);
    $abs_path = $upload_dir['basedir'] . '/' . $rel_path;

    // Fix Windows separators if mixed
    $abs_path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $abs_path);

    if (!file_exists($abs_path)) return false;

    // Check if attachment exists by GUID
    // NOTE: GUID matching is tricky if domain changed, but here we assume migration context
    $attach_id = $wpdb->get_var($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE guid=%s", $file_url));
    if ($attach_id) return $attach_id;

    // Create attachment
    $filetype = wp_check_filetype(basename($abs_path), null);
    $attach_id = wp_insert_attachment([
        'guid'           => $file_url,
        'post_mime_type' => $filetype['type'],
        'post_title'     => preg_replace('/\.[^.]+$/', '', basename($abs_path)),
        'post_content'   => '',
        'post_status'    => 'inherit'
    ], $abs_path, $post_id);

    if (!is_wp_error($attach_id)) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $abs_path));
        return $attach_id;
    }
    return false;
}

function set_featured_image_from_content($content, $post_id) {
    // If already has thumbnail, skip
    if (get_post_thumbnail_id($post_id)) return;

    // Find first image
    if (!preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $matches)) return;

    $src = $matches[1];
    $attach_id = false;

    // Case 1: fully resolved local WP URL → look up or register attachment
    $attach_id = ensure_local_attachment($src, $post_id);

    // Case 2: still an unresolved Joomla images/... path → import from Joomla source
    if (!$attach_id && preg_match('/^(?:\.\/)?images\/(.+)/i', $src, $rel_m)) {
        $result = import_joomla_src_image($rel_m[1], $post_id);
        if ($result) $attach_id = $result['id'];
    }

    if ($attach_id) {
        set_post_thumbnail($post_id, $attach_id);
    }
}

// ============================================================
// SYNC — Importar/actualizar TODO
// ============================================================
function do_sync($offset, $batch) {
    global $wpdb;
    page_header('Sincronizando TODO');
    $j = jdb();

    // Build maps
    $wp_zoo_map = [];
    $rows = $wpdb->get_results("SELECT pm.post_id, pm.meta_value as zoo_id FROM $wpdb->postmeta pm JOIN $wpdb->posts p ON p.ID=pm.post_id WHERE pm.meta_key='_joomla_zoo_id' AND p.post_status NOT IN('trash','inherit')");
    foreach ($rows as $r) $wp_zoo_map[$r->zoo_id] = $r->post_id;

    $cat_map = get_option('joomla_zoo_category_map', []);
    if (empty($cat_map)) {
        $cats = $j->query("SELECT * FROM jos_zoo_category ORDER BY parent ASC");
        if ($cats) {
            while ($c = $cats->fetch_assoc()) {
                $term = get_term_by('slug', $c['alias'], 'category');
                if ($term) {
                    $cat_map[$c['id']] = $term->term_id;
                } else {
                    $parent_wp = 0;
                    if ($c['parent'] > 0 && isset($cat_map[$c['parent']])) $parent_wp = $cat_map[$c['parent']];
                    $new_term = wp_insert_term($c['name'], 'category', ['slug' => $c['alias'], 'parent' => $parent_wp]);
                    if (!is_wp_error($new_term)) $cat_map[$c['id']] = $new_term['term_id'];
                }
            }
            update_option('joomla_zoo_category_map', $cat_map);
        }
    }

    // Build author map once: zoo_author_item_id → wp_user_id
    $author_map = build_author_map();

    // Get ALL Joomla articles+pages (any state, excluding authors)
    $all_items = [];
    $jr = $j->query("SELECT * FROM jos_zoo_item WHERE type IN('article','page') ORDER BY id ASC");
    while ($row = $jr->fetch_assoc()) $all_items[] = $row;
    $total = count($all_items);

    $slice = array_slice($all_items, $offset, $batch);

    echo "<div class='card'><h2>Procesando lote $offset - " . ($offset + count($slice)) . " de $total</h2>";
    echo "<div class='article-grid'>"; // ← GRID de 3 columnas

    $created = 0; $updated = 0; $skipped = 0; $b64_total = 0; $img_total = 0; $errors = 0;

    foreach ($slice as $row) {
        $zoo_id = $row['id'];
        $exists_wp = isset($wp_zoo_map[$zoo_id]) ? $wp_zoo_map[$zoo_id] : false;

        // Process content (extract + fix base64 + fix images)
        $result = process_content($row['elements'], $exists_wp ?: 0);
        $content = $result['content'];
        $b64_total += $result['b64_fixed'];
        $img_total += $result['img_fixed'];

        // WP status takes date into account: state=1 + future date → 'future' (scheduled in WP)
        $wp_status = joomla_to_wp_status_with_date($row['state'], $row['created']);

        // Cell tags
        $cell_tags = '';
        if ($result['b64_fixed'] > 0) $cell_tags .= " <span class='tag tag-b64'>{$result['b64_fixed']}b64</span>";
        if ($result['img_fixed'] > 0) $cell_tags .= " <span class='tag tag-img'>{$result['img_fixed']}img</span>";

        if ($exists_wp) {
            // Post exists — check ALL possible fixes needed
            $wp_post = $wpdb->get_row($wpdb->prepare("SELECT post_content, post_date, post_status FROM $wpdb->posts WHERE ID=%d", $exists_wp));
            $wp_content = $wp_post->post_content;
            $current_len = strlen($wp_content);
            $needs_update = false;
            $update_data = [];
            $actions = [];

            // DATE SYNC — update WP date if Joomla date changed
            $wp_date_ts     = strtotime($wp_post->post_date);
            $joomla_date_ts = strtotime($row['created']);
            if (abs($wp_date_ts - $joomla_date_ts) > 60) {
                $update_data['post_date']         = $row['created'];
                $update_data['post_date_gmt']     = get_gmt_from_date($row['created']);
                $update_data['post_modified']     = $row['modified'];
                $update_data['post_modified_gmt'] = get_gmt_from_date($row['modified']);
                // Status must also reflect the new date
                $update_data['post_status'] = $wp_status;
                $needs_update = true;
                $actions[] = 'DATE-FIX';
            }

            if ($current_len < 50 && $result['has_text']) {
                $wp_content = $content;
                $needs_update = true;
                $actions[] = 'RECUPERADO';
            } else {
                if (strpos($wp_content, 'data:image') !== false) {
                    $b64_wp = fix_base64_images($wp_content, $exists_wp);
                    if ($b64_wp['fixed'] > 0) {
                        $wp_content = $b64_wp['html'];
                        $b64_total += $b64_wp['fixed'];
                        $needs_update = true;
                        $actions[] = "{$b64_wp['fixed']}b64";
                    }
                }
                if (strpos($wp_content, 'data:image') === false && strpos($row['elements'], 'data:image') !== false) {
                    if ($result['b64_fixed'] > 0 && !empty($content)) {
                        $wp_content = $content;
                        $needs_update = true;
                        $actions[] = "J→{$result['b64_fixed']}b64";
                    }
                }
                if (strpos($wp_content, 'src="images/') !== false || strpos($wp_content, "src='images/") !== false) {
                    $img_fix = fix_image_urls($wp_content, $exists_wp);
                    if ($img_fix['fixed'] > 0) {
                        $wp_content = $img_fix['html'];
                        $img_total += $img_fix['fixed'];
                        $needs_update = true;
                        $actions[] = "{$img_fix['fixed']}img";
                    }
                }
            }

            // STATUS SYNC — reflect Joomla state in WP.
            // 'future' and 'publish' are both valid for Joomla state=1: if WP already
            // has 'future' (correctly scheduled) and Joomla wants 'publish', leave it —
            // WP will auto-publish it on the scheduled date.
            $current_wp_status = $wp_post->post_status;
            $status_ok = ($current_wp_status === $wp_status)
                      || ($wp_status === 'publish' && $current_wp_status === 'future');
            if (!$status_ok) {
                $update_data['post_status'] = $wp_status;
                $needs_update = true;
                $actions[] = 'STS→' . ($wp_status === 'publish' ? 'PUB' : ($wp_status === 'future' ? 'FUT' : ($wp_status === 'draft' ? 'DFT' : strtoupper($wp_status))));
            }

            // AUTHOR SYNC — update post_author if Zoo element found and differs
            $zoo_auth_id = get_zoo_author_id_from_elements($row['elements']);
            if ($zoo_auth_id && isset($author_map[$zoo_auth_id])) {
                $correct_author = $author_map[$zoo_auth_id];
                if (intval($wp_post->post_author) !== $correct_author) {
                    $update_data['post_author'] = $correct_author;
                    $needs_update = true;
                    $actions[] = 'AUTH';
                }
            }

            if ($needs_update) {
                $update_data['post_content'] = $wp_content;
                $wpdb->update($wpdb->posts, $update_data, ['ID' => $exists_wp]);
                clean_post_cache($exists_wp);
                $act_str = implode(' ', $actions);
                // Grid cell — ACTUALIZADO
                echo "<div class='article-cell status-ok'>";
                echo "<div class='cell-id'>WP:{$exists_wp} ← Zoo:{$zoo_id}</div>";
                echo "<div class='cell-title' title='" . htmlspecialchars($row['name']) . "'>" . htmlspecialchars(mb_substr($row['name'], 0, 45)) . "</div>";
                echo "<div class='cell-meta'><span class='tag tag-ok'>$act_str</span>$cell_tags</div>";
                echo "</div>";
                $updated++;
            } else {
                // Grid cell — SIN CAMBIOS (skip silencioso, no mostrar celda)
                $skipped++;
            }
            // Always try to set featured image
            set_featured_image_from_content($wp_content, $exists_wp);

        } else {
            // NEW POST — create
            // Author via Zoo "Author" relateditems element → _joomla_author_id meta
            $zoo_auth_id = get_zoo_author_id_from_elements($row['elements']);
            $wp_author = ($zoo_auth_id && isset($author_map[$zoo_auth_id])) ? $author_map[$zoo_auth_id] : 1;

            $wp_id = wp_insert_post([
                'post_title'        => $row['name'],
                'post_name'         => $row['alias'],
                'post_content'      => $content,
                'post_status'       => $wp_status, // 'future' if state=1 + future date
                'post_date'         => $row['created'],
                'post_date_gmt'     => get_gmt_from_date($row['created']),
                'post_modified'     => $row['modified'],
                'post_modified_gmt' => get_gmt_from_date($row['modified']),
                'post_type'         => 'post',
                'post_author'       => $wp_author,
            ], true);

            if (is_wp_error($wp_id)) {
                // Grid cell — ERROR
                echo "<div class='article-cell status-error'>";
                echo "<div class='cell-id'>Zoo:{$zoo_id} ERROR</div>";
                echo "<div class='cell-title'>" . htmlspecialchars(mb_substr($row['name'], 0, 45)) . "</div>";
                echo "<div class='cell-meta'><span class='tag tag-empty'>ERROR</span></div>";
                echo "</div>";
                $errors++;
                continue;
            }

            update_post_meta($wp_id, '_joomla_zoo_id', $zoo_id);
            set_featured_image_from_content($content, $wp_id);

            // Categories
            $cq = $j->query("SELECT category_id FROM jos_zoo_category_item WHERE item_id=" . intval($zoo_id));
            $cats = [];
            if ($cq) { while ($cr = $cq->fetch_assoc()) { if (isset($cat_map[$cr['category_id']])) $cats[] = intval($cat_map[$cr['category_id']]); } }
            if (!empty($cats)) wp_set_post_categories($wp_id, $cats);

            // Tags
            $tq = $j->query("SELECT name FROM jos_zoo_tag WHERE item_id=" . intval($zoo_id));
            $tags_arr = [];
            if ($tq) { while ($tr = $tq->fetch_assoc()) $tags_arr[] = $tr['name']; }
            if (!empty($tags_arr)) wp_set_post_tags($wp_id, $tags_arr, true);

            $state_label = $wp_status === 'future' ? 'PROG' : strtoupper($wp_status);
            $st_class = $wp_status === 'publish' ? 'tag-pub' : ($wp_status === 'future' ? 'tag-b64' : 'tag-draft');
            // Grid cell — CREADO
            echo "<div class='article-cell status-new'>";
            echo "<div class='cell-id'>WP:{$wp_id} ← Zoo:{$zoo_id}</div>";
            echo "<div class='cell-title' title='" . htmlspecialchars($row['name']) . "'>" . htmlspecialchars(mb_substr($row['name'], 0, 45)) . "</div>";
            echo "<div class='cell-meta'><span class='tag $st_class'>$state_label</span>$cell_tags</div>";
            echo "</div>";
            $created++;
        }
        @ob_flush(); @flush();
    }

    echo "</div></div>"; // close grid + card

    // Stats
    echo "<div class='stat-grid'>";
    echo "<div class='stat-box ok'><div class='number'>$created</div><div class='label'>Creados</div></div>";
    echo "<div class='stat-box ok'><div class='number'>$updated</div><div class='label'>Actualizados</div></div>";
    echo "<div class='stat-box'><div class='number'>$skipped</div><div class='label'>Sin cambios</div></div>";
    echo "<div class='stat-box" . ($errors > 0 ? '' : ' ok') . "'><div class='number'>$errors</div><div class='label'>Errores</div></div>";
    echo "<div class='stat-box ok'><div class='number'>$b64_total</div><div class='label'>Base64 → archivo</div></div>";
    echo "<div class='stat-box ok'><div class='number'>$img_total</div><div class='label'>Imgs reparadas</div></div>";
    echo "</div>";

    // Progress
    $processed = $offset + count($slice);
    $pct = $total > 0 ? min(100, round(($processed / $total) * 100)) : 100;
    echo "<div class='card'><div class='progress'><div class='fill' style='width:{$pct}%'>$pct% ($processed / $total)</div></div>";

    if (count($slice) >= $batch && $processed < $total) {
        $next = $offset + $batch;
        $url = "?action=sync&offset=$next&batch=$batch";
        echo "<script>setTimeout(function(){window.location.href='$url';},2000);</script>";
        echo "<a href='$url' class='btn btn-blue'>Siguiente lote</a> <a href='?action=audit' class='btn btn-gray'>Detener</a>";
    } else {
        echo "<p style='color:#7ee787;font-weight:bold;font-size:18px'>SINCRONIZACIÓN COMPLETADA</p>";
        echo "<a href='?action=audit' class='btn btn-blue'>Auditoría</a> <a href='?action=verify' class='btn btn-green'>Verificar</a>";
    }
    echo "</div>";
    pf();
}

// ============================================================
// FIX BASE64 — Re-procesar base64 en posts existentes
// ============================================================
function do_fix_base64($offset, $batch) {
    global $wpdb;
    page_header('Fix Base64 (string parser)');
    $j = jdb();

    // Increase PCRE limits
    ini_set('pcre.backtrack_limit', '50000000');

    // Get posts that either have base64 in WP content, or whose Joomla source has base64
    $total = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id' WHERE p.post_type='post' AND p.post_status NOT IN('trash','inherit')");

    $posts = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_title, p.post_content, pm.meta_value as zoo_id
         FROM $wpdb->posts p
         JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id'
         WHERE p.post_type='post' AND p.post_status NOT IN('trash','inherit')
         ORDER BY p.ID ASC LIMIT %d, %d",
        $offset, $batch
    ));

    echo "<div class='card'><h2>Fix Base64 (lote $offset, parser de strings)</h2><div class='log'>";

    $fixed_posts = 0; $fixed_imgs = 0; $from_joomla = 0;

    foreach ($posts as $post) {
        $changed = false;
        $content = $post->post_content;

        // A) Check if WP content has base64
        if (strpos($content, 'data:image') !== false) {
            $r = fix_base64_images($content, $post->ID);
            if ($r['fixed'] > 0) {
                $content = $r['html'];
                $fixed_imgs += $r['fixed'];
                $changed = true;
                lm("WP base64→file: {$r['fixed']} en {$post->post_title}", 'success');
            }
        }

        // B) If WP is empty, try Joomla (which might have base64)
        if (strlen($post->post_content) < 50) {
            $zoo_id = intval($post->zoo_id);
            $jr = $j->query("SELECT elements FROM jos_zoo_item WHERE id=$zoo_id AND type IN('article','page')");
            if ($jr && $jr->num_rows > 0) {
                $jrow = $jr->fetch_assoc();
                $result = process_content($jrow['elements'], $post->ID);
                if ($result['has_text']) {
                    $content = $result['content'];
                    $fixed_imgs += $result['b64_fixed'];
                    $from_joomla++;
                    $changed = true;
                    lm("Joomla→WP: Zoo:$zoo_id | {$result['b64_fixed']}b64 {$result['img_fixed']}img | {$post->post_title}", 'success');
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
    echo "<div class='stat-box ok'><div class='number'>$fixed_imgs</div><div class='label'>Base64 → archivo</div></div>";
    echo "<div class='stat-box ok'><div class='number'>$from_joomla</div><div class='label'>Recuperados de Joomla</div></div>";
    echo "</div>";

    $processed = $offset + count($posts);
    $pct = $total > 0 ? min(100, round(($processed / $total) * 100)) : 100;
    echo "<div class='card'><div class='progress'><div class='fill' style='width:{$pct}%'>$pct%</div></div>";

    if (count($posts) >= $batch && $processed < $total) {
        $next = $offset + $batch;
        $url = "?action=fix_base64&offset=$next&batch=$batch";
        echo "<script>setTimeout(function(){window.location.href='$url';},1500);</script>";
        echo "<a href='$url' class='btn btn-blue'>Siguiente</a> <a href='?action=audit' class='btn btn-gray'>Detener</a>";
    } else {
        echo "<p style='color:#7ee787;font-weight:bold'>Base64 completado.</p>";
        echo "<a href='?action=audit' class='btn btn-blue'>Auditoría</a>";
    }
    echo "</div>";
    pf();
}

// ============================================================
// VERIFY — Verificación 1:1 en grid de columnas
// ============================================================
function do_verify() {
    global $wpdb;
    page_header('Verificación 1:1');
    $j = jdb();

    // Build WP map: zoo_id -> post data
    $wp_data = [];
    $rows = $wpdb->get_results("
        SELECT p.ID, p.post_title, p.post_status, LENGTH(p.post_content) as clen,
               p.post_content LIKE '%data:image%' as has_b64,
               p.post_content LIKE '%src=\"images/%' as has_broken_img,
               pm.meta_value as zoo_id
        FROM $wpdb->posts p
        JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id'
        WHERE p.post_type='post' AND p.post_status NOT IN('trash','inherit')
    ");
    foreach ($rows as $r) $wp_data[$r->zoo_id] = $r;

    // Get ALL Joomla items
    $all_j = [];
    $jr = $j->query("SELECT id, name, state, type,
        LENGTH(elements) as elen,
        elements LIKE '%data:image%' as has_b64
        FROM jos_zoo_item WHERE type IN('article','page') ORDER BY id DESC");
    while ($row = $jr->fetch_assoc()) $all_j[] = $row;

    // Classify
    $ok = []; $empty = []; $missing = []; $with_b64 = []; $broken = [];
    foreach ($all_j as $ji) {
        $wp = isset($wp_data[$ji['id']]) ? $wp_data[$ji['id']] : null;
        if (!$wp) {
            $missing[] = $ji;
        } elseif ($wp->clen < 50) {
            $empty[] = ['j' => $ji, 'wp' => $wp];
        } elseif ($wp->has_b64) {
            $with_b64[] = ['j' => $ji, 'wp' => $wp];
        } elseif ($wp->has_broken_img) {
            $broken[] = ['j' => $ji, 'wp' => $wp];
        } else {
            $ok[] = ['j' => $ji, 'wp' => $wp];
        }
    }

    // STATS
    echo "<div class='stat-grid'>";
    echo "<div class='stat-box ok'><div class='number'>" . count($ok) . "</div><div class='label'>OK</div></div>";
    echo "<div class='stat-box" . (count($empty) > 0 ? ' warn' : ' ok') . "'><div class='number'>" . count($empty) . "</div><div class='label'>Vacíos</div></div>";
    echo "<div class='stat-box" . (count($missing) > 0 ? '' : ' ok') . "'><div class='number'>" . count($missing) . "</div><div class='label'>Faltan</div></div>";
    echo "<div class='stat-box" . (count($with_b64) > 0 ? '' : ' ok') . "'><div class='number'>" . count($with_b64) . "</div><div class='label'>Con base64</div></div>";
    echo "<div class='stat-box" . (count($broken) > 0 ? '' : ' ok') . "'><div class='number'>" . count($broken) . "</div><div class='label'>Imgs rotas</div></div>";
    echo "</div>";

    // MISSING GRID
    if (count($missing) > 0) {
        echo "<div class='card'><h2 style='color:#f85149'>Faltan (" . count($missing) . ")</h2><div class='article-grid'>";
        $n = 0;
        foreach ($missing as $m) {
            $st = $m['state'] == 1 ? 'pub' : 'draft';
            echo "<div class='article-cell status-error'>";
            echo "<div class='cell-id'>Zoo:{$m['id']}</div>";
            echo "<div class='cell-title'>" . htmlspecialchars(mb_substr($m['name'], 0, 55)) . "</div>";
            echo "<div class='cell-meta'><span class='tag tag-$st'>" . ($m['state'] == 1 ? 'PUB' : 'NOPUB') . "</span>";
            if ($m['has_b64']) echo " <span class='tag tag-b64'>B64</span>";
            echo "</div></div>";
            if (++$n >= 150) { echo "<div class='article-cell' style='grid-column:1/-1;text-align:center;color:#8b949e'>... y " . (count($missing)-150) . " más</div>"; break; }
        }
        echo "</div></div>";
    }

    // EMPTY GRID
    if (count($empty) > 0) {
        echo "<div class='card'><h2 style='color:#d29922'>Vacíos (" . count($empty) . ")</h2><div class='article-grid'>";
        $n = 0;
        foreach ($empty as $e) {
            echo "<div class='article-cell status-warn'>";
            echo "<div class='cell-id'>WP:{$e['wp']->ID} ← Zoo:{$e['j']['id']}</div>";
            echo "<div class='cell-title'>" . htmlspecialchars(mb_substr($e['j']['name'], 0, 55)) . "</div>";
            echo "<div class='cell-meta'><span class='tag tag-empty'>VACÍO</span></div>";
            echo "</div>";
            if (++$n >= 150) break;
        }
        echo "</div></div>";
    }

    // BASE64 GRID
    if (count($with_b64) > 0) {
        echo "<div class='card'><h2 style='color:#8957e5'>Con Base64 sin convertir (" . count($with_b64) . ")</h2><div class='article-grid'>";
        $n = 0;
        foreach ($with_b64 as $b) {
            echo "<div class='article-cell status-new'>";
            echo "<div class='cell-id'>WP:{$b['wp']->ID} ← Zoo:{$b['j']['id']}</div>";
            echo "<div class='cell-title'>" . htmlspecialchars(mb_substr($b['j']['name'], 0, 55)) . "</div>";
            echo "<div class='cell-meta'><span class='tag tag-b64'>BASE64</span> <span class='tag tag-ok'>{$b['wp']->clen}ch</span></div>";
            echo "</div>";
            if (++$n >= 150) break;
        }
        echo "</div></div>";
    }

    // OK GRID (collapsed)
    echo "<div class='card'><details><summary style='color:#7ee787;cursor:pointer;font-size:16px;font-weight:bold'>Artículos OK (" . count($ok) . ") — click para expandir</summary>";
    echo "<div class='article-grid' style='margin-top:10px'>";
    $n = 0;
    foreach ($ok as $o) {
        echo "<div class='article-cell status-ok'>";
        echo "<div class='cell-id'>WP:{$o['wp']->ID} ← Zoo:{$o['j']['id']}</div>";
        echo "<div class='cell-title'>" . htmlspecialchars(mb_substr($o['j']['name'], 0, 55)) . "</div>";
        echo "<div class='cell-meta'><span class='tag tag-ok'>OK {$o['wp']->clen}ch</span></div>";
        echo "</div>";
        if (++$n >= 300) { echo "<div class='article-cell' style='grid-column:1/-1;text-align:center;color:#8b949e'>... y " . (count($ok)-300) . " más</div>"; break; }
    }
    echo "</div></details></div>";

    pf();
}

// ============================================================
// DIAGNOSE — Detectar posts publicados incorrectamente
// ============================================================
function do_diagnose() {
    global $wpdb;
    page_header('Diagnóstico de Estado');
    $j = jdb();

    // 1) Posts huérfanos en WP (publicados o draft, sin ID de Joomla)
    $orphans = $wpdb->get_results("
        SELECT p.ID, p.post_title, p.post_status, p.post_date
        FROM $wpdb->posts p
        LEFT JOIN $wpdb->postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_joomla_zoo_id'
        WHERE p.post_type = 'post'
          AND p.post_status NOT IN('trash','inherit','auto-draft')
          AND pm.meta_value IS NULL
        ORDER BY p.post_date DESC
    ");

    // 2) Estado incorrecto: WP vs Joomla
    $mismatch = [];
    $ghost    = []; // En WP pero no existe en Joomla

    $imported = $wpdb->get_results("
        SELECT p.ID, p.post_title, p.post_status, pm.meta_value as zoo_id
        FROM $wpdb->posts p
        JOIN $wpdb->postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_joomla_zoo_id'
        WHERE p.post_type = 'post' AND p.post_status NOT IN('trash','inherit')
    ");

    $jdb = jdb();
    foreach ($imported as $wp_p) {
        $zoo_id = intval($wp_p->zoo_id);
        $jrow = $jdb->query("SELECT state, created FROM jos_zoo_item WHERE id = $zoo_id LIMIT 1");
        $jdata = $jrow ? $jrow->fetch_assoc() : null;
        if (!$jdata) {
            $ghost[] = $wp_p;
            continue;
        }
        $expected = joomla_to_wp_status_with_date($jdata['state'], $jdata['created']);
        // 'future' in WP is valid when Joomla state=1 and date is in the future
        $status_ok = ($wp_p->post_status === $expected)
                  || ($expected === 'publish' && $wp_p->post_status === 'future');
        if (!$status_ok) {
            $mismatch[] = [
                'wp'       => $wp_p,
                'j_state'  => $jdata['state'],
                'expected' => $expected,
            ];
        }
    }

    // Stats
    $orphan_pub = count(array_filter($orphans, fn($o) => $o->post_status === 'publish'));
    echo "<div class='stat-grid'>";
    echo "<div class='stat-box" . (count($orphans) > 0 ? '' : ' ok') . "'><div class='number'>" . count($orphans) . "</div><div class='label'>Huérfanos WP<br><small style='font-size:10px'>($orphan_pub publicados)</small></div></div>";
    echo "<div class='stat-box" . (count($mismatch) > 0 ? '' : ' ok') . "'><div class='number'>" . count($mismatch) . "</div><div class='label'>Estado incorrecto</div></div>";
    echo "<div class='stat-box" . (count($ghost) > 0 ? '' : ' ok') . "'><div class='number'>" . count($ghost) . "</div><div class='label'>En WP, NO en Joomla</div></div>";
    echo "</div>";

    // HUÉRFANOS (sin ID de Joomla)
    if (count($orphans) > 0) {
        echo "<div class='card'><h2 style='color:#f85149'>Posts huérfanos en WP — sin ID de Joomla (" . count($orphans) . ")</h2>";
        echo "<p style='color:#8b949e'>Estos posts existen en WordPress pero NO provienen de Joomla. Pueden ser posts de prueba, el post de muestra de WP, o posts creados antes de la migración. <strong style='color:#f85149'>Si están publicados y no deberían estar, usa Fix Estado o elimínalos manualmente.</strong></p>";
        echo "<div class='article-grid'>";
        foreach ($orphans as $o) {
            $st_c = $o->post_status === 'publish' ? 'tag-pub' : 'tag-draft';
            echo "<div class='article-cell status-error'>";
            echo "<div class='cell-id'>WP:{$o->ID}</div>";
            echo "<div class='cell-title' title='" . htmlspecialchars($o->post_title) . "'>" . htmlspecialchars(mb_substr($o->post_title, 0, 55)) . "</div>";
            echo "<div class='cell-meta'><span class='tag $st_c'>" . strtoupper($o->post_status) . "</span> <span style='color:#8b949e'>" . substr($o->post_date, 0, 10) . "</span></div>";
            echo "</div>";
        }
        echo "</div></div>";
    }

    // GHOST (importados pero ya no existen en Joomla)
    if (count($ghost) > 0) {
        echo "<div class='card'><h2 style='color:#f85149'>Posts importados pero ya NO existen en Joomla (" . count($ghost) . ")</h2>";
        echo "<div class='article-grid'>";
        foreach ($ghost as $g) {
            $st_c = $g->post_status === 'publish' ? 'tag-pub' : 'tag-draft';
            echo "<div class='article-cell status-error'>";
            echo "<div class='cell-id'>WP:{$g->ID} ← Zoo:{$g->zoo_id}</div>";
            echo "<div class='cell-title'>" . htmlspecialchars(mb_substr($g->post_title, 0, 50)) . "</div>";
            echo "<div class='cell-meta'><span class='tag $st_c'>" . strtoupper($g->post_status) . "</span></div>";
            echo "</div>";
        }
        echo "</div></div>";
    }

    // MISMATCH (estado incorrecto)
    if (count($mismatch) > 0) {
        $pub_wrong = count(array_filter($mismatch, fn($m) => $m['wp']->post_status === 'publish' && $m['expected'] !== 'publish'));
        echo "<div class='card'><h2 style='color:#d29922'>Estado incorrecto en WP — " . count($mismatch) . " posts ($pub_wrong publicados que deberían ser draft/pending)</h2>";
        echo "<div class='article-grid'>";
        foreach ($mismatch as $m) {
            $from_c = $m['wp']->post_status === 'publish' ? 'tag-pub' : 'tag-draft';
            $to_c   = $m['expected'] === 'publish' ? 'tag-pub' : 'tag-draft';
            echo "<div class='article-cell status-warn'>";
            echo "<div class='cell-id'>WP:{$m['wp']->ID} ← Zoo:{$m['wp']->zoo_id}</div>";
            echo "<div class='cell-title'>" . htmlspecialchars(mb_substr($m['wp']->post_title, 0, 45)) . "</div>";
            echo "<div class='cell-meta'>";
            echo "<span class='tag $from_c'>" . strtoupper($m['wp']->post_status) . "</span>";
            echo " → <span class='tag $to_c'>" . strtoupper($m['expected']) . "</span>";
            echo " <span style='color:#8b949e'>J.state=" . $m['j_state'] . "</span>";
            echo "</div></div>";
        }
        echo "</div>";
        echo "<p style='margin-top:12px'><a href='?action=fix_status' class='btn btn-yellow' style='font-size:15px;padding:12px 24px'>CORREGIR ESTADOS (" . count($mismatch) . " posts)</a></p>";
        echo "</div>";
    }

    if (count($orphans) === 0 && count($mismatch) === 0 && count($ghost) === 0) {
        echo "<div class='card' style='border-color:#238636'><p style='color:#7ee787;font-size:18px;font-weight:bold'>Todo OK — No se detectaron problemas de estado.</p></div>";
    }

    pf();
}

// ============================================================
// FIX STATUS — Sincronizar estados Joomla → WordPress
// ============================================================
function do_fix_status() {
    global $wpdb;
    page_header('Corregir Estados');
    $jdb = jdb();

    $imported = $wpdb->get_results("
        SELECT p.ID, p.post_title, p.post_status, pm.meta_value as zoo_id
        FROM $wpdb->posts p
        JOIN $wpdb->postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_joomla_zoo_id'
        WHERE p.post_type = 'post' AND p.post_status NOT IN('trash','inherit')
        ORDER BY p.ID ASC
    ");

    $total = count($imported);
    echo "<div class='card'><h2>Corrigiendo estados ($total posts importados)</h2><div class='log'>";

    $fixed = 0; $ok_count = 0; $ghost = 0;

    foreach ($imported as $wp_p) {
        $zoo_id = intval($wp_p->zoo_id);
        $jrow = $jdb->query("SELECT state, created FROM jos_zoo_item WHERE id = $zoo_id LIMIT 1");
        $jdata = $jrow ? $jrow->fetch_assoc() : null;
        if (!$jdata) {
            lm("AVISO Zoo:$zoo_id NO encontrado en Joomla (WP:{$wp_p->ID} '{$wp_p->post_title}')", 'warn');
            $ghost++;
            continue;
        }
        $expected = joomla_to_wp_status_with_date($jdata['state'], $jdata['created']);
        // 'future' in WP is valid when Joomla state=1 and date is in the future
        $status_ok = ($wp_p->post_status === $expected)
                  || ($expected === 'publish' && $wp_p->post_status === 'future');
        if (!$status_ok) {
            $wpdb->update($wpdb->posts, ['post_status' => $expected], ['ID' => $wp_p->ID]);
            clean_post_cache($wp_p->ID);
            lm("FIXED WP:{$wp_p->ID} [{$wp_p->post_status}→$expected] Zoo:$zoo_id | " . htmlspecialchars(mb_substr($wp_p->post_title, 0, 60)), 'success');
            $fixed++;
        } else {
            $ok_count++;
        }
        @ob_flush(); @flush();
    }

    echo "</div></div>";

    echo "<div class='stat-grid'>";
    echo "<div class='stat-box ok'><div class='number'>$fixed</div><div class='label'>Corregidos</div></div>";
    echo "<div class='stat-box ok'><div class='number'>$ok_count</div><div class='label'>Ya correctos</div></div>";
    echo "<div class='stat-box" . ($ghost > 0 ? ' warn' : ' ok') . "'><div class='number'>$ghost</div><div class='label'>No en Joomla</div></div>";
    echo "</div>";

    echo "<div class='card'>";
    echo "<p style='color:#7ee787;font-weight:bold;font-size:16px'>Estados sincronizados. $fixed posts corregidos.</p>";
    echo "<a href='?action=diagnose' class='btn btn-blue'>Ver Diagnóstico</a> <a href='?action=audit' class='btn btn-gray'>Auditoría</a>";
    echo "</div>";
    pf();
}

// ============================================================
// RESET POSTS — Borra todos los posts importados de Joomla para reimportar limpio
// NO borra: categorías, etiquetas, páginas, usuarios, opciones/tema, archivos de media.
// ============================================================
function do_reset_posts() {
    global $wpdb;

    $confirm         = isset($_GET['confirm'])         && $_GET['confirm']         === '1';
    $include_orphans = isset($_GET['include_orphans']) && $_GET['include_orphans'] === '1';

    page_header('Reset de Posts');

    // ── Contar posts Joomla (tienen _joomla_zoo_id) ──────────────────────────
    $zoo_posts = $wpdb->get_results(
        "SELECT p.ID, p.post_title, p.post_status
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_joomla_zoo_id'
         WHERE p.post_type = 'post'"
    );
    $total_zoo = count($zoo_posts);

    // ── Contar posts huérfanos (sin _joomla_zoo_id) ──────────────────────────
    $orphan_posts = $wpdb->get_results(
        "SELECT p.ID, p.post_title, p.post_status
         FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_joomla_zoo_id'
         WHERE p.post_type = 'post'
           AND pm.meta_value IS NULL
           AND p.post_status NOT IN('trash','inherit')"
    );
    $total_orphan = count($orphan_posts);

    // ── Pantalla de confirmación ──────────────────────────────────────────────
    if (!$confirm) {
        $total_to_del = $total_zoo + ($include_orphans ? $total_orphan : 0);
        echo "<div class='card' style='border:2px solid #f85149'>";
        echo "<h2 style='color:#f85149'>⚠️  Borrar posts y reimportar desde Joomla</h2>";
        echo "<p>Esta acción eliminará los posts importados de WordPress para que puedas volver a
              importar desde Joomla con un estado limpio.<br>
              Los archivos de imagen, categorías, etiquetas, páginas, usuarios y
              configuración del tema <strong style='color:#7ee787'>NO se tocarán</strong>.</p>";

        echo "<div class='stat-grid'>";
        echo "<div class='stat-box warn'><div class='number'>$total_zoo</div><div class='label'>Posts con ID Joomla</div></div>";
        echo "<div class='stat-box " . ($total_orphan > 0 ? 'warn' : 'ok') . "'><div class='number'>$total_orphan</div><div class='label'>Posts huérfanos</div></div>";
        echo "</div>";

        echo "<p style='margin-top:12px'><strong>Opción 1 — Solo posts de Joomla</strong> (recomendado):<br>
              Borra únicamente los $total_zoo posts que tienen <code>_joomla_zoo_id</code>.</p>";
        echo "<a href='?action=reset_posts&confirm=1'
                 class='btn btn-red'
                 onclick=\"return confirm('¿Confirmar borrado de {$total_zoo} posts importados de Joomla?');\">
              CONFIRMAR — Borrar {$total_zoo} posts Joomla</a>";

        if ($total_orphan > 0) {
            $total_all = $total_zoo + $total_orphan;
            echo "<p style='margin-top:16px'><strong>Opción 2 — Todos los posts</strong> (posts Joomla + huérfanos):<br>
                  Borra los $total_zoo posts de Joomla <em>más</em> los $total_orphan posts sin origen.</p>";
            echo "<a href='?action=reset_posts&confirm=1&include_orphans=1'
                     class='btn btn-red'
                     onclick=\"return confirm('¿Confirmar borrado de {$total_all} posts (Joomla + huérfanos)?');\">
                  CONFIRMAR — Borrar {$total_all} posts (todos)</a>";
        }

        echo " &nbsp;<a href='?action=audit' class='btn btn-gray'>Cancelar</a>";
        echo "</div>";
        pf();
        return;
    }

    // ── Construir lista de IDs a borrar ──────────────────────────────────────
    $post_ids = array_map(function($p) { return intval($p->ID); }, $zoo_posts);
    if ($include_orphans) {
        $orphan_ids = array_map(function($p) { return intval($p->ID); }, $orphan_posts);
        $post_ids   = array_unique(array_merge($post_ids, $orphan_ids));
    }

    if (empty($post_ids)) {
        lm("No hay posts para borrar.", 'info');
        pf();
        return;
    }

    echo "<div class='card'><h2>Borrando " . count($post_ids) . " posts...</h2><div class='log'>";

    $deleted = 0;
    foreach ($post_ids as $pid) {
        // 1) Postmeta
        $wpdb->delete($wpdb->postmeta,        ['post_id'  => $pid]);
        // 2) Term relationships (categories/tags)
        $wpdb->delete($wpdb->term_relationships, ['object_id' => $pid]);
        // 3) Comments + commentmeta
        $cids = $wpdb->get_col($wpdb->prepare(
            "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID = %d", $pid
        ));
        foreach ($cids as $cid) {
            $wpdb->delete($wpdb->commentmeta, ['comment_id' => $cid]);
            $wpdb->delete($wpdb->comments,    ['comment_ID' => $cid]);
        }
        // 4) The post itself
        $wpdb->delete($wpdb->posts, ['ID' => $pid]);
        clean_post_cache($pid);
        $deleted++;
        if ($deleted % 100 === 0) {
            lm("  … $deleted borrados", 'info');
            @ob_flush(); @flush();
        }
    }

    // ── Recalcular conteos de términos ────────────────────────────────────────
    $all_terms = $wpdb->get_col("SELECT DISTINCT tt.term_taxonomy_id FROM {$wpdb->term_taxonomy} tt");
    if ($all_terms) {
        wp_update_term_count_now($all_terms, 'category');
        wp_update_term_count_now($all_terms, 'post_tag');
    }

    // Eliminar caché del mapa de categorías para que se reconstruya en el siguiente sync
    delete_option('joomla_zoo_category_map');

    echo "</div></div>";

    echo "<div class='stat-grid'>";
    echo "<div class='stat-box ok'><div class='number'>$deleted</div><div class='label'>Posts eliminados</div></div>";
    echo "</div>";

    echo "<div class='card'>";
    echo "<p style='color:#7ee787;font-weight:bold;font-size:16px'>✓ Reset completo — $deleted posts eliminados.</p>";
    echo "<p>Ahora ejecuta <strong>Sincronizar TODO</strong> para reimportar todos los artículos desde Joomla con estado limpio.</p>";
    echo "<a href='?action=sync' class='btn btn-green'>Sincronizar TODO</a> &nbsp;";
    echo "<a href='?action=audit' class='btn btn-blue'>Auditoría</a>";
    echo "</div>";
    pf();
}

// ============================================================
// FIX AUTHORS — Actualiza post_author en todos los posts importados
// ============================================================
function do_fix_authors() {
    global $wpdb;
    page_header('Fix Autores');

    $author_map = build_author_map();
    $total_map  = count($author_map);

    echo "<div class='card'>";
    echo "<p style='color:#8b949e'>Mapa cargado: <strong>$total_map autores</strong> Zoo → WP.<br>";
    echo "Actualizando <code>post_author</code> en posts importados según el elemento Author de Joomla Zoo…</p>";
    echo "<div class='log'>";

    $j = jdb();
    $imported = $wpdb->get_results("
        SELECT p.ID, p.post_author, p.post_title, pm.meta_value as zoo_id
        FROM $wpdb->posts p
        JOIN $wpdb->postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_joomla_zoo_id'
        WHERE p.post_type = 'post' AND p.post_status NOT IN('trash','inherit')
        ORDER BY p.ID ASC
    ");

    $fixed = 0; $ok = 0; $no_author = 0; $errors = 0;

    foreach ($imported as $wp_p) {
        $zoo_id = intval($wp_p->zoo_id);
        $jrow   = $j->query("SELECT elements FROM jos_zoo_item WHERE id=$zoo_id LIMIT 1");
        $jdata  = $jrow ? $jrow->fetch_assoc() : null;

        if (!$jdata) { $errors++; continue; }

        $zoo_auth_id = get_zoo_author_id_from_elements($jdata['elements']);
        if (!$zoo_auth_id || !isset($author_map[$zoo_auth_id])) {
            $no_author++;
            continue;
        }

        $correct = $author_map[$zoo_auth_id];
        if (intval($wp_p->post_author) === $correct) {
            $ok++;
            continue;
        }

        $wpdb->update($wpdb->posts, ['post_author' => $correct], ['ID' => $wp_p->ID]);
        clean_post_cache($wp_p->ID);
        lm("FIXED WP:{$wp_p->ID} author:{$wp_p->post_author}→{$correct} Zoo:{$zoo_id} | " . htmlspecialchars(mb_substr($wp_p->post_title, 0, 55)), 'success');
        $fixed++;
        @ob_flush(); @flush();
    }

    echo "</div></div>";

    echo "<div class='stat-grid'>";
    echo "<div class='stat-box ok'><div class='number'>$fixed</div><div class='label'>Autores corregidos</div></div>";
    echo "<div class='stat-box ok'><div class='number'>$ok</div><div class='label'>Ya correctos</div></div>";
    echo "<div class='stat-box" . ($no_author > 0 ? '' : ' ok') . "'><div class='number'>$no_author</div><div class='label'>Sin autor en Zoo</div></div>";
    echo "<div class='stat-box" . ($errors > 0 ? ' warn' : ' ok') . "'><div class='number'>$errors</div><div class='label'>No en Joomla</div></div>";
    echo "</div>";
    pf();
}

// ============================================================
// ROUTER
// ============================================================
switch ($action) {
    case 'audit':       do_audit(); break;
    case 'sync':        do_sync($offset, $batch); break;
    case 'fix_base64':  do_fix_base64($offset, $batch); break;
    case 'verify':      do_verify(); break;
    case 'diagnose':    do_diagnose(); break;
    case 'fix_status':  do_fix_status(); break;
    case 'fix_authors': do_fix_authors(); break;
    case 'reset_posts': do_reset_posts(); break;
    default:            do_audit();
}
