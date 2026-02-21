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
// EXTRACT ZOO CONTENT
// ============================================================
function extract_content($elements_json) {
    $e = json_decode($elements_json, true);
    if (!$e || !is_array($e)) return '';
    $content = '';
    $uuid = '2e3c9e69-1f9e-4647-8d13-4e88094d2790';
    if (isset($e[$uuid]) && is_array($e[$uuid])) {
        foreach ($e[$uuid] as $item) {
            if (isset($item['value']) && is_string($item['value']) && strlen($item['value']) > 10)
                $content .= $item['value'] . "\n";
        }
    }
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

    $j_pub = $j->query("SELECT COUNT(*) c FROM jos_zoo_item WHERE type IN('article','page') AND state=1")->fetch_assoc()['c'];
    $j_auth = $j->query("SELECT COUNT(*) c FROM jos_zoo_item WHERE type='author'")->fetch_assoc()['c'];
    $j_b64 = $j->query("SELECT COUNT(*) c FROM jos_zoo_item WHERE type='article' AND state=1 AND elements LIKE '%data:image%'")->fetch_assoc()['c'];

    $wp_pub = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status='publish'");
    $wp_tracked = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key='_joomla_zoo_id'");

    // Duplicates by zoo_id
    $dup_zoo = $wpdb->get_var("SELECT COUNT(*) FROM (SELECT meta_value, COUNT(*) cnt FROM $wpdb->postmeta WHERE meta_key='_joomla_zoo_id' GROUP BY meta_value HAVING cnt>1) t");
    $dup_excess = $wpdb->get_var("SELECT COALESCE(SUM(cnt-1),0) FROM (SELECT meta_value, COUNT(*) cnt FROM $wpdb->postmeta WHERE meta_key='_joomla_zoo_id' GROUP BY meta_value HAVING cnt>1) t");

    // Empty content
    $empty = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id' WHERE p.post_type='post' AND p.post_status='publish' AND LENGTH(p.post_content)<50");

    // Broken images
    $broken_img = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status='publish' AND post_content LIKE '%src=\"images/%'");

    // Base64 in WP
    $wp_b64 = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status='publish' AND post_content LIKE '%data:image%'");

    // Missing posts
    $imported = array_flip($wpdb->get_col("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key='_joomla_zoo_id'"));
    $r = $j->query("SELECT id FROM jos_zoo_item WHERE type IN('article','page') AND state=1");
    $missing = 0;
    while ($row = $r->fetch_assoc()) { if (!isset($imported[$row['id']])) $missing++; }

    echo "<div class='stat-grid'>";
    echo "<div class='stat-box" . ($dup_excess > 0 ? '' : ' ok') . "'><div class='number'>$dup_excess</div><div class='label'>Duplicados (zoo_id)</div></div>";
    echo "<div class='stat-box" . ($empty > 0 ? ' warn' : ' ok') . "'><div class='number'>$empty</div><div class='label'>Posts vacíos</div></div>";
    echo "<div class='stat-box" . ($broken_img > 0 ? '' : ' ok') . "'><div class='number'>$broken_img</div><div class='label'>src=\"images/...\"</div></div>";
    echo "<div class='stat-box" . ($wp_b64 > 0 ? ' warn' : ' ok') . "'><div class='number'>$wp_b64 / $j_b64</div><div class='label'>Base64 WP/Joomla</div></div>";
    echo "<div class='stat-box" . ($missing > 0 ? '' : ' ok') . "'><div class='number'>$missing</div><div class='label'>Faltan importar</div></div>";
    echo "<div class='stat-box ok'><div class='number'>$wp_pub</div><div class='label'>WP publicados</div></div>";
    echo "</div>";

    echo "<div class='card'><h2>Plan de ejecución</h2>";
    echo "<div style='line-height:2.5;'>";

    $s1 = $dup_excess > 0 ? 'active' : 'done';
    $s2 = $empty > 0 ? 'active' : 'done';
    $s3 = $broken_img > 0 ? 'active' : 'done';
    $s4 = ($wp_b64 > 0 || $j_b64 > $wp_b64) ? 'active' : 'done';
    $s5 = $missing > 0 ? 'active' : 'done';

    echo "<span class='step $s1'>1</span><strong>Deduplicar por zoo_id</strong> — $dup_excess posts sobrantes (mismo artículo importado varias veces) ";
    if ($dup_excess > 0) echo "<a href='?action=dedup' class='btn btn-red'>Ejecutar</a>";
    echo "<br>";

    echo "<span class='step $s2'>2</span><strong>Recuperar contenido</strong> — $empty posts vacíos, re-extraer desde Joomla (incluye base64 → archivo) ";
    if ($empty > 0) echo "<a href='?action=recover' class='btn btn-green'>Ejecutar</a>";
    echo "<br>";

    echo "<span class='step $s3'>3</span><strong>Fix rutas imágenes</strong> — $broken_img posts con src=\"images/...\" ";
    if ($broken_img > 0) echo "<a href='?action=fix_images' class='btn btn-yellow'>Ejecutar</a>";
    echo "<br>";

    echo "<span class='step $s4'>4</span><strong>Fix base64</strong> — $j_b64 artículos en Joomla tienen base64, solo $wp_b64 en WP. Extraer a archivos. ";
    if ($j_b64 > 0) echo "<a href='?action=fix_base64' class='btn btn-yellow'>Ejecutar</a>";
    echo "<br>";

    echo "<span class='step $s5'>5</span><strong>Importar faltantes</strong> — $missing posts no importados ";
    if ($missing > 0) echo "<a href='?action=import_missing' class='btn btn-green'>Ejecutar</a>";
    echo "<br>";

    echo "<br><a href='?action=fix_all' class='btn btn-green' style='background:#1f6feb;font-size:16px;padding:12px 24px'>Ejecutar TODO en secuencia</a>";
    echo "</div></div>";

    // Detail
    echo "<div class='card'><h2>Detalle</h2><div class='log'>";
    lm("Joomla: $j_pub artículos pub + $j_auth autores", 'info');
    lm("WordPress: $wp_pub posts pub, $wp_tracked con zoo_id", 'info');
    lm("Duplicados: $dup_zoo zoo_ids repetidos = $dup_excess posts sobrantes", $dup_excess > 0 ? 'error' : 'success');
    lm("Vacíos: $empty posts con <50 chars (recuperables de Joomla)", $empty > 0 ? 'warn' : 'success');
    lm("Imágenes rotas: $broken_img posts con rutas Joomla", $broken_img > 0 ? 'error' : 'success');
    lm("Base64: $j_b64 en Joomla, $wp_b64 en WP (falta convertir " . ($j_b64 - $wp_b64) . ")", ($j_b64 > $wp_b64) ? 'warn' : 'success');
    lm("Faltantes: $missing posts no importados", $missing > 0 ? 'warn' : 'success');
    echo "</div></div>";

    pf();
}

// ============================================================
// PASO 1: DEDUP BY ZOO_ID
// ============================================================
function do_dedup($offset, $batch) {
    global $wpdb;
    page_header('Paso 1: Deduplicar');

    $total_groups = $wpdb->get_var("SELECT COUNT(*) FROM (SELECT meta_value FROM $wpdb->postmeta WHERE meta_key='_joomla_zoo_id' GROUP BY meta_value HAVING COUNT(*)>1) t");
    $total_excess = $wpdb->get_var("SELECT COALESCE(SUM(cnt-1),0) FROM (SELECT meta_value, COUNT(*) cnt FROM $wpdb->postmeta WHERE meta_key='_joomla_zoo_id' GROUP BY meta_value HAVING cnt>1) t");

    echo "<div class='card'><h2>Eliminando duplicados por zoo_id (lote $offset)</h2><div class='log'>";
    lm("Grupos duplicados: $total_groups | Posts sobrantes: $total_excess", 'info');

    // Get batch of duplicate zoo_ids
    $dups = $wpdb->get_results($wpdb->prepare(
        "SELECT meta_value as zoo_id, COUNT(*) cnt FROM $wpdb->postmeta WHERE meta_key='_joomla_zoo_id' GROUP BY meta_value HAVING cnt>1 ORDER BY cnt DESC LIMIT %d, %d",
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

    $total = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts p
        JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id'
        WHERE p.post_type='post' AND p.post_status='publish' AND LENGTH(p.post_content)<50");

    echo "<div class='card'><h2>Recuperando contenido ($offset / $total)</h2><div class='log'>";

    $posts = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_title, pm.meta_value as zoo_id
         FROM $wpdb->posts p
         JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id'
         WHERE p.post_type='post' AND p.post_status='publish' AND LENGTH(p.post_content)<50
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

    // Get ALL posts that have _joomla_zoo_id and check Joomla for base64
    // We process in batches to avoid timeout
    $total = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key='_joomla_zoo_id'");

    $posts = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_title, p.post_content, pm.meta_value as zoo_id
         FROM $wpdb->posts p
         JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id'
         WHERE p.post_type='post' AND p.post_status='publish'
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

        // B) Check against Joomla content if we suspect base64 might be missing
        // If WP content has base64, we processed it in (A).
        // If not, we check if Joomla has base64.
        if (!$changed) {
            $zoo_id = intval($post->zoo_id);
            $jr = $j->query("SELECT elements FROM jos_zoo_item WHERE id=$zoo_id");
            if ($jr && $jr->num_rows > 0) {
                $jrow = $jr->fetch_assoc();
                if (strpos($jrow['elements'], 'data:image') !== false) {
                    $jcontent = extract_content($jrow['elements']);
                    if (!empty(trim(strip_tags($jcontent)))) {
                        // Fix images and base64 in recovered content
                        $img_r = fix_joomla_image_urls($jcontent);
                        $jcontent = $img_r['html'];
                        $b64_r = fix_base64_in_string($jcontent, $post->ID);
                        $jcontent = $b64_r['html'];

                        // If base64 was fixed, update WP content
                        if ($b64_r['fixed'] > 0) {
                            $content = $jcontent;
                            $recovered_b64 += $b64_r['fixed'];
                            $changed = true;
                            lm("Recuperado faltante base64: {$post->post_title} (+{$b64_r['fixed']} imgs)", 'success');
                        }
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
function do_import_missing($offset, $batch) {
    global $wpdb;
    page_header('Paso 5: Importar Faltantes');
    $j = jdb();

    $imported = array_flip($wpdb->get_col("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key='_joomla_zoo_id'"));
    $cat_map = get_option('joomla_zoo_category_map', []);

    // Rebuild cat_map if empty
    if (empty($cat_map)) {
        $cats = $j->query("SELECT * FROM jos_zoo_category WHERE published=1 ORDER BY parent ASC");
        while ($c = $cats->fetch_assoc()) {
            $term = get_term_by('slug', $c['alias'], 'category');
            if ($term) $cat_map[$c['id']] = $term->term_id;
        }
        update_option('joomla_zoo_category_map', $cat_map);
    }

    // Get missing items
    $all_joomla = $j->query("SELECT * FROM jos_zoo_item WHERE type IN('article','page') AND state=1 ORDER BY id ASC");
    $missing_items = [];
    while ($row = $all_joomla->fetch_assoc()) {
        if (!isset($imported[$row['id']])) $missing_items[] = $row;
    }
    $total = count($missing_items);

    echo "<div class='card'><h2>Importando $total posts faltantes (desde $offset)</h2><div class='log'>";

    $created = 0; $errors = 0;
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

        $wp_id = wp_insert_post([
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

        lm("Creado: {$row['name']} (Zoo:{$row['id']} → WP:$wp_id)$extra_txt", 'success');
        $created++;
    }

    echo "</div></div>";

    echo "<div class='stat-grid'>";
    echo "<div class='stat-box ok'><div class='number'>$created</div><div class='label'>Creados</div></div>";
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
            $remaining = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id' WHERE p.post_type='post' AND p.post_status='publish' AND LENGTH(p.post_content)<50");
            if ($remaining > 0 || $offset > 0) {
                do_recover($offset, $batch);
                return;
            }
            $step = 'fix_images';
            $offset = 0;
            // fall through

        case 'fix_images':
            $remaining = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_content LIKE '%src=\"images/%'");
            if ($remaining > 0) {
                do_fix_images();
                echo "<script>setTimeout(function(){window.location.href='?action=fix_all&step=fix_base64&offset=0&batch=$batch';},3000);</script>";
                return;
            }
            $step = 'fix_base64';
            $offset = 0;
            // fall through

        case 'fix_base64':
            do_fix_base64($offset, $batch);
            return;

        case 'import':
            do_import_missing($offset, $batch);
            return;
    }
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
    default:               do_audit();
}
