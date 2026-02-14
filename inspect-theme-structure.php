<?php
/**
 * Script to inspect the theme template and related files for sidebars.
 * Upload this to your WordPress root directory and access it via browser.
 */

require_once('wp-load.php');

if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Access Denied. You must be an administrator to run this script.' );
}

echo "<h1>Theme & Sidebar Inspector v1.1</h1>";

// 1. Determine active theme
$theme = wp_get_theme();
$theme_root = get_theme_root();
$template_dir = get_template_directory();

echo "<h2>Active Theme: " . $theme->get('Name') . "</h2>";
echo "Template Directory: $template_dir<br>";

// Helper to inspect a file
function inspect_file($filepath, $name) {
    if ( ! file_exists( $filepath ) ) {
        return; // Silent skip if not found
    }

    $content = file_get_contents( $filepath );
    $found = false;

    // Check for get_sidebar
    if ( preg_match_all( '/get_sidebar\s*\(\s*(.*?)\s*\)/', $content, $matches ) ) {
        echo "<h3>Sidebar call in: $name</h3>";
        echo "<p style='color:red'>Found `get_sidebar()` calls:</p><ul>";
        foreach ( $matches[0] as $match ) {
            echo "<li><code>" . htmlspecialchars($match) . "</code></li>";
        }
        echo "</ul>";
        $found = true;
    }

    // Check for dynamic_sidebar
    if ( preg_match_all( '/dynamic_sidebar\s*\(\s*(.*?)\s*\)/', $content, $matches ) ) {
        if (!$found) echo "<h3>Sidebar call in: $name</h3>";
        echo "<p style='color:red'>Found `dynamic_sidebar()` calls:</p><ul>";
        foreach ( $matches[0] as $match ) {
            echo "<li><code>" . htmlspecialchars($match) . "</code></li>";
        }
        echo "</ul>";
        $found = true;
    }

    if ($found) {
        echo "<details><summary>View Code ($name)</summary>";
        echo "<textarea style='width:100%;height:300px;'>" . htmlspecialchars($content) . "</textarea>";
        echo "</details><hr>";
    }
}

// 2. Identify and inspect key files
// Index template
$template = 'index.php';
if ( 'page' == get_option('show_on_front') ) {
    $fid = get_option('page_on_front');
    $t = get_page_template_slug($fid);
    if ($t) $template = $t;
    else $template = 'page.php';
} else {
    if ( file_exists( $template_dir . '/front-page.php' ) ) $template = 'front-page.php';
    elseif ( file_exists( $template_dir . '/home.php' ) ) $template = 'home.php';
}

echo "<h2>Main Template: $template</h2>";
inspect_file( $template_dir . '/' . $template, $template );

// 3. Scan ALL PHP files in theme recursively
echo "<h2>Scanning entire theme directory...</h2>";
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($template_dir));
foreach ($iterator as $file) {
    if ($file->isDir()) continue;
    if ($file->getExtension() !== 'php') continue;

    $path = $file->getPathname();
    $name = str_replace($template_dir . '/', '', $path);

    // Skip already checked main template
    if ($name == $template) continue;

    inspect_file($path, $name);
}

// 4. Check active plugins that might affect sidebars
echo "<h2>Active Plugins</h2>";
$plugins = get_option('active_plugins');
if ($plugins) {
    echo "<ul>";
    foreach ($plugins as $p) {
        echo "<li>$p</li>";
    }
    echo "</ul>";
}

?>
