<?php
/**
 * Revisar TODAS las URLs de imágenes en WordPress
 * Para detectar cuáles apuntan a sudcalifornios.com en lugar de local
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$wordpress = mysqli_init();
$wordpress->options(MYSQLI_SET_CHARSET_NAME, 'utf8');
$wordpress->real_connect('127.0.0.1', 'wpuser', 'MiPassword123', 'wordpress', 3308);
if ($wordpress->connect_error) die("Error WordPress: " . $wordpress->connect_error);

echo "<h2>REVISIÓN DE TODAS LAS URLs DE IMÁGENES EN WORDPRESS</h2>";

$problemas = [];

// 1. IMÁGENES DE AUTORES (_author_image)
echo "<h3>1. IMÁGENES DE AUTORES (_author_image)</h3>";
$result = $wordpress->query("
    SELECT COUNT(*) as total,
           SUM(CASE WHEN meta_value LIKE '%sudcalifornios.com%' THEN 1 ELSE 0 END) as externos
    FROM wp_usermeta
    WHERE meta_key = '_author_image' AND meta_value != ''
");
$row = $result->fetch_assoc();
echo "<p>Total: {$row['total']} | Apuntando a sudcalifornios.com: <strong style='color:red;'>{$row['externos']}</strong></p>";
if ($row['externos'] > 0) $problemas['author_images'] = $row['externos'];

// 2. THUMBNAILS / FEATURED IMAGES (post_meta _thumbnail_id -> attachment URL)
echo "<h3>2. IMÁGENES DESTACADAS (Featured Images)</h3>";
$result = $wordpress->query("
    SELECT COUNT(*) as total
    FROM wp_postmeta
    WHERE meta_key = '_thumbnail_id'
");
$row = $result->fetch_assoc();
echo "<p>Posts con imagen destacada: {$row['total']}</p>";

// Verificar URLs de attachments
$result = $wordpress->query("
    SELECT COUNT(*) as total,
           SUM(CASE WHEN guid LIKE '%sudcalifornios.com%' THEN 1 ELSE 0 END) as externos
    FROM wp_posts
    WHERE post_type = 'attachment'
");
$row = $result->fetch_assoc();
echo "<p>Attachments total: {$row['total']} | Apuntando a sudcalifornios.com: <strong style='color:red;'>{$row['externos']}</strong></p>";
if ($row['externos'] > 0) $problemas['attachments_guid'] = $row['externos'];

// 3. URLs EN CONTENIDO DE POSTS (post_content)
echo "<h3>3. IMÁGENES EN CONTENIDO DE POSTS</h3>";
$result = $wordpress->query("
    SELECT COUNT(*) as total
    FROM wp_posts
    WHERE post_type = 'post' AND post_status = 'publish'
    AND (post_content LIKE '%sudcalifornios.com/images%'
         OR post_content LIKE '%sudcalifornios.com/cache%')
");
$row = $result->fetch_assoc();
echo "<p>Posts con imágenes apuntando a sudcalifornios.com: <strong style='color:red;'>{$row['total']}</strong></p>";
if ($row['total'] > 0) $problemas['post_content'] = $row['total'];

// 4. POST META (_wp_attached_file)
echo "<h3>4. ARCHIVOS ADJUNTOS (wp_postmeta _wp_attached_file)</h3>";
$result = $wordpress->query("
    SELECT COUNT(*) as total,
           SUM(CASE WHEN meta_value LIKE '%sudcalifornios.com%' THEN 1 ELSE 0 END) as externos
    FROM wp_postmeta
    WHERE meta_key = '_wp_attached_file'
");
$row = $result->fetch_assoc();
echo "<p>Total: {$row['total']} | Apuntando a sudcalifornios.com: <strong style='color:red;'>{$row['externos']}</strong></p>";
if ($row['externos'] > 0) $problemas['attached_file'] = $row['externos'];

// 5. OPCIONES DEL TEMA (logos, etc)
echo "<h3>5. OPCIONES DEL SITIO</h3>";
$result = $wordpress->query("
    SELECT option_name, option_value
    FROM wp_options
    WHERE option_value LIKE '%sudcalifornios.com/images%'
    LIMIT 10
");
$count = $result->num_rows;
echo "<p>Opciones con URLs a sudcalifornios.com: <strong style='color:red;'>{$count}</strong></p>";
if ($count > 0) {
    $problemas['options'] = $count;
    echo "<ul>";
    while ($row = $result->fetch_assoc()) {
        echo "<li><code>{$row['option_name']}</code></li>";
    }
    echo "</ul>";
}

// 6. JOOMLA IMAGE META (si existe)
echo "<h3>6. META DE IMÁGENES JOOMLA (_joomla_image)</h3>";
$result = $wordpress->query("
    SELECT COUNT(*) as total,
           SUM(CASE WHEN meta_value LIKE '%sudcalifornios.com%' THEN 1 ELSE 0 END) as externos
    FROM wp_postmeta
    WHERE meta_key = '_joomla_image' AND meta_value != ''
");
$row = $result->fetch_assoc();
echo "<p>Total: {$row['total']} | Apuntando a sudcalifornios.com: <strong style='color:red;'>{$row['externos']}</strong></p>";
if ($row['externos'] > 0) $problemas['joomla_image'] = $row['externos'];

// RESUMEN
echo "<h2>RESUMEN DE PROBLEMAS</h2>";
if (empty($problemas)) {
    echo "<p style='color:green; font-size:18px;'>✓ No se encontraron URLs externas apuntando a sudcalifornios.com</p>";
} else {
    echo "<table border='1' cellpadding='8' style='border-collapse:collapse;'>";
    echo "<tr style='background:#f0f0f0;'><th>Área</th><th>Cantidad a corregir</th></tr>";
    $total = 0;
    foreach ($problemas as $area => $cantidad) {
        $total += $cantidad;
        echo "<tr><td>{$area}</td><td style='text-align:center; color:red;'><strong>{$cantidad}</strong></td></tr>";
    }
    echo "<tr style='background:#ffe0e0;'><td><strong>TOTAL</strong></td><td style='text-align:center;'><strong>{$total}</strong></td></tr>";
    echo "</table>";

    echo "<h3>SIGUIENTE PASO:</h3>";
    echo "<p>Ejecutar script de corrección para cambiar todas las URLs de:</p>";
    echo "<code>https://www.sudcalifornios.com/images/...</code><br>";
    echo "<p>A:</p>";
    echo "<code>/wp-content/uploads/joomla-images/...</code>";
}

// Mostrar ejemplos de cada tipo
echo "<h2>EJEMPLOS DE URLs PROBLEMÁTICAS</h2>";

echo "<h4>Ejemplo de contenido de post:</h4>";
$result = $wordpress->query("
    SELECT ID, post_title,
           SUBSTRING(post_content,
               LOCATE('sudcalifornios.com', post_content) - 20,
               100) as snippet
    FROM wp_posts
    WHERE post_type = 'post'
    AND post_content LIKE '%sudcalifornios.com/images%'
    LIMIT 3
");
while ($row = $result->fetch_assoc()) {
    echo "<p>[ID: {$row['ID']}] {$row['post_title']}<br>";
    echo "<code style='font-size:11px;'>...{$row['snippet']}...</code></p>";
}

$wordpress->close();
?>
