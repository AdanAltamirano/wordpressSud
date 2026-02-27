<?php
// Script to replace the old banner with the new one for E-Commerce 365.
// This script will search for 'visitehotelesdemexico.com' and replace the entire HTML structure.

require_once 'wp-load.php';

// Check permissions
if (php_sapi_name() !== 'cli' && !current_user_can('manage_options')) {
    die("Access denied. You must be an administrator to run this script.");
}

$new_html = '<a href="https://www.ecommerce-365.com/" target="_blank" style="display:block; background-color: #D32F2F; text-align: center; padding: 20px;"><img src="' . content_url('/uploads/logo/E-Commerce-365-Logo_BCO.png') . '" alt="E-Commerce 365" style="max-width: 100%; height: auto;"></a>';
$search_term = 'visitehotelesdemexico.com';

global $wpdb;

echo "Starting banner replacement...\n";

// Recursive function for serialized data
function recursive_replace_banner($data, $search, $replace, &$modified) {
    if (is_string($data)) {
        // Regex to find the anchor tag containing the search term.
        $pattern = '/<a\s+[^>]*href=["\'][^"\']*' . preg_quote($search, '/') . '[^"\']*["\'][^>]*>.*?<\/a>/is';
        if (preg_match($pattern, $data, $matches)) {
            $data = str_replace($matches[0], $replace, $data);
            $modified = true;
        }
    } elseif (is_array($data)) {
        foreach ($data as $k => $v) {
            $data[$k] = recursive_replace_banner($v, $search, $replace, $modified);
        }
    } elseif (is_object($data)) {
        foreach ($data as $k => $v) {
            $data->$k = recursive_replace_banner($v, $search, $replace, $modified);
        }
    }
    return $data;
}


// 1. Search and Replace in wp_posts
$posts = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT ID, post_content FROM $wpdb->posts WHERE post_content LIKE %s",
        '%' . $wpdb->esc_like($search_term) . '%'
    )
);

echo "Found " . count($posts) . " posts containing the search term.\n";

foreach ($posts as $post) {
    $content = $post->post_content;
    $pattern = '/<a\s+[^>]*href=["\'][^"\']*' . preg_quote($search_term, '/') . '[^"\']*["\'][^>]*>.*?<\/a>/is';

    if (preg_match($pattern, $content, $matches)) {
        $new_content = str_replace($matches[0], $new_html, $content);
        $wpdb->update(
            $wpdb->posts,
            ['post_content' => $new_content],
            ['ID' => $post->ID]
        );
        echo "Updated Post ID: $post->ID\n";
    } else {
        echo "Search term found in Post ID: $post->ID but regex did not match a full anchor tag.\n";
    }
}

// 2. Search and Replace in wp_options (Widgets)
$options = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT option_id, option_name, option_value FROM $wpdb->options WHERE option_value LIKE %s",
        '%' . $wpdb->esc_like($search_term) . '%'
    )
);

echo "Found " . count($options) . " options containing the search term.\n";

foreach ($options as $option) {
    $modified = false;
    $value = $option->option_value;

    if (is_serialized($value)) {
        $data = @unserialize($value);
        if ($data !== false) {
            $new_data = recursive_replace_banner($data, $search_term, $new_html, $modified);
            if ($modified) {
                $wpdb->update(
                    $wpdb->options,
                    ['option_value' => serialize($new_data)],
                    ['option_id' => $option->option_id]
                );
                echo "Updated serialized Option: $option->option_name\n";
            }
        }
    } else {
        // String replacement
        $pattern = '/<a\s+[^>]*href=["\'][^"\']*' . preg_quote($search_term, '/') . '[^"\']*["\'][^>]*>.*?<\/a>/is';
        if (preg_match($pattern, $value, $matches)) {
            $new_value = str_replace($matches[0], $new_html, $value);
            $wpdb->update(
                $wpdb->options,
                ['option_value' => $new_value],
                ['option_id' => $option->option_id]
            );
            echo "Updated string Option: $option->option_name\n";
        }
    }
}

echo "Done.\n";
