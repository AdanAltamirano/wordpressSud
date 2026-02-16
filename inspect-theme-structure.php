<?php
/**
 * Script to inspect the theme template for the home page.
 * Upload this to your WordPress root directory and access it via browser.
 */

require_once('wp-load.php');

if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Access Denied. You must be an administrator to run this script.' );
}

echo "<h1>Home Page Template Inspector</h1>";

// 1. Determine active theme
$theme = wp_get_theme();
echo "<h2>Active Theme: " . $theme->get('Name') . " (" . $theme->get_template() . ")</h2>";
echo "Theme Root: " . get_theme_root() . "<br>";
echo "Template Directory: " . get_template_directory() . "<br>";

// 2. Identify front page template
$template = '';

if ( 'page' == get_option('show_on_front') ) {
    $front_page_id = get_option('page_on_front');
    echo "<h3>Home is a Static Page (ID: $front_page_id)</h3>";
    $template = get_page_template_slug( $front_page_id );
    if ( ! $template ) {
        $template = 'page.php'; // Default fallback
    } else {
        echo "Assigned Template Slug: $template<br>";
    }
} else {
    echo "<h3>Home displays Latest Posts</h3>";
    $template = 'front-page.php';
    if ( ! file_exists( get_template_directory() . '/' . $template ) ) {
        $template = 'home.php';
        if ( ! file_exists( get_template_directory() . '/' . $template ) ) {
            $template = 'index.php';
        }
    }
}

$template_path = get_template_directory() . '/' . $template;
if ( ! file_exists( $template_path ) && $theme->get_stylesheet() != $theme->get_template() ) {
    // Check child theme
    $template_path = get_stylesheet_directory() . '/' . $template;
}

echo "<h3>Using Template File: $template</h3>";
echo "Path: $template_path<br>";

if ( file_exists( $template_path ) ) {
    $content = file_get_contents( $template_path );
    echo "<h4>File Content Analysis:</h4>";
    
    // Check for get_sidebar
    if ( preg_match_all( '/get_sidebar\s*\(\s*(.*?)\s*\)/', $content, $matches ) ) {
        echo "<p style='color:red'>Found `get_sidebar()` calls:</p><ul>";
        foreach ( $matches[0] as $match ) {
            echo "<li><code>" . htmlspecialchars($match) . "</code></li>";
        }
        echo "</ul>";
    } else {
        echo "<p>No explicit `get_sidebar()` found in this file.</p>";
    }

    // Check for dynamic_sidebar
    if ( preg_match_all( '/dynamic_sidebar\s*\(\s*(.*?)\s*\)/', $content, $matches ) ) {
        echo "<p style='color:red'>Found `dynamic_sidebar()` calls:</p><ul>";
        foreach ( $matches[0] as $match ) {
            echo "<li><code>" . htmlspecialchars($match) . "</code></li>";
        }
        echo "</ul>";
    } else {
        echo "<p>No explicit `dynamic_sidebar()` found in this file.</p>";
    }

    echo "<hr><h4>Full Source Code of $template:</h4>";
    echo "<textarea style='width:100%;height:400px;'>" . htmlspecialchars($content) . "</textarea>";
} else {
    echo "<p style='color:red'>Template file not found at: $template_path</p>";
}

// 3. Check for Page Builder usage (TagDiv Composer)
if ( 'page' == get_option('show_on_front') ) {
    $post = get_post( get_option('page_on_front') );
    if ( $post ) {
        echo "<hr><h3>Page Content (First 1000 chars):</h3>";
        echo "<pre>" . htmlspecialchars( substr($post->post_content, 0, 1000) ) . "</pre>";
        
        if ( strpos( $post->post_content, '[vc_column_text]' ) !== false || strpos( $post->post_content, 'td_block' ) !== false ) {
            echo "<p style='color:orange'><strong>Note:</strong> This page seems to use a Page Builder (Visual Composer or TagDiv Composer).</p>";
            echo "<p>If you see a sidebar in the builder AND the page template also has a sidebar, this causes duplication.</p>";
        }
    }
}
?>
