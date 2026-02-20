<?php
/**
 * Quick status check after backup restore
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

// WordPress DB
$wp = new mysqli('127.0.0.1', 'root', 'yue02', 'wordpress', 3308);
$wp->set_charset("utf8mb4");

// Joomla DB
$j = new mysqli('127.0.0.1', 'root', 'yue02', 'sudcalifornios', 3306);
$j->set_charset("utf8");

echo "=== ESTADO ACTUAL ===\n\n";

// WordPress
$wp_pub = $wp->query("SELECT COUNT(*) c FROM wp_posts WHERE post_type='post' AND post_status='publish'")->fetch_assoc()['c'];
$wp_tracked = $wp->query("SELECT COUNT(*) c FROM wp_postmeta WHERE meta_key='_joomla_zoo_id'")->fetch_assoc()['c'];
echo "WP Posts publicados: $wp_pub\n";
echo "WP Posts con _joomla_zoo_id: $wp_tracked\n";

// Joomla
$j_pub = $j->query("SELECT COUNT(*) c FROM jos_zoo_item WHERE type IN('article','page') AND state=1")->fetch_assoc()['c'];
echo "Joomla articulos+paginas pub: $j_pub\n\n";

// Posts with broken images (src="images/...")
$broken = $wp->query("SELECT COUNT(*) c FROM wp_posts WHERE post_type='post' AND post_status='publish' AND post_content LIKE '%src=\"images/%'")->fetch_assoc()['c'];
echo "Posts con imagenes rotas (src=\"images/...\"): $broken\n";

// Posts with very little content
$empty = $wp->query("SELECT COUNT(*) c FROM wp_posts WHERE post_type='post' AND post_status='publish' AND LENGTH(post_content)<50")->fetch_assoc()['c'];
echo "Posts con <50 chars contenido: $empty\n";

// Posts with no content at all
$truly_empty = $wp->query("SELECT COUNT(*) c FROM wp_posts WHERE post_type='post' AND post_status='publish' AND (post_content='' OR post_content IS NULL)")->fetch_assoc()['c'];
echo "Posts totalmente vacios: $truly_empty\n\n";

// Sample empty posts to understand them
echo "=== MUESTRA DE POSTS VACIOS (primeros 15) ===\n";
$r = $wp->query("SELECT p.ID, p.post_title, LENGTH(p.post_content) as len, SUBSTRING(p.post_content,1,80) as preview, pm.meta_value as zoo_id
    FROM wp_posts p
    LEFT JOIN wp_postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id'
    WHERE p.post_type='post' AND p.post_status='publish' AND LENGTH(p.post_content)<50
    ORDER BY p.ID ASC LIMIT 15");
while ($row = $r->fetch_assoc()) {
    $zoo_type = '';
    if ($row['zoo_id']) {
        $tr = $j->query("SELECT type FROM jos_zoo_item WHERE id=" . intval($row['zoo_id']));
        if ($tr && $tr->num_rows > 0) $zoo_type = $tr->fetch_assoc()['type'];
    }
    echo "  WP:{$row['ID']} Zoo:{$row['zoo_id']} Type:$zoo_type Len:{$row['len']} | {$row['post_title']}\n";
    if ($row['preview']) echo "    Preview: " . trim($row['preview']) . "\n";
}

echo "\n=== MUESTRA DE IMAGENES ROTAS (primeros 10) ===\n";
$r = $wp->query("SELECT ID, post_title, SUBSTRING(post_content, LOCATE('src=\"images/', post_content), 80) as img_snippet
    FROM wp_posts WHERE post_type='post' AND post_status='publish' AND post_content LIKE '%src=\"images/%' LIMIT 10");
while ($row = $r->fetch_assoc()) {
    echo "  WP:{$row['ID']} | {$row['post_title']}\n";
    echo "    Imagen: {$row['img_snippet']}\n";
}

// Check how many posts have content that could be recovered from Joomla
echo "\n=== POSTS RECUPERABLES DESDE JOOMLA ===\n";
$empty_with_zoo = $wp->query("SELECT COUNT(*) c FROM wp_posts p
    JOIN wp_postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id'
    WHERE p.post_type='post' AND p.post_status='publish' AND LENGTH(p.post_content)<50")->fetch_assoc()['c'];
echo "Posts vacios CON zoo_id (recuperables): $empty_with_zoo\n";

$empty_no_zoo = $wp->query("SELECT COUNT(*) c FROM wp_posts p
    LEFT JOIN wp_postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id'
    WHERE p.post_type='post' AND p.post_status='publish' AND LENGTH(p.post_content)<50 AND pm.meta_value IS NULL")->fetch_assoc()['c'];
echo "Posts vacios SIN zoo_id (no recuperables): $empty_no_zoo\n";

$wp->close();
$j->close();
