<?php
/**
 * Corregir TODAS las URLs de imágenes en WordPress
 * Cambiar de sudcalifornios.com a rutas locales
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);

$wordpress = mysqli_init();
$wordpress->options(MYSQLI_SET_CHARSET_NAME, 'utf8');
$wordpress->real_connect('127.0.0.1', 'wpuser', 'MiPassword123', 'wordpress', 3308);
if ($wordpress->connect_error) die("Error WordPress: " . $wordpress->connect_error);

echo "<h2>CORRIGIENDO TODAS LAS URLs DE IMÁGENES</h2>";

$stats = [
    'author_images' => 0,
    'post_content' => 0,
    'errors' => 0
];

// Patrones a reemplazar
$replacements = [
    'https://www.sudcalifornios.com/images/' => '/wp-content/uploads/joomla-images/',
    'http://www.sudcalifornios.com/images/' => '/wp-content/uploads/joomla-images/',
    'https://sudcalifornios.com/images/' => '/wp-content/uploads/joomla-images/',
    'http://sudcalifornios.com/images/' => '/wp-content/uploads/joomla-images/',
];

// =============================================
// 1. CORREGIR IMÁGENES DE AUTORES
// =============================================
echo "<h3>1. Corrigiendo imágenes de autores...</h3>";

$result = $wordpress->query("
    SELECT umeta_id, meta_value
    FROM wp_usermeta
    WHERE meta_key = '_author_image' AND meta_value != ''
    AND (meta_value LIKE '%sudcalifornios.com%')
");

while ($row = $result->fetch_assoc()) {
    $new_value = $row['meta_value'];

    foreach ($replacements as $old => $new) {
        $new_value = str_replace($old, $new, $new_value);
    }

    if ($new_value !== $row['meta_value']) {
        $new_value_safe = $wordpress->real_escape_string($new_value);
        $update = $wordpress->query("
            UPDATE wp_usermeta
            SET meta_value = '{$new_value_safe}'
            WHERE umeta_id = {$row['umeta_id']}
        ");

        if ($update) {
            $stats['author_images']++;
        } else {
            $stats['errors']++;
        }
    }
}

echo "<p>Imágenes de autores corregidas: <strong style='color:green;'>{$stats['author_images']}</strong></p>";

// =============================================
// 2. CORREGIR CONTENIDO DE POSTS
// =============================================
echo "<h3>2. Corrigiendo contenido de posts...</h3>";

$result = $wordpress->query("
    SELECT ID, post_content
    FROM wp_posts
    WHERE post_type = 'post'
    AND (post_content LIKE '%sudcalifornios.com/images%')
");

$posts_to_update = [];
while ($row = $result->fetch_assoc()) {
    $new_content = $row['post_content'];

    foreach ($replacements as $old => $new) {
        $new_content = str_replace($old, $new, $new_content);
    }

    if ($new_content !== $row['post_content']) {
        $posts_to_update[] = [
            'id' => $row['ID'],
            'content' => $new_content
        ];
    }
}

// Actualizar posts
foreach ($posts_to_update as $post) {
    $content_safe = $wordpress->real_escape_string($post['content']);
    $update = $wordpress->query("
        UPDATE wp_posts
        SET post_content = '{$content_safe}'
        WHERE ID = {$post['id']}
    ");

    if ($update) {
        $stats['post_content']++;
    } else {
        $stats['errors']++;
    }
}

echo "<p>Posts corregidos: <strong style='color:green;'>{$stats['post_content']}</strong></p>";

// =============================================
// 3. TAMBIÉN CORREGIR CACHE (si existe)
// =============================================
echo "<h3>3. Verificando URLs de cache...</h3>";

$cache_replacements = [
    'https://www.sudcalifornios.com/cache/com_zoo/images/' => '/wp-content/uploads/joomla-images/cache/com_zoo/images/',
    'http://www.sudcalifornios.com/cache/com_zoo/images/' => '/wp-content/uploads/joomla-images/cache/com_zoo/images/',
];

$result = $wordpress->query("
    SELECT COUNT(*) as total
    FROM wp_posts
    WHERE post_content LIKE '%sudcalifornios.com/cache%'
");
$row = $result->fetch_assoc();
echo "<p>Posts con URLs de cache: {$row['total']}</p>";

if ($row['total'] > 0) {
    echo "<p style='color:orange;'>Nota: Si tienes la carpeta cache copiada, ejecuta un script adicional para corregir esas URLs.</p>";
}

// =============================================
// RESUMEN FINAL
// =============================================
echo "<h2>RESUMEN</h2>";
echo "<table border='1' cellpadding='8' style='border-collapse:collapse;'>";
echo "<tr style='background:#e0ffe0;'><th>Área</th><th>Corregidos</th></tr>";
echo "<tr><td>Imágenes de autores</td><td style='text-align:center;'><strong>{$stats['author_images']}</strong></td></tr>";
echo "<tr><td>Contenido de posts</td><td style='text-align:center;'><strong>{$stats['post_content']}</strong></td></tr>";
echo "<tr><td>Errores</td><td style='text-align:center; color:red;'>{$stats['errors']}</td></tr>";
$total = $stats['author_images'] + $stats['post_content'];
echo "<tr style='background:#c0ffc0;'><td><strong>TOTAL CORREGIDOS</strong></td><td style='text-align:center;'><strong>{$total}</strong></td></tr>";
echo "</table>";

// =============================================
// VERIFICACIÓN
// =============================================
echo "<h2>VERIFICACIÓN</h2>";

echo "<h4>Imágenes de autores restantes con URL externa:</h4>";
$result = $wordpress->query("
    SELECT COUNT(*) as total
    FROM wp_usermeta
    WHERE meta_key = '_author_image'
    AND meta_value LIKE '%sudcalifornios.com%'
");
$row = $result->fetch_assoc();
echo "<p>Restantes: <strong>{$row['total']}</strong></p>";

echo "<h4>Posts restantes con URL externa:</h4>";
$result = $wordpress->query("
    SELECT COUNT(*) as total
    FROM wp_posts
    WHERE post_content LIKE '%sudcalifornios.com/images%'
");
$row = $result->fetch_assoc();
echo "<p>Restantes: <strong>{$row['total']}</strong></p>";

// Mostrar ejemplo de autor corregido
echo "<h4>Ejemplo - Andrea Geiger:</h4>";
$result = $wordpress->query("
    SELECT u.display_name, um.meta_value as imagen
    FROM wp_users u
    INNER JOIN wp_usermeta um ON u.ID = um.user_id AND um.meta_key = '_author_image'
    WHERE u.display_name LIKE '%Andrea%Geiger%'
    LIMIT 1
");
if ($row = $result->fetch_assoc()) {
    echo "<p>{$row['display_name']}</p>";
    echo "<p>URL: <code>{$row['imagen']}</code></p>";
    echo "<p><img src='{$row['imagen']}' style='max-width:100px; border-radius:50%;' /></p>";
}

$wordpress->close();

echo "<h3 style='color:green;'>¡COMPLETADO!</h3>";
echo "<p>Ahora visita la página de un autor para verificar que las imágenes se muestren correctamente.</p>";
?>
