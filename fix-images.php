<?php
/**
 * Script para arreglar rutas de imágenes en WordPress
 * Cambia rutas de Joomla a WordPress
 * Ejecutar desde: http://localhost/wordpress/fix-images.php
 */
 
set_time_limit(300);
ini_set('max_execution_time', 300);
ini_set('memory_limit', '512M');
 
require_once('wp-load.php');
 
global $wpdb;
 
echo "<h1>Arreglar Rutas de Imágenes</h1>";
echo "<pre>";
 
// Patrones a reemplazar
$replacements = array(
    // Rutas relativas de Joomla
    'src="images/' => 'src="' . site_url('/wp-content/uploads/joomla-images/'),
    "src='images/" => "src='" . site_url('/wp-content/uploads/joomla-images/'),
 
    // Rutas con http externas de vivirmejor (si aplica)
    'src="http://www.vivirmejor.com/images/' => 'src="' . site_url('/wp-content/uploads/joomla-images/'),
);
 
$total_updated = 0;
 
foreach ($replacements as $search => $replace) {
    // Actualizar post_content
    $result = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
        $search,
        $replace,
        '%' . $wpdb->esc_like($search) . '%'
    ));
 
    if ($result !== false) {
        echo "Reemplazado '$search' -> " . ($result > 0 ? "$result posts actualizados" : "0 coincidencias") . "\n";
        $total_updated += $result;
    }
}
 
echo "\n========================================\n";
echo "Total de posts actualizados: $total_updated\n";
echo "========================================\n";
 
// Verificar cuántos posts tienen imágenes
$posts_with_images = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE '%<img%' AND post_type = 'post'");
echo "\nPosts con imágenes: $posts_with_images\n";
 
// Verificar si quedan rutas viejas
$old_paths = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE '%src=\"images/%'");
echo "Posts con rutas viejas (images/): $old_paths\n";
 
echo "\n¡¡¡ PROCESO COMPLETADO !!!\n";
echo "</pre>";
?>