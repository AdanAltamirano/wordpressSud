<?php
/**
 * Script de migración de ZOO Authors a WordPress
 * Ejecutar desde: http://localhost/wordpress/migrate-authors.php
 */
 
set_time_limit(300);
ini_set('max_execution_time', 300);
ini_set('memory_limit', '512M');
define('WP_IMPORTING', true);
 
require_once('wp-load.php');
 
// Conexión a Joomla MySQL 5.7
$joomla_db = new mysqli('127.0.0.1', 'root', 'yue02', 'sudcalifornios', 3306);
 
if ($joomla_db->connect_error) {
    die("Error de conexión: " . $joomla_db->connect_error);
}
 
$joomla_db->set_charset("utf8");
 
echo "<h1>Migración de ZOO Authors a WordPress</h1>";
echo "<pre>";
 
// Contar total de authors publicados
$count_result = $joomla_db->query("SELECT COUNT(*) as total FROM jos_zoo_item WHERE state = 1 AND type = 'author'");
$total = $count_result->fetch_assoc()['total'];
echo "Total de Authors publicados: $total\n\n";
 
// Obtener todos los authors
$query = "SELECT id, name, alias, elements, created
          FROM jos_zoo_item
          WHERE state = 1 AND type = 'author'
          ORDER BY id";
 
$result = $joomla_db->query($query);
 
$imported = 0;
$skipped = 0;
$errors = 0;
 
while ($row = $result->fetch_assoc()) {
    $name = $row['name'];
    $slug = $row['alias'];
 
    // Verificar si ya existe
    global $wpdb;
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'author'",
        $slug
    ));
 
    if ($exists) {
        $skipped++;
        continue;
    }
 
    // Decodificar elementos JSON
    $elements = json_decode($row['elements'], true);
 
    $description = '';
    $email = '';
    $website = '';
    $image = '';
 
    if ($elements) {
        foreach ($elements as $key => $element) {
            // Descripción
            if (isset($element['0']['value']) && !empty($element['0']['value'])) {
                $description .= $element['0']['value'];
            }
            // Email
            if (isset($element['value']) && strpos($element['value'], '@') !== false) {
                $email = $element['value'];
            }
            // Imagen
            if (isset($element['file']) && !empty($element['file'])) {
                $image = $element['file'];
            }
        }
    }
 
    // Crear author como Custom Post Type
    $post_id = wp_insert_post(array(
        'post_title'    => wp_strip_all_tags($name),
        'post_content'  => stripslashes($description),
        'post_status'   => 'publish',
        'post_date'     => $row['created'],
        'post_name'     => $slug,
        'post_type'     => 'post', // Lo creamos como post, después puedes cambiar a CPT
        'post_author'   => 1,
    ), true);
 
    if (is_wp_error($post_id)) {
        echo "ERROR: $name\n";
        $errors++;
    } else {
        // Guardar metadatos
        update_post_meta($post_id, '_joomla_zoo_id', $row['id']);
        update_post_meta($post_id, '_author_type', 'zoo_author');
        if ($email) update_post_meta($post_id, '_author_email', $email);
        if ($image) update_post_meta($post_id, '_author_image', $image);
 
        // Asignar categoría "Autores"
        wp_set_object_terms($post_id, 'Autores', 'category', false);
 
        echo "OK: $name\n";
        $imported++;
    }
}
 
echo "\n========================================\n";
echo "Importados: $imported\n";
echo "Omitidos: $skipped\n";
echo "Errores: $errors\n";
echo "========================================\n";
echo "\n¡¡¡ MIGRACIÓN DE AUTHORS COMPLETADA !!!\n";
 
echo "</pre>";
$joomla_db->close();
?>
 