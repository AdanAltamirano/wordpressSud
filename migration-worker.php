<?php
/**
 * Migration Worker
 * Handles the actual data transfer and file copying.
 */

// Increase time limit for image processing
set_time_limit(300);
ini_set('memory_limit', '512M');

require_once('wp-load.php');
require_once('wp-admin/includes/image.php');
require_once('wp-admin/includes/file.php');
require_once('wp-admin/includes/media.php');
require_once('migration-config.php');

$type = isset($_GET['type']) ? $_GET['type'] : 'zoo'; // 'zoo' or 'standard'
$step = isset($_GET['step']) ? $_GET['step'] : 'categories'; // 'categories' or 'posts'
$offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
$batch_size = MIGRATION_BATCH_SIZE;

echo "<html><head><title>Migrando...</title>";
echo "<style>body{font-family:sans-serif; padding:20px;} .log{background:#f0f0f0; padding:10px; border:1px solid #ccc; max-height:400px; overflow:auto;} .success{color:green;} .error{color:red;} .info{color:blue;}</style>";
echo "</head><body>";
echo "<h1>Migrando " . ucfirst($type) . " - " . ucfirst($step) . "</h1>";
echo "<div class='log'>";

// Connect to Joomla
$jdb = get_joomla_connection();

// =================================================================================
// STEP 1: CATEGORIES
// =================================================================================
if ($step == 'categories') {
    if ($type == 'zoo') {
        $table = 'jos_zoo_category';
        $id_col = 'id';
        $parent_col = 'parent';
        $name_col = 'name';
        $alias_col = 'alias';
    } else {
        // Standard Joomla Categories (J2.5/3.0+)
        $table = 'jos_categories';
        $id_col = 'id';
        $parent_col = 'parent_id';
        $name_col = 'title';
        $alias_col = 'alias';
    }

    // Check table exists
    if (!$jdb->query("SHOW TABLES LIKE '$table'")->num_rows) {
        die("<p class='error'>Tabla $table no existe.</p></div></body></html>");
    }

    $q = "SELECT * FROM $table WHERE published = 1 ORDER BY $parent_col ASC";
    $rows = $jdb->query($q);

    $count = 0;
    $updated = 0;

    $cat_map = get_option('joomla_' . $type . '_category_map', []);

    while ($row = $rows->fetch_assoc()) {
        $j_id = $row[$id_col];
        $name = $row[$name_col];
        $slug = $row[$alias_col];
        $parent_j_id = $row[$parent_col];

        // Determine Parent WP ID
        $parent_wp_id = 0;
        if ($parent_j_id > 0 && isset($cat_map[$parent_j_id])) {
            $parent_wp_id = $cat_map[$parent_j_id];
        }

        // Check if exists by Slug
        $existing = get_term_by('slug', $slug, 'category');

        if ($existing) {
            // Update mapping
            $cat_map[$j_id] = $existing->term_id;
            echo "<div class='info'>Existente: $name (ID: {$existing->term_id})</div>";
        } else {
            // Create
            $new_term = wp_insert_term($name, 'category', [
                'slug' => $slug,
                'parent' => $parent_wp_id,
                'description' => 'Importado de Joomla ' . $type
            ]);

            if (is_wp_error($new_term)) {
                echo "<div class='error'>Error creando $name: " . $new_term->get_error_message() . "</div>";
            } else {
                $cat_map[$j_id] = $new_term['term_id'];
                echo "<div class='success'>Creado: $name (ID: {$new_term['term_id']})</div>";
                $count++;
            }
        }
    }

    update_option('joomla_' . $type . '_category_map', $cat_map);
    echo "</div>";
    echo "<h3>Categorías completadas. $count nuevas.</h3>";
    echo "<a href='migration-dashboard.php'>Volver al Dashboard</a>";

// =================================================================================
// STEP 2: POSTS & IMAGES
// =================================================================================
} elseif ($step == 'posts') {

    // Query setup
    if ($type == 'zoo') {
        // Zoo Logic
        $count_q = "SELECT COUNT(*) as c FROM jos_zoo_item WHERE state = 1 AND type IN ('article', 'blog-post', 'page')";
        $data_q = "SELECT * FROM jos_zoo_item WHERE state = 1 AND type IN ('article', 'blog-post', 'page') ORDER BY id ASC LIMIT $offset, $batch_size";
    } else {
        // Standard Logic
        $count_q = "SELECT COUNT(*) as c FROM jos_content WHERE state = 1";
        $data_q = "SELECT * FROM jos_content WHERE state = 1 ORDER BY id ASC LIMIT $offset, $batch_size";
    }

    $total_res = $jdb->query($count_q);
    $total = $total_res->fetch_assoc()['c'];

    $result = $jdb->query($data_q);

    if (!$result) {
        die("Error DB Joomla: " . $jdb->error);
    }

    $processed = 0;

    while ($row = $result->fetch_assoc()) {
        $processed++;

        // Normalize Data
        if ($type == 'zoo') {
            $j_id = $row['id'];
            $title = $row['name'];
            $slug = $row['alias'];
            $created = $row['created'];
            $modified = $row['modified'];

            // Extract Content from Elements JSON
            $content = "";
            $elements = json_decode($row['elements'], true);
            // Rough extraction of text values
            if ($elements) {
                // Try to find specific text areas or specific keys if known.
                // For now, recursively find 'value' keys that look like HTML or text
                array_walk_recursive($elements, function($item, $key) use (&$content) {
                    if ($key === 'value' && is_string($item) && strlen($item) > 0) {
                        // Avoid simple metadata, check if it looks like content
                        // Or just concatenate everything (safest for generic import)
                        $content .= " " . $item;
                    }
                });
            }
            // Fallback: use the raw JSON string for image searching later if needed,
            // but for WP post_content we want readable text.
            if (empty($content)) $content = $row['elements']; // Fallback to raw if empty

        } else {
            $j_id = $row['id'];
            $title = $row['title'];
            $slug = $row['alias'];
            $created = $row['created'];
            $modified = $row['modified'];
            $content = $row['introtext'] . " " . $row['fulltext'];
        }

        // Check if exists in WP
        $meta_key = ($type == 'zoo') ? '_joomla_zoo_id' : '_joomla_id';

        $existing_post = get_posts([
            'meta_key' => $meta_key,
            'meta_value' => $j_id,
            'post_type' => 'post',
            'post_status' => 'any',
            'numberposts' => 1
        ]);

        $post_id = 0;
        $is_update = false;

        if ($existing_post) {
            $post = $existing_post[0];
            $post_id = $post->ID;

            // Check Dates to see if update needed
            // If Joomla Modified > WP Modified
            $wp_mod = $post->post_modified;

            if (strtotime($modified) > strtotime($wp_mod)) {
                echo "<div class='info'>Actualizando ID $j_id (Nueva versión detectada)...</div>";
                $is_update = true;
            } else {
                echo "<div class='success'>Saltando ID $j_id (Ya existe y está actualizado)</div>";
                continue;
            }
        }

        // ---------------------------------------------------------
        // IMAGE MIGRATION LOGIC
        // ---------------------------------------------------------
        // We scan $content AND raw $row data for image paths.
        // We look for local paths relative to Joomla root.

        $image_map = []; // old_url => new_url

        // Find all images
        $matches = [];
        // Match /images/... or "images/...
        preg_match_all('#(?<=["\'])/?images/([^"\']+\.(jpg|jpeg|png|gif))#i', $content . ($type=='zoo' ? $row['elements'] : ''), $matches);

        $unique_images = array_unique($matches[1]); // The 'folder/file.jpg' part
        $featured_image_id = 0;

        foreach ($unique_images as $img_rel_path) {
            // Full source path
            $source_path = JOOMLA_LOCAL_PATH . '/images/' . $img_rel_path;

            // Fix slashes for Windows
            $source_path = str_replace('/', DIRECTORY_SEPARATOR, $source_path);
            $source_path = str_replace('\\', DIRECTORY_SEPARATOR, $source_path);

            if (file_exists($source_path)) {
                // Copy to WP
                $upload_dir = wp_upload_dir();
                $filename = basename($img_rel_path);
                $destination_path = $upload_dir['path'] . '/' . $filename;
                $destination_url = $upload_dir['url'] . '/' . $filename;

                if (!file_exists($destination_path)) {
                    if (copy($source_path, $destination_path)) {
                        // Create Attachment
                        $filetype = wp_check_filetype($filename, null);
                        $attachment = [
                            'guid'           => $destination_url,
                            'post_mime_type' => $filetype['type'],
                            'post_title'     => preg_replace('/\.[^.]+$/', '', $filename),
                            'post_content'   => '',
                            'post_status'    => 'inherit'
                        ];
                        $attach_id = wp_insert_attachment($attachment, $destination_path);
                        // require_once(ABSPATH . 'wp-admin/includes/image.php'); // Already included
                        $attach_data = wp_generate_attachment_metadata($attach_id, $destination_path);
                        wp_update_attachment_metadata($attach_id, $attach_data);

                        echo "<div class='success'>Imagen importada: $filename</div>";
                        if (!$featured_image_id) $featured_image_id = $attach_id;
                    } else {
                        echo "<div class='error'>Fallo al copiar: $source_path</div>";
                    }
                } else {
                    // Already exists in FS, check DB
                    // (Simplified: assuming if file exists, we use it.
                    // ideally we find the attachment ID by guid)
                    global $wpdb;
                    $att_id = $wpdb->get_var($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE guid = %s", $destination_url));
                    if ($att_id) {
                         if (!$featured_image_id) $featured_image_id = $att_id;
                    }
                }

                // Map for replacement
                // Matches /images/path/to/file.jpg
                $image_map['/images/' . $img_rel_path] = $destination_url;
                $image_map['images/' . $img_rel_path] = $destination_url; // Without leading slash
            }
        }

        // Replace images in content
        foreach ($image_map as $old => $new) {
            $content = str_replace($old, $new, $content);
        }

        // ---------------------------------------------------------
        // INSERT / UPDATE POST
        // ---------------------------------------------------------
        $post_data = [
            'post_title'    => $title,
            'post_content'  => $content,
            'post_status'   => 'publish',
            'post_date'     => $created,
            'post_modified' => $modified,
            'post_type'     => 'post',
            'post_author'   => 1
        ];

        if ($is_update) {
            $post_data['ID'] = $post_id;
            wp_update_post($post_data);
            echo "<div class='success'>Post actualizado: $title</div>";
        } else {
            $post_id = wp_insert_post($post_data);
            if (!is_wp_error($post_id)) {
                update_post_meta($post_id, $meta_key, $j_id);
                echo "<div class='success'>Post creado: $title</div>";
            } else {
                echo "<div class='error'>Error creando post: " . $post_id->get_error_message() . "</div>";
            }
        }

        // Set Featured Image
        if ($post_id && !is_wp_error($post_id) && $featured_image_id) {
            set_post_thumbnail($post_id, $featured_image_id);
        }

        // Category Assignment (Zoo)
        if ($type == 'zoo' && $post_id) {
             $cat_map = get_option('joomla_zoo_category_map', []);
             // Fetch categories for this item from jos_zoo_category_item
             $cat_q = "SELECT category_id FROM jos_zoo_category_item WHERE item_id = $j_id";
             $cat_res = $jdb->query($cat_q);
             $wp_cats = [];
             while ($cat_row = $cat_res->fetch_assoc()) {
                 if (isset($cat_map[$cat_row['category_id']])) {
                     $wp_cats[] = $cat_map[$cat_row['category_id']];
                 }
             }
             if (!empty($wp_cats)) {
                 wp_set_post_categories($post_id, $wp_cats);
             }
        } elseif ($type == 'standard' && $post_id) {
            // Standard category logic
            $cat_map = get_option('joomla_standard_category_map', []);
            $cat_id = $row['catid'];
            if (isset($cat_map[$cat_id])) {
                 wp_set_post_categories($post_id, [$cat_map[$cat_id]]);
            }
        }
    }

    echo "</div>";

    // Progress Bar
    $next_offset = $offset + $batch_size;
    $progress = min(100, round(($next_offset / $total) * 100));

    echo "<div style='background:#ddd; height:20px; width:100%; margin:10px 0;'>
            <div style='background:green; height:100%; width:$progress%'></div>
          </div>";
    echo "<p>Progreso: $next_offset / $total</p>";

    if ($next_offset < $total) {
        $next_url = "migration-worker.php?type=$type&step=posts&offset=$next_offset";
        echo "<p>Continuando automáticamente en 2 segundos...</p>";
        echo "<script>setTimeout(function(){ window.location.href='$next_url'; }, 2000);</script>";
        echo "<a href='$next_url'>Clic aquí si no redirige</a>";
    } else {
        echo "<h2>¡Migración Completada!</h2>";
        echo "<a href='migration-dashboard.php'>Volver al Dashboard</a>";
    }

}
?>
</body>
</html>
