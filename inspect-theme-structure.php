<?php
/**
 * Script to inspect the theme template and related files for sidebars.
 * Upload this to your WordPress root directory and access it via browser.
 */

require_once('wp-load.php');

if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Access Denied. You must be an administrator to run this script.' );
}

echo "<h1>Theme & Sidebar Inspector v2</h1>";

// 1. Determine active theme
$theme = wp_get_theme();
$theme_root = get_theme_root();
$template_dir = get_template_directory();

echo "<h2>Active Theme: " . $theme->get('Name') . "</h2>";
echo "Template Directory: $template_dir<br>";

// Helper to inspect a file
function inspect_file($filepath, $name) {
    if ( ! file_exists( $filepath ) ) {
        echo "<h3 style='color:red'>File Not Found: $name</h3>";
        return;
    }

    $content = file_get_contents( $filepath );
    echo "<h3>Analysis of: $name</h3>";
    echo "Path: $filepath<br>";

    $found = false;
    // Check for get_sidebar
    if ( preg_match_all( '/get_sidebar\s*\(\s*(.*?)\s*\)/', $content, $matches ) ) {
        echo "<p style='color:red'>Found `get_sidebar()` calls:</p><ul>";
        foreach ( $matches[0] as $match ) {
            echo "<li><code>" . htmlspecialchars($match) . "</code></li>";
        }
        echo "</ul>";
        $found = true;
    }

    // Check for dynamic_sidebar
    if ( preg_match_all( '/dynamic_sidebar\s*\(\s*(.*?)\s*\)/', $content, $matches ) ) {
        echo "<p style='color:red'>Found `dynamic_sidebar()` calls:</p><ul>";
        foreach ( $matches[0] as $match ) {
            echo "<li><code>" . htmlspecialchars($match) . "</code></li>";
        }
        echo "</ul>";
        $found = true;
    }

    if (!$found) {
        echo "<p>No explicit sidebar calls found.</p>";
    }

    echo "<details><summary>View Code ($name)</summary>";
    echo "<textarea style='width:100%;height:300px;'>" . htmlspecialchars($content) . "</textarea>";
    echo "</details><hr>";
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

// Inspect specific parts
inspect_file( $template_dir . '/header.php', 'header.php' );
inspect_file( $template_dir . '/footer.php', 'footer.php' );
inspect_file( $template_dir . '/sidebar.php', 'sidebar.php' );
inspect_file( $template_dir . '/loop.php', 'loop.php' );

// Inspect functions.php snippet
$funcs = $template_dir . '/functions.php';
if ( file_exists( $funcs ) ) {
    echo "<h3>Check functions.php (first 200 lines)</h3>";
    $lines = file($funcs);
    $snippet = implode("", array_slice($lines, 0, 200));
    if ( preg_match_all( '/register_sidebar\s*\(/', $snippet, $matches ) ) {
        echo "<p>Found `register_sidebar` calls in start of functions.php.</p>";
    }
    echo "<details><summary>View Code (functions.php snippet)</summary>";
    echo "<textarea style='width:100%;height:300px;'>" . htmlspecialchars($snippet) . "</textarea>";
    echo "</details><hr>";
}

// 3. List all PHP files in theme root
echo "<h2>All PHP Files in Theme Root</h2>";
$files = glob( $template_dir . '/*.php' );
if ($files) {
    echo "<ul>";
    foreach ($files as $f) {
        echo "<li>" . basename($f) . "</li>";
    }
    echo "</ul>";
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
