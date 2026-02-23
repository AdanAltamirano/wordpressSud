<?php
/**
 * Shared functions for Joomla -> WordPress Migration
 */

// Load configuration if available
if (file_exists(__DIR__ . '/migration-config.php')) {
    require_once __DIR__ . '/migration-config.php';
}

// Fallback constants if not defined in config
if (!defined('J_HOST')) define('J_HOST', defined('JOOMLA_DB_HOST') ? JOOMLA_DB_HOST : '127.0.0.1');
if (!defined('J_PORT')) define('J_PORT', defined('JOOMLA_DB_PORT') ? JOOMLA_DB_PORT : 3306);
if (!defined('J_USER')) define('J_USER', defined('JOOMLA_DB_USER') ? JOOMLA_DB_USER : 'root');
if (!defined('J_PASS')) define('J_PASS', defined('JOOMLA_DB_PASS') ? JOOMLA_DB_PASS : 'yue02');
if (!defined('J_DB'))   define('J_DB',   defined('JOOMLA_DB_NAME') ? JOOMLA_DB_NAME : 'sudcalifornios');

if (!defined('UPLOADS_DIR')) define('UPLOADS_DIR', ABSPATH . 'wp-content/uploads');
if (!defined('UPLOADS_URL')) define('UPLOADS_URL', site_url('/wp-content/uploads'));
if (!defined('JIMAGES_DIR')) define('JIMAGES_DIR', UPLOADS_DIR . '/joomla-images');

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
// EXTRACT ZOO CONTENT
// ============================================================
function extract_content($elements_json) {
    $e = json_decode($elements_json, true);
    if (!$e || !is_array($e)) return '';

    // Lista de UUIDs conocidos para contenido principal (textarea/wysiwyg)
    $content_uuids = [
        '2e3c9e69-1f9e-4647-8d13-4e88094d2790', // UUID principal conocido (perro.json)
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
function save_base64_image($base64_data, $mime_type, $post_id = 0, $set_featured = false) {
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

    if ($set_featured && $post_id > 0) {
        set_post_thumbnail($post_id, $attach_id);
    }

    return $upload_dir['url'] . '/' . $filename;
}

// ============================================================
// FIX BASE64 IMAGES IN HTML
// ============================================================
function fix_base64_in_string($html, $post_id = 0, $set_featured = false) {
    $count = 0;
    $html = preg_replace_callback(
        '/src=["\']data:image\/([\w\+]+);base64,([A-Za-z0-9+\/=\s]+)["\']/i',
        function($m) use (&$count, $post_id, $set_featured) {
            $mime = 'image/' . $m[1];
            $data = preg_replace('/\s+/', '', $m[2]);
            $url = save_base64_image($data, $mime, $post_id, $set_featured);
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
// HTML UI & HELPERS
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
}
function pf() { echo "</div></body></html>"; }
function lm($m, $c='info') { echo "<div class='$c'>$m</div>\n"; @ob_flush(); @flush(); }

function joomla_state_to_wp($state) {
    // 1=Published, 0=Unpublished, -1=Archived, -2=Trashed
    switch (intval($state)) {
        case 1:  return 'publish';
        case 0:  return 'draft';
        case 2:  return 'pending';
        case -1: return 'draft'; // Archive to draft
        case -2: return 'trash';
        default: return 'draft';
    }
}
