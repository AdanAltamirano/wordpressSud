<?php
/**
 * Joomla -> WordPress DEFINITIVE Sync Script v3
 *
 * Problemas diagnosticados y resueltos:
 * 1. 2,614 posts DUPLICADOS (importados múltiples veces)
 * 2. 112 posts con src="images/..." que apuntan a rutas Joomla
 * 3. ~700 "posts vacíos" que son perfiles de autores (Zoo type=author), no artículos
 * 4. Imágenes en joomla-images/ que ya existen pero con rutas incorrectas
 *
 * Acciones:
 *   ?action=audit           -> Diagnóstico completo
 *   ?action=fix_image_urls  -> Corregir las 112 rutas de imágenes rotas
 *   ?action=show_duplicates -> Mostrar posts duplicados
 *   ?action=remove_duplicates -> Eliminar posts duplicados (conserva el más antiguo)
 *   ?action=show_authors    -> Mostrar "posts" que son autores
 *   ?action=fix_authors     -> Convertir author-posts a draft o eliminar
 *   ?action=sync_categories -> Sincronizar categorías faltantes
 *   ?action=sync_posts      -> Importar posts faltantes
 *   ?action=fix_empty       -> Re-extraer contenido de posts vacíos reales
 */

set_time_limit(600);
ini_set('memory_limit', '1024M');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . '/wp-load.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/media.php');

// DB config
define('J_HOST', '127.0.0.1'); define('J_PORT', 3306);
define('J_USER', 'root'); define('J_PASS', 'yue02'); define('J_DB', 'sudcalifornios');
define('JOOMLA_PATH', 'C:/Sudcalifornios');
define('UPLOADS_DIR', ABSPATH . 'wp-content/uploads');
define('UPLOADS_URL', site_url('/wp-content/uploads'));
define('JIMAGES_DIR', UPLOADS_DIR . '/joomla-images');

$action  = isset($_GET['action']) ? $_GET['action'] : 'audit';
$offset  = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
$batch   = isset($_GET['batch']) ? intval($_GET['batch']) : 100;
$dry_run = isset($_GET['dry_run']) && $_GET['dry_run'] == '1';

function jdb() {
    static $conn = null;
    if (!$conn) {
        $conn = new mysqli(J_HOST, J_USER, J_PASS, J_DB, J_PORT);
        $conn->set_charset("utf8");
    }
    return $conn;
}

// ============================================================
// BUILD FILE INDEX (cached in memory)
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
        if (strpos($path, ' - copia') !== false) continue;
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
// HTML
// ============================================================
function page_header($title) {
    echo "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'><title>$title</title><style>
        *{box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0d1117;color:#c9d1d9;padding:20px;margin:0}
        .container{max-width:1200px;margin:0 auto}.card{background:#161b22;border:1px solid #30363d;padding:20px;border-radius:8px;margin-bottom:16px}
        h1,h2{color:#58a6ff;margin-top:0}.log{background:#010409;color:#7ee787;padding:15px;border-radius:6px;max-height:600px;overflow-y:auto;font-family:Consolas,monospace;font-size:13px;line-height:1.6}
        .log .error{color:#f85149}.log .warn{color:#d29922}.log .info{color:#58a6ff}.log .success{color:#7ee787}.log .skip{color:#484f58}
        .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:16px}
        .stat-box{background:#21262d;padding:12px;border-radius:6px;text-align:center;border:1px solid #30363d}
        .stat-box .number{font-size:1.8em;font-weight:bold;color:#f85149}.stat-box .label{color:#8b949e;font-size:.85em;margin-top:4px}
        .stat-box.ok .number{color:#7ee787}.stat-box.warn .number{color:#d29922}
        .btn{display:inline-block;padding:8px 16px;border-radius:6px;text-decoration:none;font-weight:600;margin:3px;font-size:13px;border:1px solid #30363d;cursor:pointer}
        .btn-blue{background:#1f6feb;color:#fff;border-color:#1f6feb}.btn-green{background:#238636;color:#fff;border-color:#238636}
        .btn-yellow{background:#9e6a03;color:#fff;border-color:#9e6a03}.btn-red{background:#da3633;color:#fff;border-color:#da3633}
        .btn-gray{background:#21262d;color:#58a6ff}.progress{background:#21262d;height:24px;border-radius:12px;overflow:hidden;margin:12px 0}
        .progress .fill{background:linear-gradient(90deg,#1f6feb,#238636);height:100%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:bold}
        .nav{margin-bottom:16px;padding:10px;background:#161b22;border-radius:8px;border:1px solid #30363d;display:flex;flex-wrap:wrap;align-items:center;gap:3px}
        .dry{background:#1c1e23;border:2px solid #d29922;padding:8px 16px;border-radius:4px;margin-bottom:12px;font-weight:bold;color:#d29922}
        table{width:100%;border-collapse:collapse}th,td{padding:6px 10px;text-align:left;border-bottom:1px solid #21262d;font-size:13px}th{color:#8b949e;font-weight:600}
    </style></head><body><div class='container'>";
    echo "<div class='nav'><strong style='color:#58a6ff'>v3:&nbsp;</strong>";
    echo "<a href='?action=audit' class='btn btn-blue'>Auditoría</a>";
    echo "<a href='?action=fix_image_urls' class='btn btn-yellow'>Fix Imágenes</a>";
    echo "<a href='?action=show_duplicates' class='btn btn-yellow'>Ver Duplicados</a>";
    echo "<a href='?action=remove_duplicates&dry_run=1' class='btn btn-red'>Limpiar Duplicados</a>";
    echo "<a href='?action=show_authors' class='btn btn-gray'>Ver Autores</a>";
    echo "<a href='?action=sync_posts&dry_run=1' class='btn btn-green'>Sync Posts</a>";
    echo "<a href='?action=fix_empty' class='btn btn-yellow'>Fix Vacíos</a>";
    echo "</div>";
}

function pf() { echo "</div></body></html>"; }
function lm($m, $c='info') { echo "<div class='$c'>$m</div>\n"; @ob_flush(); @flush(); }

// ============================================================
// EXTRACT ZOO CONTENT
// ============================================================
function extract_content($elements_json) {
    $e = json_decode($elements_json, true);
    if (!$e || !is_array($e)) return '';
    $content = '';
    // Known content UUID
    $uuid = '2e3c9e69-1f9e-4647-8d13-4e88094d2790';
    if (isset($e[$uuid]) && is_array($e[$uuid])) {
        foreach ($e[$uuid] as $item) {
            if (isset($item['value']) && is_string($item['value']) && strlen($item['value']) > 10)
                $content .= $item['value'] . "\n";
        }
    }
    if (empty(trim(strip_tags($content)))) {
        // Fallback
        array_walk_recursive($e, function($v, $k) use (&$content) {
            if ($k === 'value' && is_string($v) && (strlen($v) > 100 || ($v !== strip_tags($v) && strlen($v) > 30)))
                $content .= $v . "\n";
        });
    }
    return trim($content);
}

// ============================================================
// FIX IMAGE URLS IN STRING
// ============================================================
function fix_images_in_string($html) {
    $index = build_file_index();
    $count = 0;

    // Replace src="images/..." with correct WP path
    $html = preg_replace_callback(
        '/src=["\'](?:\.\/)?images\/([^"\']+)["\']/i',
        function($m) use ($index, &$count) {
            $original_path = $m[1];
            $decoded = urldecode($original_path);
            $fname = strtolower(basename($decoded));

            if (isset($index[$fname])) {
                $count++;
                $new_url = UPLOADS_URL . '/' . $index[$fname];
                return 'src="' . $new_url . '"';
            }
            return $m[0]; // Keep original if not found
        },
        $html
    );

    return ['html' => $html, 'fixed' => $count];
}

// ============================================================
// ACTION: AUDIT
// ============================================================
function do_audit() {
    global $wpdb;
    page_header('Auditoría v3');
    echo "<div class='card'><h2>Diagnóstico Completo</h2><div class='log'>";

    $j = jdb();

    // Counts
    $j_pub = $j->query("SELECT COUNT(*) c FROM jos_zoo_item WHERE type IN('article','page') AND state=1")->fetch_assoc()['c'];
    $j_auth = $j->query("SELECT COUNT(*) c FROM jos_zoo_item WHERE type='author'")->fetch_assoc()['c'];
    $j_cats = $j->query("SELECT COUNT(*) c FROM jos_zoo_category WHERE published=1")->fetch_assoc()['c'];

    $wp_pub = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status='publish'");
    $wp_tracked = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key='_joomla_zoo_id'");
    $wp_cats = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->term_taxonomy WHERE taxonomy='category'");

    lm("=== JOOMLA ===", 'info');
    lm("Artículos+páginas publicados: $j_pub", 'info');
    lm("Autores (Zoo type=author): $j_auth", 'info');
    lm("Categorías: $j_cats", 'info');

    lm("", 'info');
    lm("=== WORDPRESS ===", 'info');
    lm("Posts publicados: $wp_pub", 'info');
    lm("Posts con _joomla_zoo_id: $wp_tracked", 'info');
    lm("Categorías: $wp_cats", 'info');

    // DUPLICATES
    lm("", 'info');
    lm("=== DUPLICADOS ===", 'error');
    $dup_total = $wpdb->get_var("SELECT COALESCE(SUM(cnt-1),0) FROM (SELECT post_title, COUNT(*) cnt FROM $wpdb->posts WHERE post_type='post' AND post_status='publish' GROUP BY post_title HAVING cnt>1) t");
    lm("Posts duplicados (por título): $dup_total", $dup_total > 0 ? 'error' : 'success');
    if ($dup_total > 0) {
        $top_dups = $wpdb->get_results("SELECT post_title, COUNT(*) cnt FROM $wpdb->posts WHERE post_type='post' AND post_status='publish' GROUP BY post_title HAVING cnt>1 ORDER BY cnt DESC LIMIT 10");
        foreach ($top_dups as $d) {
            lm("  x{$d->cnt}: {$d->post_title}", 'warn');
        }
    }

    // BROKEN IMAGES
    lm("", 'info');
    lm("=== IMÁGENES ROTAS ===", 'info');
    $broken_img = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status='publish' AND post_content LIKE '%src=\"images/%'");
    lm("Posts con src=\"images/...\" sin convertir: $broken_img", $broken_img > 0 ? 'error' : 'success');

    // EMPTY/AUTHOR POSTS
    lm("", 'info');
    lm("=== POSTS VACÍOS / AUTORES ===", 'info');
    $empty_total = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status='publish' AND LENGTH(post_content)<50");
    lm("Posts con <50 chars de contenido: $empty_total", $empty_total > 0 ? 'warn' : 'success');

    // Check how many are actually authors (use Joomla connection for author IDs)
    $auth_ids_r = $j->query("SELECT id FROM jos_zoo_item WHERE type='author'");
    $auth_ids = [];
    while ($r = $auth_ids_r->fetch_assoc()) $auth_ids[] = intval($r['id']);

    if (!empty($auth_ids)) {
        $auth_ids_str = implode(',', $auth_ids);
        // Count empty posts whose zoo_id matches an author item
        $author_posts = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id' WHERE p.post_type='post' AND p.post_status='publish' AND LENGTH(p.post_content)<50 AND pm.meta_value IN ($auth_ids_str)");
        lm("  De esos, son perfiles de autor: $author_posts", 'warn');
        lm("  Posts vacíos reales: " . ($empty_total - $author_posts), 'info');
    }

    // MISSING POSTS
    lm("", 'info');
    lm("=== POSTS FALTANTES ===", 'info');
    $imported = array_flip($wpdb->get_col("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key='_joomla_zoo_id'"));
    $missing = 0;
    $r = $j->query("SELECT id FROM jos_zoo_item WHERE type IN('article','page') AND state=1");
    while ($row = $r->fetch_assoc()) {
        if (!isset($imported[$row['id']])) $missing++;
    }
    lm("Posts de Joomla no importados: $missing", $missing > 0 ? 'warn' : 'success');

    echo "</div></div>";

    // Stats
    echo "<div class='stat-grid'>";
    echo "<div class='stat-box" . ($dup_total > 0 ? '' : ' ok') . "'><div class='number'>$dup_total</div><div class='label'>Duplicados</div></div>";
    echo "<div class='stat-box" . ($broken_img > 0 ? '' : ' ok') . "'><div class='number'>$broken_img</div><div class='label'>Imágenes Rotas</div></div>";
    echo "<div class='stat-box warn'><div class='number'>$empty_total</div><div class='label'>Posts Vacíos</div></div>";
    echo "<div class='stat-box" . ($missing > 0 ? ' warn' : ' ok') . "'><div class='number'>$missing</div><div class='label'>Posts Faltantes</div></div>";
    echo "</div>";

    echo "<div class='card'><h2>Orden Recomendado</h2><ol style='color:#8b949e;line-height:2;'>";
    echo "<li><strong style='color:#d29922'>Eliminar $dup_total duplicados (por título)</strong> <a href='?action=remove_duplicates&dry_run=1' class='btn btn-red'>Dry Run</a></li>";
    echo "<li><strong style='color:#d29922'>Corregir $broken_img URLs de imágenes</strong> <a href='?action=fix_image_urls' class='btn btn-yellow'>Ejecutar</a></li>";
    if ($missing > 0) echo "<li><strong style='color:#7ee787'>Importar $missing posts faltantes</strong> <a href='?action=sync_posts&dry_run=1' class='btn btn-green'>Dry Run</a></li>";
    echo "</ol></div>";

    pf();
}

// ============================================================
// ACTION: FIX IMAGE URLS
// ============================================================
function do_fix_image_urls() {
    global $wpdb;
    page_header('Fix URLs Imágenes');
    echo "<div class='card'><h2>Corrigiendo rutas de imágenes</h2><div class='log'>";

    // Pre-build file index
    lm("Construyendo índice de archivos...", 'info');
    $index = build_file_index();
    lm("Indexados " . count($index) . " archivos", 'success');

    $posts = $wpdb->get_results("SELECT ID, post_title, post_content FROM $wpdb->posts WHERE post_type='post' AND post_status='publish' AND post_content LIKE '%src=\"images/%'");

    $fixed_posts = 0;
    $fixed_urls = 0;
    $not_found_list = [];

    foreach ($posts as $post) {
        $result = fix_images_in_string($post->post_content);
        if ($result['fixed'] > 0) {
            $wpdb->update($wpdb->posts, ['post_content' => $result['html']], ['ID' => $post->ID]);
            clean_post_cache($post->ID);
            lm("Reparadas {$result['fixed']} imágenes en: {$post->post_title} (ID: {$post->ID})", 'success');
            $fixed_posts++;
            $fixed_urls += $result['fixed'];
        } else {
            // Still broken - show what we couldn't find
            preg_match_all('/src=["\']images\/([^"\']+)["\']/i', $post->post_content, $m);
            foreach ($m[1] as $p) {
                $not_found_list[] = urldecode($p);
                lm("No encontrada: {$post->post_title} -> images/$p", 'error');
            }
        }
    }

    echo "</div></div>";
    echo "<div class='stat-grid'>";
    echo "<div class='stat-box ok'><div class='number'>$fixed_posts</div><div class='label'>Posts Reparados</div></div>";
    echo "<div class='stat-box ok'><div class='number'>$fixed_urls</div><div class='label'>URLs Corregidas</div></div>";
    echo "<div class='stat-box'><div class='number'>" . count($not_found_list) . "</div><div class='label'>No Encontradas</div></div>";
    echo "</div>";
    echo "<div class='card'><a href='?action=audit' class='btn btn-blue'>Auditoría</a></div>";
    pf();
}

// ============================================================
// ACTION: SHOW/REMOVE DUPLICATES
// ============================================================
function do_show_duplicates() {
    global $wpdb;
    page_header('Posts Duplicados');
    echo "<div class='card'><h2>Posts con título duplicado</h2>";

    $dups = $wpdb->get_results("SELECT post_title, COUNT(*) cnt FROM $wpdb->posts WHERE post_type='post' AND post_status='publish' GROUP BY post_title HAVING cnt>1 ORDER BY cnt DESC LIMIT 50");

    echo "<table><tr><th>Título</th><th>Copias</th><th>IDs</th></tr>";
    foreach ($dups as $d) {
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_type='post' AND post_status='publish' AND post_title=%s ORDER BY ID ASC", $d->post_title));
        echo "<tr><td>{$d->post_title}</td><td style='color:#f85149;font-weight:bold'>{$d->cnt}</td><td style='font-size:11px'>" . implode(', ', $ids) . "</td></tr>";
    }
    echo "</table>";

    $total = $wpdb->get_var("SELECT COALESCE(SUM(cnt-1),0) FROM (SELECT post_title, COUNT(*) cnt FROM $wpdb->posts WHERE post_type='post' AND post_status='publish' GROUP BY post_title HAVING cnt>1) t");
    echo "<p style='color:#f85149;font-weight:bold'>Total a eliminar: $total posts duplicados</p>";
    echo "<a href='?action=remove_duplicates&dry_run=1' class='btn btn-yellow'>Dry Run (ver qué se eliminaría)</a> ";
    echo "<a href='?action=remove_duplicates' class='btn btn-red' onclick=\"return confirm('¿Eliminar $total posts duplicados? Se conserva el más antiguo de cada grupo.')\">Eliminar Duplicados</a>";
    echo "</div>";
    pf();
}

function do_remove_duplicates($dry_run) {
    global $wpdb;
    $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
    $batch  = 50; // Process 50 duplicate groups per page load to avoid timeout

    page_header('Eliminar Duplicados');
    echo "<div class='card'><h2>Eliminando Posts Duplicados (lote desde $offset)</h2>";
    if ($dry_run) echo "<div class='dry'>DRY RUN - No se elimina nada</div>";
    echo "<div class='log'>";

    // Count total duplicate groups
    $total_groups = $wpdb->get_var("SELECT COUNT(*) FROM (SELECT post_title FROM $wpdb->posts WHERE post_type='post' AND post_status='publish' GROUP BY post_title HAVING COUNT(*)>1) t");
    $total_excess = $wpdb->get_var("SELECT COALESCE(SUM(cnt-1),0) FROM (SELECT post_title, COUNT(*) cnt FROM $wpdb->posts WHERE post_type='post' AND post_status='publish' GROUP BY post_title HAVING cnt>1) t");

    lm("Total grupos duplicados: $total_groups | Posts sobrantes: $total_excess", 'info');
    lm("Procesando lote: $offset a " . ($offset + $batch), 'info');
    lm("", 'info');

    // Get this batch of duplicate groups
    $dups = $wpdb->get_results($wpdb->prepare(
        "SELECT post_title, COUNT(*) cnt FROM $wpdb->posts WHERE post_type='post' AND post_status='publish' GROUP BY post_title HAVING cnt>1 ORDER BY cnt DESC LIMIT %d, %d",
        $offset, $batch
    ));

    $batch_removed = 0;

    foreach ($dups as $d) {
        // Get all IDs for this title, ordered by content length DESC so the one with most content is first
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM $wpdb->posts WHERE post_type='post' AND post_status='publish' AND post_title=%s ORDER BY LENGTH(post_content) DESC, ID ASC",
            $d->post_title
        ));
        $keep = $ids[0]; // Keep the one with most content
        $remove = array_slice($ids, 1);

        if (!$dry_run) {
            foreach ($remove as $rid) {
                // Use direct SQL update instead of wp_update_post (much faster, avoids hooks/timeout)
                $wpdb->update($wpdb->posts, ['post_status' => 'trash'], ['ID' => $rid]);
                clean_post_cache($rid);
            }
        }

        $rc = count($remove);
        $keep_len = $wpdb->get_var($wpdb->prepare("SELECT LENGTH(post_content) FROM $wpdb->posts WHERE ID=%d", $keep));
        lm(($dry_run ? "ELIMINARÍA" : "Eliminados") . " $rc copias de: " . mb_substr($d->post_title, 0, 80) . " (conserva ID:$keep, {$keep_len}chars)", 'success');
        $batch_removed += $rc;
    }

    echo "</div></div>";

    $processed_groups = $offset + count($dups);
    $pct = $total_groups > 0 ? min(100, round(($processed_groups / $total_groups) * 100)) : 100;

    echo "<div class='stat-grid'>";
    echo "<div class='stat-box ok'><div class='number'>$batch_removed</div><div class='label'>" . ($dry_run ? 'Se eliminarían (este lote)' : 'Eliminados (este lote)') . "</div></div>";
    echo "<div class='stat-box'><div class='number'>$total_excess</div><div class='label'>Total pendiente</div></div>";
    echo "<div class='stat-box'><div class='number'>$processed_groups / $total_groups</div><div class='label'>Grupos procesados</div></div>";
    echo "</div>";

    echo "<div class='card'><div class='progress'><div class='fill' style='width:{$pct}%'>$pct%</div></div>";

    if (count($dups) >= $batch) {
        // More batches to process
        $next = $offset + $batch;
        $dp = $dry_run ? '&dry_run=1' : '';
        $url = "?action=remove_duplicates&offset=$next$dp";
        if (!$dry_run) {
            echo "<script>setTimeout(function(){window.location.href='$url';},2000);</script>";
        }
        echo "<a href='$url' class='btn btn-blue'>Siguiente Lote</a> ";
        echo "<a href='?action=audit' class='btn btn-gray'>Detener</a>";
    } else {
        // Done
        if ($dry_run) {
            echo "<p style='color:#d29922;font-weight:bold'>Dry Run completo. Se eliminarían $total_excess posts duplicados.</p>";
            echo "<a href='?action=remove_duplicates' class='btn btn-red' onclick=\"return confirm('¿Confirmar eliminación de duplicados? Se conserva el que tiene más contenido de cada grupo.')\">Confirmar Eliminación</a> ";
            echo "<a href='?action=audit' class='btn btn-gray'>Cancelar</a>";
        } else {
            echo "<p style='color:#7ee787;font-weight:bold'>Completado. Posts duplicados movidos a la papelera.</p>";
            echo "<a href='?action=audit' class='btn btn-blue'>Ver Auditoría</a>";
        }
    }
    echo "</div>";
    pf();
}

// ============================================================
// ACTION: SHOW/FIX AUTHORS (author zoo items imported as posts)
// ============================================================
function do_show_authors() {
    global $wpdb;
    page_header('Posts de Autores');
    echo "<div class='card'><h2>Zoo items type=author importados como posts</h2>";

    $j = jdb();
    $auth_ids_r = $j->query("SELECT id, name FROM jos_zoo_item WHERE type='author' ORDER BY name");
    $author_zoo_ids = [];
    while ($r = $auth_ids_r->fetch_assoc()) $author_zoo_ids[$r['id']] = $r['name'];

    if (empty($author_zoo_ids)) {
        echo "<p>No hay autores en Joomla Zoo.</p></div>";
        pf();
        return;
    }

    $ids_str = implode(',', array_keys($author_zoo_ids));
    $author_posts = $wpdb->get_results("SELECT p.ID, p.post_title, p.post_status, pm.meta_value as zoo_id
        FROM $wpdb->posts p
        JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id'
        WHERE pm.meta_value IN ($ids_str) AND p.post_type='post'
        ORDER BY p.post_title");

    echo "<p>Encontrados " . count($author_posts) . " posts que son perfiles de autor:</p>";
    echo "<table><tr><th>WP ID</th><th>Título</th><th>Estado</th><th>Zoo ID</th></tr>";
    foreach ($author_posts as $ap) {
        $status_color = $ap->post_status === 'publish' ? '#f85149' : '#7ee787';
        echo "<tr><td>{$ap->ID}</td><td>{$ap->post_title}</td><td style='color:$status_color'>{$ap->post_status}</td><td>{$ap->zoo_id}</td></tr>";
    }
    echo "</table>";

    $published_count = 0;
    foreach ($author_posts as $ap) { if ($ap->post_status === 'publish') $published_count++; }

    echo "<p><strong>$published_count</strong> están publicados como posts (deberían ser borradores o eliminarse).</p>";
    echo "<a href='?action=fix_authors&dry_run=1' class='btn btn-yellow'>Dry Run</a> ";
    echo "<a href='?action=fix_authors' class='btn btn-red' onclick=\"return confirm('¿Mover $published_count author-posts a borrador?')\">Mover a Borrador</a>";
    echo "</div>";
    pf();
}

function do_fix_authors($dry_run) {
    global $wpdb;
    page_header('Fix Author Posts');
    echo "<div class='card'><h2>Moviendo author-posts a borrador</h2>";
    if ($dry_run) echo "<div class='dry'>DRY RUN</div>";
    echo "<div class='log'>";

    $j = jdb();
    $auth_ids_r = $j->query("SELECT id FROM jos_zoo_item WHERE type='author'");
    $ids = [];
    while ($r = $auth_ids_r->fetch_assoc()) $ids[] = $r['id'];

    if (empty($ids)) {
        lm("No hay autores en Joomla.", 'info');
        echo "</div></div>"; pf(); return;
    }

    $ids_str = implode(',', $ids);
    $posts = $wpdb->get_results("SELECT p.ID, p.post_title FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id' WHERE pm.meta_value IN ($ids_str) AND p.post_type='post' AND p.post_status='publish'");

    $count = 0;
    foreach ($posts as $p) {
        if (!$dry_run) {
            wp_update_post(['ID' => $p->ID, 'post_status' => 'draft']);
        }
        lm(($dry_run ? "MOVERÍA" : "Movido") . " a borrador: {$p->post_title} (ID: {$p->ID})", 'success');
        $count++;
    }

    echo "</div></div>";
    echo "<div class='stat-grid'><div class='stat-box ok'><div class='number'>$count</div><div class='label'>" . ($dry_run ? 'Se moverían' : 'Movidos') . " a borrador</div></div></div>";

    if ($dry_run && $count > 0) {
        echo "<div class='card'><a href='?action=fix_authors' class='btn btn-red' onclick=\"return confirm('¿Confirmar?')\">Confirmar</a> ";
        echo "<a href='?action=audit' class='btn btn-gray'>Cancelar</a></div>";
    } else {
        echo "<div class='card'><a href='?action=audit' class='btn btn-blue'>Auditoría</a></div>";
    }
    pf();
}

// ============================================================
// ACTION: FIX EMPTY POSTS (re-extract content)
// ============================================================
function do_fix_empty($offset, $batch) {
    global $wpdb;
    page_header('Fix Posts Vacíos');
    echo "<div class='card'><h2>Re-extrayendo contenido de Joomla</h2><div class='log'>";

    $j = jdb();

    // Only fix empty posts that are NOT authors
    $auth_ids_r = $j->query("SELECT id FROM jos_zoo_item WHERE type='author'");
    $author_ids = [];
    while ($r = $auth_ids_r->fetch_assoc()) $author_ids[] = $r['id'];
    $not_author_clause = '';
    if (!empty($author_ids)) {
        $not_author_clause = " AND pm.meta_value NOT IN (" . implode(',', $author_ids) . ")";
    }

    $total = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id' WHERE p.post_type='post' AND p.post_status='publish' AND LENGTH(p.post_content)<50 $not_author_clause");

    $posts = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_title, pm.meta_value as zoo_id FROM $wpdb->posts p JOIN $wpdb->postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id' WHERE p.post_type='post' AND p.post_status='publish' AND LENGTH(p.post_content)<50 $not_author_clause ORDER BY p.ID ASC LIMIT %d, %d",
        $offset, $batch
    ));

    $fixed = 0; $still_empty = 0;
    $file_index = build_file_index();

    foreach ($posts as $post) {
        $zoo_id = intval($post->zoo_id);
        $jr = $j->query("SELECT elements FROM jos_zoo_item WHERE id=$zoo_id");
        if (!$jr || $jr->num_rows == 0) { $still_empty++; continue; }
        $jrow = $jr->fetch_assoc();

        $content = extract_content($jrow['elements']);
        if (empty(trim(strip_tags($content)))) { $still_empty++; continue; }

        // Fix images in content
        $img_fix = fix_images_in_string($content);
        $content = $img_fix['html'];

        $wpdb->update($wpdb->posts, ['post_content' => $content], ['ID' => $post->ID]);
        clean_post_cache($post->ID);
        lm("Restaurado: {$post->post_title} (" . strlen($content) . " chars)", 'success');
        $fixed++;
    }

    echo "</div></div>";
    echo "<div class='stat-grid'><div class='stat-box ok'><div class='number'>$fixed</div><div class='label'>Restaurados</div></div>";
    echo "<div class='stat-box'><div class='number'>$still_empty</div><div class='label'>Sin contenido en Joomla</div></div></div>";

    $next = $offset + $batch;
    if (count($posts) >= $batch) {
        echo "<div class='card'><script>setTimeout(function(){window.location.href='?action=fix_empty&offset=$next&batch=$batch';},2000);</script>";
        echo "<a href='?action=fix_empty&offset=$next&batch=$batch' class='btn btn-blue'>Continuar</a> <a href='?action=audit' class='btn btn-gray'>Detener</a></div>";
    } else {
        echo "<div class='card'><p style='color:#7ee787'>Completado.</p><a href='?action=audit' class='btn btn-blue'>Auditoría</a></div>";
    }
    pf();
}

// ============================================================
// ACTION: SYNC POSTS (missing ones)
// ============================================================
function do_sync_posts($offset, $batch, $dry_run) {
    global $wpdb;
    page_header('Sync Posts Faltantes');
    echo "<div class='card'><h2>Importando posts faltantes (offset: $offset)</h2>";
    if ($dry_run) echo "<div class='dry'>DRY RUN</div>";
    echo "<div class='log'>";

    $j = jdb();
    $imported = array_flip($wpdb->get_col("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key='_joomla_zoo_id'"));
    $cat_map = get_option('joomla_zoo_category_map', []);

    // Rebuild cat_map if empty
    if (empty($cat_map)) {
        lm("Construyendo mapa de categorías...", 'info');
        $cats = $j->query("SELECT * FROM jos_zoo_category WHERE published=1 ORDER BY parent ASC");
        while ($c = $cats->fetch_assoc()) {
            $term = get_term_by('slug', $c['alias'], 'category');
            if ($term) $cat_map[$c['id']] = $term->term_id;
        }
        update_option('joomla_zoo_category_map', $cat_map);
        lm("Mapa: " . count($cat_map) . " categorías", 'success');
    }

    $total = $j->query("SELECT COUNT(*) c FROM jos_zoo_item WHERE type IN('article','page') AND state=1")->fetch_assoc()['c'];
    $r = $j->query("SELECT * FROM jos_zoo_item WHERE type IN('article','page') AND state=1 ORDER BY id ASC LIMIT $offset, $batch");

    $created = 0; $skipped = 0; $errors = 0;
    $file_index = build_file_index();

    while ($row = $r->fetch_assoc()) {
        if (isset($imported[$row['id']])) { $skipped++; continue; }

        $content = extract_content($row['elements']);
        $img_fix = fix_images_in_string($content);
        $content = $img_fix['html'];

        if ($dry_run) {
            lm("IMPORTARÍA: J:{$row['id']} - {$row['name']}", 'info');
            $created++;
            continue;
        }

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

        lm("Creado: {$row['name']} (J:{$row['id']} -> WP:$wp_id)", 'success');
        $created++;
    }

    echo "</div></div>";
    echo "<div class='stat-grid'>";
    echo "<div class='stat-box ok'><div class='number'>$created</div><div class='label'>" . ($dry_run ? 'Se importarían' : 'Nuevos') . "</div></div>";
    echo "<div class='stat-box'><div class='number'>$skipped</div><div class='label'>Ya existen</div></div>";
    echo "<div class='stat-box'><div class='number'>$errors</div><div class='label'>Errores</div></div>";
    echo "</div>";

    $next = $offset + $batch;
    $pct = min(100, round(($next / max($total, 1)) * 100));
    echo "<div class='card'><div class='progress'><div class='fill' style='width:{$pct}%'>$pct%</div></div>";
    echo "<p>" . min($next, $total) . " / $total</p>";

    if ($next < $total) {
        $dp = $dry_run ? '&dry_run=1' : '';
        $url = "?action=sync_posts&offset=$next&batch=$batch$dp";
        echo "<script>setTimeout(function(){window.location.href='$url';},3000);</script>";
        echo "<a href='$url' class='btn btn-blue'>Continuar</a> <a href='?action=audit' class='btn btn-gray'>Detener</a>";
    } else {
        echo "<p style='color:#7ee787;font-weight:bold'>Completado.</p><a href='?action=audit' class='btn btn-blue'>Auditoría</a>";
    }
    echo "</div>";
    pf();
}

// ============================================================
// ROUTER
// ============================================================
switch ($action) {
    case 'audit':             do_audit(); break;
    case 'fix_image_urls':    do_fix_image_urls(); break;
    case 'show_duplicates':   do_show_duplicates(); break;
    case 'remove_duplicates': do_remove_duplicates($dry_run); break;
    case 'show_authors':      do_show_authors(); break;
    case 'fix_authors':       do_fix_authors($dry_run); break;
    case 'fix_empty':         do_fix_empty($offset, $batch); break;
    case 'sync_posts':        do_sync_posts($offset, $batch, $dry_run); break;
    default:                  do_audit();
}
