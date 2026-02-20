<?php
/**
 * Comparación exhaustiva Joomla vs WordPress post por post
 * Usa zoo_id como llave para comparar 1 a 1
 */
set_time_limit(300);
ini_set('memory_limit', '1024M');
ini_set('display_errors', 1);
error_reporting(E_ALL);

// WordPress DB
$wp = new mysqli('127.0.0.1', 'root', 'yue02', 'wordpress', 3308);
$wp->set_charset("utf8mb4");

// Joomla DB
$j = new mysqli('127.0.0.1', 'root', 'yue02', 'sudcalifornios', 3306);
$j->set_charset("utf8");

echo "=== COMPARACION EXHAUSTIVA JOOMLA vs WORDPRESS ===\n\n";

// 1. Joomla: todos los items publicados
echo "--- JOOMLA ---\n";
$j_articles = $j->query("SELECT COUNT(*) c FROM jos_zoo_item WHERE type='article' AND state=1")->fetch_assoc()['c'];
$j_pages = $j->query("SELECT COUNT(*) c FROM jos_zoo_item WHERE type='page' AND state=1")->fetch_assoc()['c'];
$j_authors = $j->query("SELECT COUNT(*) c FROM jos_zoo_item WHERE type='author'")->fetch_assoc()['c'];
$j_total_pub = $j->query("SELECT COUNT(*) c FROM jos_zoo_item WHERE state=1")->fetch_assoc()['c'];
$j_total_all = $j->query("SELECT COUNT(*) c FROM jos_zoo_item")->fetch_assoc()['c'];

echo "Articulos publicados (type=article, state=1): $j_articles\n";
echo "Paginas publicadas (type=page, state=1): $j_pages\n";
echo "Autores (type=author, cualquier state): $j_authors\n";
echo "Total items publicados (state=1): $j_total_pub\n";
echo "Total items en Zoo (todos): $j_total_all\n";

// Items no publicados
$j_unpub = $j->query("SELECT COUNT(*) c FROM jos_zoo_item WHERE type='article' AND state!=1")->fetch_assoc()['c'];
echo "Articulos NO publicados: $j_unpub\n";

// Check other types
$types = $j->query("SELECT type, state, COUNT(*) c FROM jos_zoo_item GROUP BY type, state ORDER BY type, state");
echo "\nDesglose por tipo y estado:\n";
while ($r = $types->fetch_assoc()) {
    $state_txt = $r['state'] == 1 ? 'publicado' : ($r['state'] == 0 ? 'despublicado' : "state={$r['state']}");
    echo "  {$r['type']} ($state_txt): {$r['c']}\n";
}

// 2. WordPress
echo "\n--- WORDPRESS ---\n";
$wp_published = $wp->query("SELECT COUNT(*) c FROM wp_posts WHERE post_type='post' AND post_status='publish'")->fetch_assoc()['c'];
$wp_draft = $wp->query("SELECT COUNT(*) c FROM wp_posts WHERE post_type='post' AND post_status='draft'")->fetch_assoc()['c'];
$wp_trash = $wp->query("SELECT COUNT(*) c FROM wp_posts WHERE post_type='post' AND post_status='trash'")->fetch_assoc()['c'];
$wp_total = $wp->query("SELECT COUNT(*) c FROM wp_posts WHERE post_type='post'")->fetch_assoc()['c'];
$wp_tracked = $wp->query("SELECT COUNT(*) c FROM wp_postmeta WHERE meta_key='_joomla_zoo_id'")->fetch_assoc()['c'];

echo "Posts publicados: $wp_published\n";
echo "Posts borrador: $wp_draft\n";
echo "Posts papelera: $wp_trash\n";
echo "Total posts: $wp_total\n";
echo "Posts con _joomla_zoo_id: $wp_tracked\n";

// 3. Comparación 1 a 1 usando zoo_id
echo "\n--- COMPARACION POR ZOO_ID ---\n";

// Get all zoo_ids tracked in WP
$wp_zoo_ids = [];
$r = $wp->query("SELECT pm.meta_value as zoo_id, p.ID as wp_id, p.post_status, LENGTH(p.post_content) as content_len
    FROM wp_postmeta pm
    JOIN wp_posts p ON p.ID = pm.post_id
    WHERE pm.meta_key='_joomla_zoo_id'");
while ($row = $r->fetch_assoc()) {
    $zid = $row['zoo_id'];
    if (!isset($wp_zoo_ids[$zid])) {
        $wp_zoo_ids[$zid] = [];
    }
    $wp_zoo_ids[$zid][] = $row;
}

// Check for duplicate zoo_ids in WP (same joomla item imported multiple times)
$dup_zoo_count = 0;
$dup_zoo_list = [];
foreach ($wp_zoo_ids as $zid => $entries) {
    if (count($entries) > 1) {
        $dup_zoo_count++;
        if (count($dup_zoo_list) < 20) {
            $dup_zoo_list[$zid] = count($entries);
        }
    }
}
echo "Zoo IDs duplicados en WP (mismo item importado >1 vez): $dup_zoo_count\n";
if ($dup_zoo_count > 0) {
    echo "  Primeros ejemplos:\n";
    foreach ($dup_zoo_list as $zid => $cnt) {
        $name = $j->query("SELECT name FROM jos_zoo_item WHERE id=$zid")->fetch_assoc()['name'];
        echo "    Zoo:$zid x$cnt -> $name\n";
    }
}

// Get all Joomla published articles+pages
$missing_in_wp = [];
$present_in_wp = 0;
$present_but_empty = 0;

$r = $j->query("SELECT id, name, type, state FROM jos_zoo_item WHERE type IN('article','page') AND state=1 ORDER BY id ASC");
$j_count = 0;
while ($row = $r->fetch_assoc()) {
    $j_count++;
    $zid = $row['id'];
    if (isset($wp_zoo_ids[$zid])) {
        $present_in_wp++;
        // Check if content is empty
        $best = $wp_zoo_ids[$zid][0];
        if ($best['content_len'] < 50) {
            $present_but_empty++;
        }
    } else {
        $missing_in_wp[] = $row;
    }
}

echo "\nArticulos+paginas en Joomla (pub): $j_count\n";
echo "Presentes en WP (tienen zoo_id): $present_in_wp\n";
echo "Presentes pero vacios (<50 chars): $present_but_empty\n";
echo "FALTANTES en WP: " . count($missing_in_wp) . "\n";

if (count($missing_in_wp) > 0) {
    echo "\n--- ARTICULOS FALTANTES (primeros 50) ---\n";
    $shown = 0;
    foreach ($missing_in_wp as $m) {
        if ($shown >= 50) { echo "  ... y " . (count($missing_in_wp) - 50) . " mas\n"; break; }
        echo "  Zoo:{$m['id']} Type:{$m['type']} | {$m['name']}\n";
        $shown++;
    }
}

// Also check: Joomla authors that might be in WP
echo "\n--- AUTORES JOOMLA EN WP ---\n";
$r = $j->query("SELECT id, name FROM jos_zoo_item WHERE type='author'");
$authors_in_wp = 0;
$authors_missing = 0;
while ($row = $r->fetch_assoc()) {
    if (isset($wp_zoo_ids[$row['id']])) {
        $authors_in_wp++;
    } else {
        $authors_missing++;
    }
}
echo "Autores presentes en WP: $authors_in_wp\n";
echo "Autores NO en WP: $authors_missing\n";

// Content quality check
echo "\n--- CALIDAD DE CONTENIDO ---\n";
$content_ok = $wp->query("SELECT COUNT(*) c FROM wp_posts WHERE post_type='post' AND post_status='publish' AND LENGTH(post_content)>=50")->fetch_assoc()['c'];
$content_short = $wp->query("SELECT COUNT(*) c FROM wp_posts WHERE post_type='post' AND post_status='publish' AND LENGTH(post_content)>0 AND LENGTH(post_content)<50")->fetch_assoc()['c'];
$content_empty = $wp->query("SELECT COUNT(*) c FROM wp_posts WHERE post_type='post' AND post_status='publish' AND (post_content='' OR post_content IS NULL)")->fetch_assoc()['c'];
$broken_imgs = $wp->query("SELECT COUNT(*) c FROM wp_posts WHERE post_type='post' AND post_status='publish' AND post_content LIKE '%src=\"images/%'")->fetch_assoc()['c'];

echo "Posts con contenido OK (>=50 chars): $content_ok\n";
echo "Posts con contenido corto (1-49 chars): $content_short\n";
echo "Posts totalmente vacios: $content_empty\n";
echo "Posts con imagenes rotas (src=\"images/...\"): $broken_imgs\n";

echo "\n=== RESUMEN ===\n";
echo "Joomla tiene $j_articles articulos publicados\n";
echo "WordPress tiene $wp_published posts publicados\n";
echo "Faltan " . count($missing_in_wp) . " articulos de Joomla en WordPress\n";
echo "Hay $dup_zoo_count items importados mas de una vez (duplicados por zoo_id)\n";
echo "Hay $present_but_empty posts presentes pero sin contenido\n";
echo "Hay $broken_imgs posts con imagenes rotas\n";

$wp->close();
$j->close();
