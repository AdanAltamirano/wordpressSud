<?php
/**
 * Diagnostic Tool to Check for Data Discrepancies
 */

require_once('wp-load.php');
require_once('migration-config.php');

echo "<h1>Diagnóstico de Discrepancias</h1>";
echo "<style>table{border-collapse:collapse; margin-bottom:20px;} th,td{border:1px solid #ccc; padding:5px;} .warning{color:red; font-weight:bold;}</style>";

// 1. Joomla Breakdown
echo "<h2>1. Desglose en Joomla (jos_zoo_item)</h2>";
$jdb = get_joomla_connection();

$q = "SELECT type, state, COUNT(*) as count FROM jos_zoo_item GROUP BY type, state ORDER BY type, state";
$res = $jdb->query($q);

echo "<table><tr><th>Tipo (Type)</th><th>Estado (State)</th><th>Cantidad</th></tr>";
$total_j = 0;
while ($row = $res->fetch_assoc()) {
    $state_desc = ($row['state'] == 1) ? 'Publicado' : 'No Publicado';
    echo "<tr><td>{$row['type']}</td><td>{$state_desc} ({$row['state']})</td><td>{$row['count']}</td></tr>";
    $total_j += $row['count'];
}
echo "<tr><td colspan='2'><strong>TOTAL</strong></td><td><strong>$total_j</strong></td></tr>";
echo "</table>";

// 2. WordPress Breakdown (Imported via Zoo)
echo "<h2>2. Desglose en WordPress (Items Importados)</h2>";
global $wpdb;

$q = "SELECT p.post_type, p.post_status, COUNT(*) as count
      FROM $wpdb->posts p
      JOIN $wpdb->postmeta pm ON p.ID = pm.post_id
      WHERE pm.meta_key = '_joomla_zoo_id'
      GROUP BY p.post_type, p.post_status";
$res = $wpdb->get_results($q);

echo "<table><tr><th>Post Type</th><th>Post Status</th><th>Cantidad</th></tr>";
$total_w = 0;
foreach ($res as $row) {
    echo "<tr><td>{$row->post_type}</td><td>{$row->post_status}</td><td>{$row->count}</td></tr>";
    $total_w += $row->count;
}
echo "<tr><td colspan='2'><strong>TOTAL</strong></td><td><strong>$total_w</strong></td></tr>";
echo "</table>";

// 3. Check for Duplicates in WordPress
echo "<h2>3. Verificación de Duplicados en WordPress</h2>";
echo "<p>Buscando IDs de Joomla (_joomla_zoo_id) que están asignados a múltiples posts en WordPress...</p>";

$q = "SELECT meta_value, COUNT(post_id) as c
      FROM $wpdb->postmeta
      WHERE meta_key = '_joomla_zoo_id'
      GROUP BY meta_value
      HAVING c > 1
      ORDER BY c DESC
      LIMIT 20";
$dupes = $wpdb->get_results($q);

if (count($dupes) > 0) {
    echo "<p class='warning'>¡SE ENCONTRARON DUPLICADOS!</p>";
    echo "<table><tr><th>ID Joomla</th><th>Veces Repetido</th><th>IDs en WP</th></tr>";
    foreach ($dupes as $d) {
        $j_id = $d->meta_value;
        // Get WP IDs
        $wp_ids = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_joomla_zoo_id' AND meta_value = %s", $j_id));
        echo "<tr><td>$j_id</td><td>{$d->c}</td><td>" . implode(', ', $wp_ids) . "</td></tr>";
    }
    echo "</table>";
    echo "<p>Esto explica por qué hay más posts en WordPress que en Joomla. Probablemente se importaron varias veces.</p>";
} else {
    echo "<p style='color:green;'>No se encontraron duplicados por ID de Joomla.</p>";
}

// 4. Missing Items Analysis
echo "<h2>4. Análisis de Faltantes</h2>";
// Get all Published Joomla IDs of type 'article', 'blog-post', 'page'
$j_ids = [];
$jq = "SELECT id FROM jos_zoo_item WHERE state = 1 AND type IN ('article', 'blog-post', 'page')";
$jres = $jdb->query($jq);
while ($row = $jres->fetch_assoc()) $j_ids[] = $row['id'];

// Get all Imported IDs in WP
$w_ids = $wpdb->get_col("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = '_joomla_zoo_id'");

$missing = array_diff($j_ids, $w_ids);
$extra = array_diff($w_ids, $j_ids); // Extra items in WP not in current Joomla list

echo "<p>Items en Joomla (filtro: article, blog-post, page, published): " . count($j_ids) . "</p>";
echo "<p>Items en WordPress (con _joomla_zoo_id): " . count($w_ids) . "</p>";

if (count($missing) > 0) {
    echo "<p class='warning'>Faltan por importar: " . count($missing) . " items.</p>";
    echo "<details><summary>Ver primeros 20 faltantes</summary><ul>";
    $i=0; foreach($missing as $id) { if($i++ > 20) break; echo "<li>ID Joomla: $id</li>"; }
    echo "</ul></details>";
} else {
    echo "<p style='color:green;'>No falta ningún item de los filtrados.</p>";
}

if (count($extra) > 0) {
    echo "<p class='warning'>Hay " . count($extra) . " items en WordPress que NO están en la lista actual de Joomla (posiblemente borrados en Joomla, o tipos diferentes).</p>";
    // Check what types these 'extra' items are in Joomla
    if (count($extra) < 1000) {
        $extra_str = implode(',', $extra);
        $eq = "SELECT id, type, state FROM jos_zoo_item WHERE id IN ($extra_str) LIMIT 20";
        $eres = $jdb->query($eq);
        echo "<table caption='Muestra de items extra en WP'><tr><th>ID</th><th>Tipo Real en Joomla</th><th>Estado</th></tr>";
        while ($er = $eres->fetch_assoc()) {
            echo "<tr><td>{$er['id']}</td><td>{$er['type']}</td><td>{$er['state']}</td></tr>";
        }
        echo "</table>";
    }
}

?>
