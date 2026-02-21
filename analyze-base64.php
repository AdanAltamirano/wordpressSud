<?php
/**
 * Analizar imágenes base64 en Joomla y WordPress
 */
set_time_limit(300);
ini_set('memory_limit', '1024M');
ini_set('display_errors', 1);
error_reporting(E_ALL);

$wp = new mysqli('127.0.0.1', 'root', 'yue02', 'wordpress', 3308);
$wp->set_charset("utf8mb4");
$j = new mysqli('127.0.0.1', 'root', 'yue02', 'sudcalifornios', 3306);
$j->set_charset("utf8");

echo "=== ANALISIS DE IMAGENES BASE64 ===\n\n";

// 1. Check in WordPress post_content
echo "--- EN WORDPRESS (post_content) ---\n";
$wp_b64 = $wp->query("SELECT COUNT(*) c FROM wp_posts WHERE post_type='post' AND post_status='publish' AND post_content LIKE '%data:image%'")->fetch_assoc()['c'];
echo "Posts con data:image en contenido: $wp_b64\n";

if ($wp_b64 > 0) {
    $r = $wp->query("SELECT ID, post_title, LENGTH(post_content) as len FROM wp_posts WHERE post_type='post' AND post_status='publish' AND post_content LIKE '%data:image%' ORDER BY LENGTH(post_content) DESC LIMIT 10");
    echo "Ejemplos (los mas pesados):\n";
    while ($row = $r->fetch_assoc()) {
        echo "  WP:{$row['ID']} | {$row['len']} bytes | {$row['post_title']}\n";
    }
}

// Count how many base64 images total in WP
$r = $wp->query("SELECT ID, post_content FROM wp_posts WHERE post_type='post' AND post_status='publish' AND post_content LIKE '%data:image%'");
$total_b64_wp = 0;
$types_wp = [];
while ($row = $r->fetch_assoc()) {
    preg_match_all('/src=["\']data:image\/([\w\+]+);base64,/i', $row['post_content'], $m);
    $total_b64_wp += count($m[0]);
    foreach ($m[1] as $type) {
        $types_wp[$type] = ($types_wp[$type] ?? 0) + 1;
    }
}
echo "Total imagenes base64 en WP: $total_b64_wp\n";
echo "Tipos: " . print_r($types_wp, true) . "\n";

// 2. Check in Joomla elements JSON
echo "\n--- EN JOOMLA (jos_zoo_item.elements) ---\n";
$j_b64 = $j->query("SELECT COUNT(*) c FROM jos_zoo_item WHERE elements LIKE '%data:image%'")->fetch_assoc()['c'];
echo "Items con data:image en elements: $j_b64\n";

// 3. Check Joomla elements for image UUIDs (image type elements)
echo "\n--- ELEMENTOS DE IMAGEN EN JOOMLA ZOO ---\n";
// Sample a few items to find image-related UUIDs
$r = $j->query("SELECT id, name, elements FROM jos_zoo_item WHERE type='article' AND state=1 AND LENGTH(elements) > 500 LIMIT 5");
$image_uuids = [];
while ($row = $r->fetch_assoc()) {
    $elems = json_decode($row['elements'], true);
    if (!$elems) continue;
    foreach ($elems as $uuid => $data) {
        if (!is_array($data)) continue;
        foreach ($data as $item) {
            if (!is_array($item)) continue;
            // Look for image-type values (file paths, base64, URLs)
            if (isset($item['value']) && is_string($item['value'])) {
                $v = $item['value'];
                if (preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $v) && strlen($v) < 500) {
                    if (!isset($image_uuids[$uuid])) {
                        $image_uuids[$uuid] = ['type' => 'file_path', 'sample' => $v, 'count' => 0];
                    }
                    $image_uuids[$uuid]['count']++;
                }
                if (strpos($v, 'data:image') !== false) {
                    if (!isset($image_uuids[$uuid])) {
                        $image_uuids[$uuid] = ['type' => 'base64', 'sample' => substr($v, 0, 80), 'count' => 0];
                    }
                    $image_uuids[$uuid]['count']++;
                }
            }
            // Check for 'file' key (Zoo image elements store paths in 'file')
            if (isset($item['file']) && is_string($item['file'])) {
                if (!isset($image_uuids[$uuid])) {
                    $image_uuids[$uuid] = ['type' => 'zoo_file', 'sample' => $item['file'], 'count' => 0];
                }
                $image_uuids[$uuid]['count']++;
            }
        }
    }
}
echo "UUIDs de imagen encontrados:\n";
foreach ($image_uuids as $uuid => $info) {
    echo "  $uuid -> Type:{$info['type']} Sample:{$info['sample']}\n";
}

// 4. Now scan ALL Joomla articles for image elements
echo "\n--- ESCANEO COMPLETO DE IMAGENES EN ZOO ---\n";
$r = $j->query("SELECT id, name, type, elements FROM jos_zoo_item WHERE type='article' AND state=1");
$has_base64_in_elements = 0;
$has_file_images = 0;
$has_images_path = 0;
$has_http_images = 0;
$image_element_uuids = [];
$sample_file_images = [];

while ($row = $r->fetch_assoc()) {
    $elems = json_decode($row['elements'], true);
    if (!$elems) continue;

    $found_image = false;
    foreach ($elems as $uuid => $data) {
        if (!is_array($data)) continue;
        foreach ($data as $item) {
            if (!is_array($item)) continue;

            // Zoo image elements use 'file' key
            if (isset($item['file']) && is_string($item['file']) && !empty($item['file'])) {
                $has_file_images++;
                $found_image = true;
                if (!isset($image_element_uuids[$uuid])) {
                    $image_element_uuids[$uuid] = 0;
                }
                $image_element_uuids[$uuid]++;
                if (count($sample_file_images) < 10) {
                    $sample_file_images[] = "Zoo:{$row['id']} UUID:$uuid -> {$item['file']}";
                }
            }

            // Check value for base64
            if (isset($item['value']) && is_string($item['value'])) {
                if (strpos($item['value'], 'data:image') !== false) {
                    $has_base64_in_elements++;
                }
                if (preg_match('/src=["\']images\//', $item['value'])) {
                    $has_images_path++;
                }
                if (preg_match('/src=["\']https?:\/\//', $item['value'])) {
                    $has_http_images++;
                }
            }
        }
    }
}

echo "Articulos con base64 en elements: $has_base64_in_elements\n";
echo "Articulos con src=\"images/...\" en HTML: $has_images_path\n";
echo "Articulos con src=\"http...\" en HTML: $has_http_images\n";
echo "Items con 'file' key (imagen Zoo): $has_file_images\n";

echo "\nUUIDs de elementos de imagen (file key):\n";
foreach ($image_element_uuids as $uuid => $cnt) {
    echo "  $uuid: $cnt items\n";
}

echo "\nMuestra de image files:\n";
foreach ($sample_file_images as $s) {
    echo "  $s\n";
}

// 5. Check which zoo_ids in WP have empty content but Joomla has images
echo "\n--- POSTS VACIOS CON IMAGENES EN JOOMLA ---\n";
$r = $wp->query("SELECT p.ID, p.post_title, pm.meta_value as zoo_id
    FROM wp_posts p
    JOIN wp_postmeta pm ON p.ID=pm.post_id AND pm.meta_key='_joomla_zoo_id'
    WHERE p.post_type='post' AND p.post_status='publish' AND (p.post_content='' OR p.post_content IS NULL)
    LIMIT 20");
$count_check = 0;
while ($row = $r->fetch_assoc()) {
    $zoo_id = intval($row['zoo_id']);
    $jr = $j->query("SELECT elements, LENGTH(elements) as elen FROM jos_zoo_item WHERE id=$zoo_id");
    if ($jr && $jr->num_rows > 0) {
        $jrow = $jr->fetch_assoc();
        $has_content = strlen($jrow['elements']) > 100 ? 'SI' : 'NO';
        echo "  WP:{$row['ID']} Zoo:$zoo_id ElemLen:{$jrow['elen']} Content:$has_content | {$row['post_title']}\n";

        // Quick look at what's in elements
        $elems = json_decode($jrow['elements'], true);
        if ($elems) {
            foreach ($elems as $uuid => $data) {
                if (!is_array($data)) continue;
                foreach ($data as $item) {
                    if (!is_array($item)) continue;
                    if (isset($item['value']) && strlen($item['value']) > 30) {
                        echo "    UUID:$uuid value_len:" . strlen($item['value']) . " starts:" . substr(strip_tags($item['value']), 0, 60) . "\n";
                    }
                    if (isset($item['file'])) {
                        echo "    UUID:$uuid file: {$item['file']}\n";
                    }
                }
            }
        }
    }
    $count_check++;
}

// 6. Summary of the 29 missing posts
echo "\n--- LOS 29 POSTS FALTANTES ---\n";
$imported = [];
$r2 = $wp->query("SELECT meta_value FROM wp_postmeta WHERE meta_key='_joomla_zoo_id'");
while ($row2 = $r2->fetch_assoc()) $imported[$row2['meta_value']] = true;

$r = $j->query("SELECT id, name, type, LENGTH(elements) as elen FROM jos_zoo_item WHERE type IN('article','page') AND state=1 ORDER BY id ASC");
$missing = [];
while ($row = $r->fetch_assoc()) {
    if (!isset($imported[$row['id']])) {
        $missing[] = $row;
    }
}
echo "Faltantes: " . count($missing) . "\n";
foreach ($missing as $m) {
    echo "  Zoo:{$m['id']} Type:{$m['type']} ElemLen:{$m['elen']} | {$m['name']}\n";
}

// 7. Duplicates by zoo_id
echo "\n--- DUPLICADOS POR ZOO_ID (mismo item importado >1 vez) ---\n";
$r = $wp->query("SELECT meta_value as zoo_id, COUNT(*) as cnt FROM wp_postmeta WHERE meta_key='_joomla_zoo_id' GROUP BY meta_value HAVING cnt > 1 ORDER BY cnt DESC LIMIT 20");
$dup_count = 0;
while ($row = $r->fetch_assoc()) {
    $jname = $j->query("SELECT name FROM jos_zoo_item WHERE id=" . intval($row['zoo_id']))->fetch_assoc()['name'] ?? '?';
    echo "  Zoo:{$row['zoo_id']} x{$row['cnt']} | $jname\n";

    // Show the WP IDs
    $wp_ids = $wp->query("SELECT pm.post_id, LENGTH(p.post_content) as clen FROM wp_postmeta pm JOIN wp_posts p ON pm.post_id=p.ID WHERE pm.meta_key='_joomla_zoo_id' AND pm.meta_value='{$row['zoo_id']}'");
    $id_list = [];
    while ($wr = $wp_ids->fetch_assoc()) {
        $id_list[] = "WP:{$wr['post_id']}({$wr['clen']}chars)";
    }
    echo "    -> " . implode(', ', $id_list) . "\n";
    $dup_count++;
}
$total_dup_zoo = $wp->query("SELECT COUNT(*) c FROM (SELECT meta_value, COUNT(*) cnt FROM wp_postmeta WHERE meta_key='_joomla_zoo_id' GROUP BY meta_value HAVING cnt > 1) t")->fetch_assoc()['c'];
echo "Total zoo_ids duplicados: $total_dup_zoo\n";

$wp->close();
$j->close();
