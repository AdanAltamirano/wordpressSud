<?php
/**
 * Script to fix author mapping from Joomla Zoo to WordPress
 *
 * Reads the Zoo elements JSON to find the real author reference,
 * then maps it to the correct WordPress user.
 *
 * Usage: Run from command line: php fix-authors.php
 * Or visit: http://localhost/fix-authors.php
 */

// Joomla database connection
$joomla_host = '127.0.0.1';
$joomla_port = 3306;
$joomla_user = 'root';
$joomla_pass = 'yue02';
$joomla_db   = 'sudcalifornios';

// WordPress database connection
$wp_host = '127.0.0.1';
$wp_port = 3308;
$wp_user = 'wpuser';
$wp_pass = 'MiPassword123';
$wp_db   = 'wordpress';

// The UUID of the "author" element in Zoo
$author_element_uuid = 'fc5a6788-ffae-41d9-a812-3530331fef64';

echo "<pre>\n";
echo "=== Fix Author Mapping: Joomla Zoo -> WordPress ===\n\n";

// Connect to Joomla DB
$joomla_conn = new mysqli($joomla_host, $joomla_user, $joomla_pass, $joomla_db, $joomla_port);
if ($joomla_conn->connect_error) {
    die("Joomla DB connection failed: " . $joomla_conn->connect_error . "\n");
}
$joomla_conn->set_charset("utf8mb4");
echo "Connected to Joomla DB.\n";

// Connect to WordPress DB
$wp_conn = new mysqli($wp_host, $wp_user, $wp_pass, $wp_db, $wp_port);
if ($wp_conn->connect_error) {
    die("WordPress DB connection failed: " . $wp_conn->connect_error . "\n");
}
$wp_conn->set_charset("utf8mb4");
echo "Connected to WordPress DB.\n\n";

// Step 1: Build a map of Zoo author item IDs -> author names
echo "Step 1: Building Zoo author name map...\n";
$author_map = [];
$result = $joomla_conn->query("SELECT id, name FROM jos_zoo_item WHERE type = 'author'");
while ($row = $result->fetch_assoc()) {
    $author_map[$row['id']] = $row['name'];
}
echo "  Found " . count($author_map) . " Zoo authors.\n\n";

// Step 2: Build a map of WordPress user display_name -> user ID
echo "Step 2: Building WordPress user map...\n";
$wp_user_map = [];
$wp_user_slug_map = [];
$result = $wp_conn->query("SELECT ID, user_login, display_name FROM wp_users");
while ($row = $result->fetch_assoc()) {
    // Map by display_name (case-insensitive)
    $wp_user_map[mb_strtolower(trim($row['display_name']))] = $row['ID'];
    // Also map by user_login
    $wp_user_slug_map[mb_strtolower(trim($row['user_login']))] = $row['ID'];
}
echo "  Found " . count($wp_user_map) . " WordPress users.\n\n";

// Step 3: Read all Zoo articles and extract author references
echo "Step 3: Reading Zoo articles and extracting author references...\n";
$zoo_article_authors = []; // alias -> author_name
$result = $joomla_conn->query("SELECT id, alias, name, elements FROM jos_zoo_item WHERE type = 'article'");
$total_articles = 0;
$articles_with_author = 0;
$articles_without_author = 0;

while ($row = $result->fetch_assoc()) {
    $total_articles++;
    $elements = json_decode($row['elements'], true);

    if ($elements && isset($elements[$author_element_uuid]['item']['0'])) {
        $author_id = $elements[$author_element_uuid]['item']['0'];
        if (isset($author_map[$author_id])) {
            $zoo_article_authors[$row['alias']] = $author_map[$author_id];
            $articles_with_author++;
        } else {
            $articles_without_author++;
        }
    } else {
        $articles_without_author++;
    }
}
echo "  Total Zoo articles: $total_articles\n";
echo "  Articles with author reference: $articles_with_author\n";
echo "  Articles without author reference: $articles_without_author\n\n";

// Step 4: Match WordPress posts to Zoo articles by slug and update author
echo "Step 4: Matching and updating WordPress posts...\n";

// Get all WordPress posts that are assigned to admin (ID=1)
$result = $wp_conn->query("SELECT ID, post_name, post_title, post_author FROM wp_posts WHERE post_type = 'post' AND post_status = 'publish'");

$updated = 0;
$not_found_author = 0;
$not_found_wp_user = 0;
$already_correct = 0;
$skipped = 0;
$missing_authors = [];

$update_stmt = $wp_conn->prepare("UPDATE wp_posts SET post_author = ? WHERE ID = ?");

while ($row = $result->fetch_assoc()) {
    $wp_slug = $row['post_name'];

    // Try to find matching Zoo article by alias (slug)
    $zoo_author_name = null;

    // Direct match
    if (isset($zoo_article_authors[$wp_slug])) {
        $zoo_author_name = $zoo_article_authors[$wp_slug];
    } else {
        // Try variations (WordPress may modify slugs)
        $slug_variations = [
            $wp_slug,
            $wp_slug . '-2',
            preg_replace('/-\d+$/', '', $wp_slug), // Remove trailing number
        ];
        foreach ($slug_variations as $slug) {
            if (isset($zoo_article_authors[$slug])) {
                $zoo_author_name = $zoo_article_authors[$slug];
                break;
            }
        }
    }

    if (!$zoo_author_name) {
        $not_found_author++;
        continue;
    }

    // Find WordPress user by author name
    $author_name_lower = mb_strtolower(trim($zoo_author_name));
    $wp_user_id = null;

    if (isset($wp_user_map[$author_name_lower])) {
        $wp_user_id = $wp_user_map[$author_name_lower];
    } else {
        // Try to create a slug from the author name and match
        $author_slug = sanitize_slug($zoo_author_name);
        if (isset($wp_user_slug_map[$author_slug])) {
            $wp_user_id = $wp_user_slug_map[$author_slug];
        }
    }

    if (!$wp_user_id) {
        $not_found_wp_user++;
        if (!isset($missing_authors[$zoo_author_name])) {
            $missing_authors[$zoo_author_name] = 0;
        }
        $missing_authors[$zoo_author_name]++;
        continue;
    }

    // Check if already assigned correctly
    if ($row['post_author'] == $wp_user_id) {
        $already_correct++;
        continue;
    }

    // Update the post author
    $update_stmt->bind_param("ii", $wp_user_id, $row['ID']);
    $update_stmt->execute();
    $updated++;
}

$update_stmt->close();

echo "\n=== RESULTS ===\n";
echo "Posts updated with correct author: $updated\n";
echo "Posts already had correct author: $already_correct\n";
echo "Posts where Zoo author not found by slug: $not_found_author\n";
echo "Posts where WP user not found for author name: $not_found_wp_user\n";

if (!empty($missing_authors)) {
    echo "\n=== Missing WP users for these Zoo authors ===\n";
    arsort($missing_authors);
    foreach (array_slice($missing_authors, 0, 30) as $name => $count) {
        echo "  '$name' ($count posts)\n";
    }
}

echo "\nDone!\n";
echo "</pre>\n";

$joomla_conn->close();
$wp_conn->close();

function sanitize_slug($str) {
    $str = mb_strtolower(trim($str));
    // Remove accents
    $str = strtr($str, [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u',
        'ñ'=>'n','ü'=>'u','Á'=>'a','É'=>'e','Í'=>'i',
        'Ó'=>'o','Ú'=>'u','Ñ'=>'n','Ü'=>'u'
    ]);
    $str = preg_replace('/[^a-z0-9\s-]/', '', $str);
    $str = preg_replace('/[\s-]+/', '-', $str);
    $str = trim($str, '-');
    return $str;
}
