<?php
/**
 * Fix WordPress Content & Images
 *
 * Solo dos funciones:
 * 1. Recuperar contenido de posts vacíos desde Joomla Zoo
 * 2. Corregir URLs de imágenes rotas (src="images/..." -> wp-content/uploads/joomla-images/...)
 *
 * Acciones:
 *   ?action=audit          -> Ver estado actual
 *   ?action=fix_content    -> Recuperar contenido de posts vacíos desde Joomla
 *   ?action=fix_images     -> Corregir rutas de imágenes rotas
 *   ?action=fix_all        -> Hacer ambos (contenido primero, luego imágenes)
 */

set_time_limit(300);
ini_set('memory_limit', '1024M');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . '/wp-load.php');

// DB config
define('J_HOST', '127.0.0.1'); define('J_PORT', 3306);
define('J_USER', 'root'); define('J_PASS', 'yue02'); define('J_DB', 'sudcalifornios');
define('UPLOADS_DIR', ABSPATH . 'wp-content/uploads');
define('UPLOADS_URL', site_url('/wp-content/uploads'));
define('JIMAGES_DIR', UPLOADS_DIR . '/joomla-images');

$action = isset($_GET['action']) ? $_GET['action'] : 'audit';
$offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
$batch  = isset($_GET['batch'])  ? intval($_GET['batch'])  : 100;

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
// BUILD FILE INDEX - mapea nombre de archivo -> ruta relativa en uploads
// ============================================================
function build_file_index() {
    static $index = null;
    if ($index !== null) return $index;
    $index = [];
    $dir = JIMAGES_DIR;
    if (!is_dir($dir)) return $index;
    $di = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
    $it = new RecursiveIteratorIterator($di);
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $path = $file->getPathname();
        if (strpos($path, ' - copia') !== false) continue; // skip backup copies
        $fname = strtolower(basename($path));
        $rel = str_replace([UPLOADS_DIR . '/', UPLOADS_DIR . '\\'], '', $path);
        $rel = str_replace('\\', '/', $rel);
        if (!isset($index[$fname])) {
            $index[$fname] = $rel;
        }
    }
    return $index;
}

// ============================================================
// EXTRACT CONTENT FROM ZOO JSON
// ============================================================
function extract_content($elements_json) {
    $e = json_decode($elements_json, true);
    if (!$e || !is_array($e)) return '';
    $content = '';
    // Known content UUID for this Zoo app
    $uuid = '2e3c9e69-1f9e-4647-8d13-4e88094d2790';
    if (isset($e[$uuid]) && is_array($e[$uuid])) {
        foreach ($e[$uuid] as $item) {
            if (isset($item['value']) && is_string($item['value']) && strlen($item['value']) > 10)
                $content .= $item['value'] . "\n";
        }
    }
    if (empty(trim(strip_tags($content)))) {
        // Fallback: buscar cualquier valor HTML largo
        array_walk_recursive($e, function($v, $k) use (&$content) {
            if ($k === 'value' && is_string($v) && (strlen($v) > 100 || ($v !== strip_tags($v) && strlen($v) > 30)))
                $content .= $v . "\n";
        });
    }
    return trim($content);
}

// ============================================================
// FIX IMAGE URLS IN HTML STRING
// ============================================================
function fix_images_in_string($html) {
    $index = build_file_index();
    $count = 0;
    $not_found = [];

    // Replace src="images/..." or src="./images/..." with correct WP uploads URL
    $html = preg_replace_callback(
        '/src=["\'](?:\.\/)?images\/([^"\']+)["\']/i',
        function($m) use ($index, &$count, &$not_found) {
            $original_path = $m[1];
            $decoded = urldecode($original_path);
            $fname = strtolower(basename($decoded));

            if (isset($index[$fname])) {
                $count++;
                return 'src="' . UPLOADS_URL . '/' . $index[$fname] . '"';
            }
            $not_found[] = $decoded;
            return $m[0]; // Keep original if not found
        },
        $html
    );

    return ['html' => $html, 'fixed' => $count, 'not_found' => $not_found];
}

// ============================================================
// HTML UI
// ============================================================
function page_header($title) {
    echo "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'><title>$title</title><style>
        *{box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0d1117;color:#c9d1d9;padding:20px;margin:0}
        .container{max-width:1200px;margin:0 auto}.card{background:#161b22;border:1px solid #30363d;padding:20px;border-radius:8px;margin-bottom:16px}
        h1,h2{color:#58a6ff;margin-top:0}.log{background:#010409;color:#7ee787;padding:15px;border-radius:6px;max-height:600px;overflow-y:auto;font-family:Consolas,monospace;font-size:13px;line-height:1.6}
        .log .error{color:#f85149}.log .warn{color:#d29922}.log .info{color:#58a6ff}.log .success{color:#7ee787}
        .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-bottom:16px}
        .stat-box{background:#21262d;padding:12px;border-radius:6px;text-align:center;border:1px solid #30363d}
        .stat-box .number{font-size:1.8em;font-weight:bold;color:#f85149}.stat-box .label{color:#8b949e;font-size:.85em;margin-top:4px}
        .stat-box.ok .number{color:#7ee787}.stat-box.warn .number{color:#d29922}
        .btn{display:inline-block;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:600;margin:4px;font-size:14px;border:1px solid #30363d;cursor:pointer}
        .btn-blue{background:#1f6feb;color:#fff;border-color:#1f6feb}.btn-green{background:#238636;color:#fff;border-color:#238636}
        .btn-yellow{background:#9e6a03;color:#fff;border-color:#9e6a03}
        .btn-gray{background:#21262d;color:#58a6ff}
        .nav{margin-bottom:16px;padding:12px;background:#161b22;border-radius:8px;border:1px solid #30363d;display:flex;flex-wrap:wrap;align-items:center;gap:6px}
        .progress{background:#21262d;height:24px;border-radius:12px;overflow:hidden;margin:12px 0}
        .progress .fill{background:linear-gradient(90deg,#1f6feb,#238636);height:100%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:bold}
        table{width:100%;border-collapse:collapse}th,td{padding:6px 10px;text-align:left;border-bottom:1px solid #21262d;font-size:13px}th{color:#8b949e;font-weight:600}
    </style></head><body><div class='container'>";
    echo "<div class='nav'><strong style='color:#58a6ff;font-size:16px'>Fix Content &amp; Images&nbsp;&nbsp;</strong>";
    echo "<a href='?action=audit' class='btn btn-blue'>Auditoría</a>";
    echo "<a href='?action=fix_content' class='btn btn-green'>Recuperar Contenido</a>";
    echo "<a href='?action=fix_images' class='btn btn-yellow'>Fix Imágenes</a>";
    echo "<a href='?action=fix_all' class='btn btn-green'>Fix Todo</a>";
    echo "</div>";
}
function pf() { echo "</div></body></html>"; }
function lm($m, $c='info') { echo "<div class='$c'>$m</div>\n"; @ob_flush(); @flush(); }

// ============================================================
// AUDIT
// ============================================================
function do_audit() {
    global $wpdb;
    page_header('Auditoría');
    $j = jdb();

    // Counts
    $wp_pub = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status='publish'");
    $j_pub = $j->query("SELECT COUNT(*) c FROM jos_zoo_item WHERE type IN('article','page') AND state=1")->fetch_assoc()['c'];

    // Empty posts
    $empty_total = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status='publish' AND LENGTH(post_content)<50");
    $truly_empty = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status='publish' AND (post_content='' OR post_content IS NULL)");

    // Recoverable (have zoo_id)
    $recoverable = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts p
        JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id'
        WHERE p.post_type='post' AND p.post_status='publish' AND LENGTH(p.post_content)<50");

    // Broken images
    $broken_img = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status='publish' AND post_content LIKE '%src=\"images/%'");

    // File index
    $index = build_file_index();

    echo "<div class='stat-grid'>";
    echo "<div class='stat-box'><div class='number'>$wp_pub</div><div class='label'>Posts WP publicados</div></div>";
    echo "<div class='stat-box'><div class='number'>$j_pub</div><div class='label'>Artículos Joomla</div></div>";
    echo "<div class='stat-box" . ($empty_total > 0 ? ' warn' : ' ok') . "'><div class='number'>$empty_total</div><div class='label'>Posts con poco contenido</div></div>";
    echo "<div class='stat-box" . ($recoverable > 0 ? '' : ' ok') . "'><div class='number'>$recoverable</div><div class='label'>Recuperables de Joomla</div></div>";
    echo "<div class='stat-box" . ($broken_img > 0 ? '' : ' ok') . "'><div class='number'>$broken_img</div><div class='label'>Imágenes rotas</div></div>";
    echo "<div class='stat-box ok'><div class='number'>" . count($index) . "</div><div class='label'>Archivos en joomla-images/</div></div>";
    echo "</div>";

    // Detail log
    echo "<div class='card'><h2>Detalle</h2><div class='log'>";
    lm("=== POSTS CON POCO CONTENIDO ===", 'info');
    lm("Total con <50 chars: $empty_total", 'warn');
    lm("Totalmente vacíos: $truly_empty", 'warn');
    lm("Con zoo_id (recuperables desde Joomla): $recoverable", 'info');
    lm("Sin zoo_id (no recuperables): " . ($empty_total - $recoverable), 'info');

    // Show sample of recoverable posts
    lm("", 'info');
    lm("Muestra de posts recuperables:", 'info');
    $samples = $wpdb->get_results("SELECT p.ID, p.post_title, pm.meta_value as zoo_id
        FROM $wpdb->posts p
        JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id'
        WHERE p.post_type='post' AND p.post_status='publish' AND LENGTH(p.post_content)<50
        LIMIT 10");
    foreach ($samples as $s) {
        // Check if Joomla has content
        $jr = $j->query("SELECT type, LENGTH(elements) as elen FROM jos_zoo_item WHERE id=" . intval($s->zoo_id));
        $jinfo = $jr ? $jr->fetch_assoc() : null;
        $type = $jinfo ? $jinfo['type'] : '?';
        $elen = $jinfo ? $jinfo['elen'] : 0;
        lm("  WP:{$s->ID} Zoo:{$s->zoo_id} Type:$type Elements:{$elen}b | {$s->post_title}", $type === 'author' ? 'skip' : 'warn');
    }

    lm("", 'info');
    lm("=== IMÁGENES ROTAS ===", 'info');
    lm("Posts con src=\"images/...\": $broken_img", $broken_img > 0 ? 'error' : 'success');
    lm("Archivos indexados en joomla-images/: " . count($index), 'success');

    echo "</div></div>";

    // Actions
    echo "<div class='card'><h2>Acciones</h2>";
    echo "<p style='color:#8b949e;line-height:2;'>";
    echo "<strong>1.</strong> <a href='?action=fix_content' class='btn btn-green'>Recuperar Contenido</a> Re-extrae contenido de $recoverable posts vacíos desde la base de Joomla<br><br>";
    echo "<strong>2.</strong> <a href='?action=fix_images' class='btn btn-yellow'>Fix Imágenes</a> Corrige $broken_img rutas de imágenes rotas<br><br>";
    echo "<strong>O bien:</strong> <a href='?action=fix_all' class='btn btn-green'>Fix Todo</a> Ejecuta ambos en secuencia";
    echo "</p></div>";

    pf();
}

// ============================================================
// FIX CONTENT - Re-extract from Joomla Zoo
// ============================================================
function do_fix_content($offset, $batch) {
    global $wpdb;
    page_header('Recuperar Contenido');
    $j = jdb();

    $total = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts p
        JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id'
        WHERE p.post_type='post' AND p.post_status='publish' AND LENGTH(p.post_content)<50");

    echo "<div class='card'><h2>Recuperando contenido de Joomla ($offset / $total)</h2><div class='log'>";

    $posts = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_title, pm.meta_value as zoo_id
         FROM $wpdb->posts p
         JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id'
         WHERE p.post_type='post' AND p.post_status='publish' AND LENGTH(p.post_content)<50
         ORDER BY p.ID ASC
         LIMIT %d, %d",
        $offset, $batch
    ));

    $fixed = 0;
    $skipped_author = 0;
    $skipped_empty = 0;
    $skipped_no_joomla = 0;

    foreach ($posts as $post) {
        $zoo_id = intval($post->zoo_id);

        // Get Joomla item
        $jr = $j->query("SELECT type, elements FROM jos_zoo_item WHERE id=$zoo_id");
        if (!$jr || $jr->num_rows == 0) {
            lm("No encontrado en Joomla Zoo ID:$zoo_id | {$post->post_title}", 'warn');
            $skipped_no_joomla++;
            continue;
        }
        $jrow = $jr->fetch_assoc();

        // Skip authors - they are supposed to have little/no content
        if ($jrow['type'] === 'author') {
            $skipped_author++;
            continue;
        }

        // Extract content
        $content = extract_content($jrow['elements']);
        if (empty(trim(strip_tags($content)))) {
            lm("Sin contenido en Joomla: Zoo:$zoo_id | {$post->post_title}", 'warn');
            $skipped_empty++;
            continue;
        }

        // Fix image URLs in the recovered content too
        $img_result = fix_images_in_string($content);
        $content = $img_result['html'];

        // Update WordPress post
        $wpdb->update($wpdb->posts, ['post_content' => $content], ['ID' => $post->ID]);
        clean_post_cache($post->ID);

        $img_note = $img_result['fixed'] > 0 ? " (+{$img_result['fixed']} imgs arregladas)" : '';
        lm("Recuperado: {$post->post_title} (" . strlen($content) . " chars)$img_note", 'success');
        $fixed++;
    }

    echo "</div></div>";

    echo "<div class='stat-grid'>";
    echo "<div class='stat-box ok'><div class='number'>$fixed</div><div class='label'>Contenido Recuperado</div></div>";
    echo "<div class='stat-box'><div class='number'>$skipped_author</div><div class='label'>Autores (ignorados)</div></div>";
    echo "<div class='stat-box'><div class='number'>$skipped_empty</div><div class='label'>Vacíos en Joomla</div></div>";
    echo "<div class='stat-box'><div class='number'>$skipped_no_joomla</div><div class='label'>No encontrados</div></div>";
    echo "</div>";

    // Progress and auto-continue
    $processed = $offset + count($posts);
    $pct = $total > 0 ? min(100, round(($processed / $total) * 100)) : 100;

    echo "<div class='card'><div class='progress'><div class='fill' style='width:{$pct}%'>$pct% ($processed / $total)</div></div>";

    if (count($posts) >= $batch) {
        $next = $offset + $batch;
        $url = "?action=fix_content&offset=$next&batch=$batch";
        echo "<script>setTimeout(function(){window.location.href='$url';},2000);</script>";
        echo "<a href='$url' class='btn btn-blue'>Siguiente Lote</a> ";
        echo "<a href='?action=audit' class='btn btn-gray'>Detener</a>";
    } else {
        echo "<p style='color:#7ee787;font-weight:bold'>Completado.</p>";
        echo "<a href='?action=audit' class='btn btn-blue'>Ver Auditoría</a> ";
        echo "<a href='?action=fix_images' class='btn btn-yellow'>Ahora Fix Imágenes</a>";
    }
    echo "</div>";
    pf();
}

// ============================================================
// FIX IMAGES - Replace src="images/..." with correct WP URL
// ============================================================
function do_fix_images() {
    global $wpdb;
    page_header('Fix Imágenes');

    echo "<div class='card'><h2>Corrigiendo rutas de imágenes</h2><div class='log'>";

    lm("Construyendo índice de archivos en joomla-images/...", 'info');
    $index = build_file_index();
    lm("Indexados " . count($index) . " archivos", 'success');
    lm("", 'info');

    // Get ALL posts with broken image URLs (not just published - also check drafts etc)
    $posts = $wpdb->get_results("SELECT ID, post_title, post_content FROM $wpdb->posts
        WHERE post_type='post' AND post_content LIKE '%src=\"images/%'");

    lm("Posts con src=\"images/...\": " . count($posts), 'info');
    lm("", 'info');

    $fixed_posts = 0;
    $fixed_urls = 0;
    $all_not_found = [];

    foreach ($posts as $post) {
        $result = fix_images_in_string($post->post_content);

        if ($result['fixed'] > 0) {
            $wpdb->update($wpdb->posts, ['post_content' => $result['html']], ['ID' => $post->ID]);
            clean_post_cache($post->ID);
            lm("Reparadas {$result['fixed']} imágenes: {$post->post_title} (ID:{$post->ID})", 'success');
            $fixed_posts++;
            $fixed_urls += $result['fixed'];
        }

        if (!empty($result['not_found'])) {
            foreach ($result['not_found'] as $nf) {
                $all_not_found[] = $nf;
                lm("  No encontrada: images/$nf", 'error');
            }
        }
    }

    echo "</div></div>";

    echo "<div class='stat-grid'>";
    echo "<div class='stat-box ok'><div class='number'>$fixed_posts</div><div class='label'>Posts Reparados</div></div>";
    echo "<div class='stat-box ok'><div class='number'>$fixed_urls</div><div class='label'>URLs Corregidas</div></div>";
    echo "<div class='stat-box" . (count($all_not_found) > 0 ? '' : ' ok') . "'><div class='number'>" . count($all_not_found) . "</div><div class='label'>No Encontradas</div></div>";
    echo "</div>";

    if (!empty($all_not_found)) {
        echo "<div class='card'><h2>Imágenes no encontradas en joomla-images/</h2><div class='log'>";
        $unique = array_unique($all_not_found);
        foreach ($unique as $nf) {
            lm("images/$nf", 'error');
        }
        echo "</div></div>";
    }

    // Verify
    $remaining = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status='publish' AND post_content LIKE '%src=\"images/%'");
    echo "<div class='card'>";
    if ($remaining > 0) {
        echo "<p style='color:#d29922'>Quedan $remaining posts con imágenes sin resolver (archivos no encontrados en joomla-images/).</p>";
    } else {
        echo "<p style='color:#7ee787;font-weight:bold'>Todas las imágenes han sido corregidas.</p>";
    }
    echo "<a href='?action=audit' class='btn btn-blue'>Ver Auditoría</a></div>";
    pf();
}

// ============================================================
// FIX ALL - Content first, then images
// ============================================================
function do_fix_all($offset, $batch) {
    // Phase 1: fix content (with auto-continue)
    // When content is done, it redirects to fix_images
    global $wpdb;

    $remaining_content = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts p
        JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id'
        WHERE p.post_type='post' AND p.post_status='publish' AND LENGTH(p.post_content)<50");

    if ($remaining_content > 0 || $offset > 0) {
        // Still fixing content
        do_fix_content_for_all($offset, $batch);
    } else {
        // Content done, fix images
        do_fix_images();
    }
}

function do_fix_content_for_all($offset, $batch) {
    global $wpdb;
    page_header('Fix Todo - Fase 1: Contenido');
    $j = jdb();

    $total = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts p
        JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id'
        WHERE p.post_type='post' AND p.post_status='publish' AND LENGTH(p.post_content)<50");

    echo "<div class='card'><h2>Fase 1: Recuperando contenido ($offset / $total)</h2><div class='log'>";

    $posts = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_title, pm.meta_value as zoo_id
         FROM $wpdb->posts p
         JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id'
         WHERE p.post_type='post' AND p.post_status='publish' AND LENGTH(p.post_content)<50
         ORDER BY p.ID ASC
         LIMIT %d, %d",
        $offset, $batch
    ));

    $fixed = 0;

    foreach ($posts as $post) {
        $zoo_id = intval($post->zoo_id);
        $jr = $j->query("SELECT type, elements FROM jos_zoo_item WHERE id=$zoo_id");
        if (!$jr || $jr->num_rows == 0) continue;
        $jrow = $jr->fetch_assoc();
        if ($jrow['type'] === 'author') continue;

        $content = extract_content($jrow['elements']);
        if (empty(trim(strip_tags($content)))) continue;

        $img_result = fix_images_in_string($content);
        $content = $img_result['html'];

        $wpdb->update($wpdb->posts, ['post_content' => $content], ['ID' => $post->ID]);
        clean_post_cache($post->ID);
        lm("Recuperado: {$post->post_title} (" . strlen($content) . " chars)", 'success');
        $fixed++;
    }

    echo "</div></div>";

    $processed = $offset + count($posts);
    $pct = $total > 0 ? min(100, round(($processed / $total) * 100)) : 100;
    echo "<div class='card'><div class='progress'><div class='fill' style='width:{$pct}%'>Contenido: $pct%</div></div>";

    if (count($posts) >= $batch) {
        $next = $offset + $batch;
        $url = "?action=fix_all&offset=$next&batch=$batch";
        echo "<script>setTimeout(function(){window.location.href='$url';},2000);</script>";
        echo "<p>Procesados $fixed en este lote. Continuando...</p>";
        echo "<a href='?action=audit' class='btn btn-gray'>Detener</a>";
    } else {
        // Content phase done, redirect to images
        echo "<p style='color:#7ee787'>Fase 1 completada. Pasando a imágenes...</p>";
        echo "<script>setTimeout(function(){window.location.href='?action=fix_images';},2000);</script>";
        echo "<a href='?action=fix_images' class='btn btn-yellow'>Ir a Fix Imágenes</a>";
    }
    echo "</div>";
    pf();
}

// ============================================================
// ROUTER
// ============================================================
switch ($action) {
    case 'audit':       do_audit(); break;
    case 'fix_content': do_fix_content($offset, $batch); break;
    case 'fix_images':  do_fix_images(); break;
    case 'fix_all':     do_fix_all($offset, $batch); break;
    default:            do_audit();
}
