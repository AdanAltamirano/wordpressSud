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
ini_set('pcre.backtrack_limit', '10000000');
error_reporting(E_ALL);

require_once(__DIR__ . '/wp-load.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/media.php');

// Load shared functions and config
require_once(__DIR__ . '/migration-functions.php');

$action = isset($_GET['action']) ? $_GET['action'] : 'audit';
$offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
$batch  = isset($_GET['batch'])  ? intval($_GET['batch'])  : 50;
$step   = isset($_GET['step'])   ? $_GET['step']           : '';

// ============================================================
// AUDIT
// ============================================================
function do_audit() {
    global $wpdb;
    page_header('Auditoría Definitiva');
    $j = jdb();

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

    echo "<div class='nav'><strong style='color:#58a6ff;font-size:15px'>Fix Definitivo&nbsp;&nbsp;</strong>";
    echo "<a href='?action=audit' class='btn btn-blue'>Auditoría</a>";
    echo "<a href='?action=dedup' class='btn btn-red'>1. Dedup</a>";
    echo "</div>";

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
        $b64_r = fix_base64_in_string($content, $post->ID, true); // Set featured image
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

    // TARGETED FIX FOR PERRO (Zoo ID 25436) to ensure it appears in grid with image
    if ($offset == 0) {
        $perro_zoo_id = 25436;
        $perro_post_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_joomla_zoo_id' AND meta_value=%s LIMIT 1", $perro_zoo_id));

        if ($perro_post_id) {
            $p = get_post($perro_post_id);
            $has_thumb = has_post_thumbnail($perro_post_id);
            $has_b64 = strpos($p->post_content, 'data:image') !== false;
            $has_imported = strpos($p->post_content, 'imported-content') !== false;

            // If in trash, or missing image, or missing thumbnail -> FORCE RECOVER
            if ($p && ($p->post_status === 'trash' || (!$has_b64 && !$has_imported) || !$has_thumb)) {
                lm("FORCE FIX: 'Perro' post (ID:$perro_post_id) found. Status:{$p->post_status}, Thumb:" . ($has_thumb?'Yes':'NO'), 'warn');

                // 1. Untrash if needed
                if ($p->post_status === 'trash') {
                    $wpdb->update($wpdb->posts, ['post_status' => 'publish'], ['ID' => $perro_post_id]);
                    clean_post_cache($perro_post_id);
                    lm("  -> Restored from Trash to Publish", 'success');
                }

                // 2. Re-fetch content from Joomla to get the image
                $jr = $j->query("SELECT elements FROM jos_zoo_item WHERE id=$perro_zoo_id AND elements LIKE '%2e3c9e69-1f9e-4647-8d13-4e88094d2790%'");
                if ($jr && $jr->num_rows > 0) {
                    $jrow = $jr->fetch_assoc();
                    $jcontent = extract_content($jrow['elements']);

                    if (strpos($jcontent, 'data:image') !== false) {
                        // 3. Process base64 AND set as featured image
                        $b64_r = fix_base64_in_string($jcontent, $perro_post_id, true); // true = set featured

                        // 4. Update post content
                        $wpdb->update($wpdb->posts, ['post_content' => $b64_r['html']], ['ID' => $perro_post_id]);
                        clean_post_cache($perro_post_id);

                        lm("  -> Recovered content & Set Featured Image ({$b64_r['fixed']} imgs)", 'success');
                    } else {
                        lm("  -> Warning: Source Joomla item exists but 'data:image' not found in extraction.", 'error');
                    }
                }
            }
        }
    }

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

        // C) Check specifically for the known UUID with base64 in Joomla, regardless of WP content length
        // This addresses the "perro.json" case where content length > 50 but image is missing.
        if (strpos($content, 'data:image') === false && strpos($content, 'imported-content') === false) {
             $zoo_id = intval($post->zoo_id);
             $jr = $j->query("SELECT elements FROM jos_zoo_item WHERE id=$zoo_id AND elements LIKE '%2e3c9e69-1f9e-4647-8d13-4e88094d2790%' AND elements LIKE '%data:image%'");
             if ($jr && $jr->num_rows > 0) {
                 $jrow = $jr->fetch_assoc();
                 $jcontent = extract_content($jrow['elements']);

                 // If extracted content has base64, update content variable so Block A can process it
                 if (strpos($jcontent, 'data:image') !== false) {
                     lm("Found missing base64 image in Joomla for Zoo ID $zoo_id. Recovering...", 'warning');
                     $content = $jcontent;
                     // We don't save to DB yet, we let Block A handle the base64 conversion and save
                 }
             }
        }

        // A) If WP content has base64, convert them to files
        if (strpos($content, 'data:image') !== false) {
            $r = fix_base64_in_string($content, $post->ID, true); // Set featured image
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
                        $b64_r = fix_base64_in_string($jcontent, $post->ID, true); // Set featured image
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
        $b64_r = fix_base64_in_string($content, 0, true); // Set featured image
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
