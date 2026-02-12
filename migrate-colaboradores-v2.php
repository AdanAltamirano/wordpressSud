<?php
/**
 * Migrar Colaboradores de Joomla ZOO a WordPress como USUARIOS/AUTORES
 * Esto permite que cada colaborador tenga su página de autor con sus artículos
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);

echo "=== MIGRACIÓN DE COLABORADORES COMO AUTORES EN WORDPRESS ===\n\n";

// Conexión a Joomla
$joomla = new mysqli('127.0.0.1', 'root', 'yue02', 'sudcalifornios', 3306);
if ($joomla->connect_error) {
    die("Error conexión Joomla: " . $joomla->connect_error);
}
$joomla->set_charset('utf8mb4');

// Conexión a WordPress
$wordpress = new mysqli('127.0.0.1', 'wpuser', 'MiPassword123', 'wordpress', 3308);
if ($wordpress->connect_error) {
    die("Error conexión WordPress: " . $wordpress->connect_error);
}
$wordpress->set_charset('utf8mb4');

// 1. Obtener colaboradores de Joomla
echo "1. Obteniendo colaboradores de Joomla (tipo 'author')...\n";
$query = "
    SELECT
        i.id as joomla_id,
        i.name,
        i.alias,
        i.elements,
        i.created,
        i.state
    FROM jos_zoo_item i
    WHERE i.type = 'author'
    AND i.state = 1
    ORDER BY i.name ASC
";

$result = $joomla->query($query);
$colaboradores = [];
while ($row = $result->fetch_assoc()) {
    $colaboradores[] = $row;
}
echo "   Encontrados: " . count($colaboradores) . " colaboradores\n\n";

// 2. Crear usuarios en WordPress
echo "2. Creando usuarios en WordPress...\n";
$migrados = 0;
$existentes = 0;
$errores = 0;

foreach ($colaboradores as $colab) {
    $nombre = $colab['name'];
    $alias = $colab['alias'];

    // Crear username único basado en el alias
    // user_nicename tiene límite de 50 caracteres en WordPress
    $username = preg_replace('/[^a-z0-9_-]/', '', strtolower($alias));

    // Si el alias es muy largo, recortamos a 43 chars y agregamos ID (max 6 chars) para hacerlo único
    if (strlen($username) > 50) {
        $id_suffix = '-' . $colab['joomla_id'];
        $max_base = 50 - strlen($id_suffix);
        $username = substr($username, 0, $max_base) . $id_suffix;
    }

    if (empty($username)) {
        $username = 'autor-' . $colab['joomla_id'];
    }

    // Asegurar que NUNCA exceda 50 caracteres
    $nicename = substr($username, 0, 50);

    $username_safe = $wordpress->real_escape_string($username);
    $nicename_safe = $wordpress->real_escape_string($nicename);

    // Verificar si ya existe
    $check = $wordpress->query("SELECT ID FROM wp_users WHERE user_login = '{$username_safe}' OR user_nicename = '{$nicename_safe}'");
    if ($check->num_rows > 0) {
        $existentes++;
        continue;
    }

    // Parsear elements para obtener email y descripción
    $elements = json_decode($colab['elements'], true);
    $descripcion = '';
    $email = '';
    $imagen_url = '';

    if (is_array($elements)) {
        foreach ($elements as $key => $element) {
            if (isset($element['0']['value'])) {
                $value = $element['0']['value'];
                // Buscar descripción/biografía
                if (strpos($key, 'textarea') !== false || strpos($key, 'description') !== false) {
                    if (strlen($value) > strlen($descripcion)) {
                        $descripcion = $value;
                    }
                }
                // Buscar email
                if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $email = $value;
                }
                // Buscar imagen
                if (isset($element['0']['file'])) {
                    $imagen_url = $element['0']['file'];
                }
            }
        }
    }

    // Si no hay email, crear uno ficticio
    if (empty($email)) {
        $email = $username . '@sudcalifornios.com';
    }

    $nombre_safe = $wordpress->real_escape_string($nombre);
    $email_safe = $wordpress->real_escape_string($email);
    $descripcion_safe = $wordpress->real_escape_string(strip_tags($descripcion));
    $fecha = $colab['created'] ?: date('Y-m-d H:i:s');

    // Generar contraseña aleatoria (no se usará para login)
    $password_hash = '$P$B' . substr(md5(uniqid()), 0, 30);

    // Insertar usuario
    $insert_user = "
        INSERT INTO wp_users (
            user_login, user_pass, user_nicename, user_email,
            user_url, user_registered, user_status, display_name
        ) VALUES (
            '{$username_safe}',
            '{$password_hash}',
            '{$nicename_safe}',
            '{$email_safe}',
            '',
            '{$fecha}',
            0,
            '{$nombre_safe}'
        )
    ";

    if ($wordpress->query($insert_user)) {
        $user_id = $wordpress->insert_id;

        // Agregar meta datos del usuario
        $metas = [
            'nickname' => $nombre_safe,
            'first_name' => $nombre_safe,
            'last_name' => '',
            'description' => $descripcion_safe,
            'wp_capabilities' => 'a:1:{s:6:"author";b:1;}',
            'wp_user_level' => '2',
            '_joomla_author_id' => $colab['joomla_id'],
            '_joomla_alias' => $alias
        ];

        foreach ($metas as $meta_key => $meta_value) {
            $meta_key_safe = $wordpress->real_escape_string($meta_key);
            $meta_value_safe = $wordpress->real_escape_string($meta_value);
            $wordpress->query("
                INSERT INTO wp_usermeta (user_id, meta_key, meta_value)
                VALUES ({$user_id}, '{$meta_key_safe}', '{$meta_value_safe}')
            ");
        }

        $migrados++;
        if ($migrados % 50 == 0) {
            echo "   Progreso: {$migrados} usuarios creados...\n";
        }
    } else {
        $errores++;
        echo "   ERROR en '{$nombre}': " . $wordpress->error . "\n";
    }
}

echo "\n=== RESUMEN DE USUARIOS ===\n";
echo "Total colaboradores en Joomla: " . count($colaboradores) . "\n";
echo "Usuarios creados: {$migrados}\n";
echo "Ya existían: {$existentes}\n";
echo "Errores: {$errores}\n";

// 3. Actualizar posts para asignar autores correctos
echo "\n3. Actualizando posts para asignar autores...\n";

// Obtener mapeo de alias de autor -> user_id en WordPress
$author_map = [];
$result = $wordpress->query("
    SELECT u.ID, um.meta_value as joomla_alias
    FROM wp_users u
    INNER JOIN wp_usermeta um ON u.ID = um.user_id
    WHERE um.meta_key = '_joomla_alias'
");
while ($row = $result->fetch_assoc()) {
    $author_map[$row['joomla_alias']] = $row['ID'];
}

// Obtener relación item -> author en Joomla
echo "   Obteniendo relaciones artículo-autor de Joomla...\n";
$query_relations = "
    SELECT
        art.id as article_id,
        art.alias as article_alias,
        auth.alias as author_alias
    FROM jos_zoo_item art
    INNER JOIN jos_zoo_item auth ON art.created_by_alias = auth.name OR art.created_by_alias = auth.alias
    WHERE art.type = 'article'
    AND auth.type = 'author'
";
// Nota: La relación en ZOO puede variar. Vamos a buscar por el campo "created_by_alias" o similar

// Alternativa: buscar en los elements del artículo
$updates = 0;
$result = $joomla->query("
    SELECT id, alias, elements
    FROM jos_zoo_item
    WHERE type = 'article' AND state = 1
");

while ($row = $result->fetch_assoc()) {
    $elements = json_decode($row['elements'], true);
    if (!is_array($elements)) continue;

    // Buscar referencia al autor en los elements
    foreach ($elements as $key => $element) {
        if (strpos($key, 'relateditem') !== false || strpos($key, 'author') !== false) {
            if (isset($element['0']['item'])) {
                $author_joomla_id = $element['0']['item'];

                // Buscar el alias del autor
                $auth_result = $joomla->query("SELECT alias FROM jos_zoo_item WHERE id = {$author_joomla_id}");
                if ($auth_row = $auth_result->fetch_assoc()) {
                    $author_alias = $auth_row['alias'];

                    if (isset($author_map[$author_alias])) {
                        $wp_author_id = $author_map[$author_alias];
                        $article_alias = $wordpress->real_escape_string($row['alias']);

                        $wordpress->query("
                            UPDATE wp_posts
                            SET post_author = {$wp_author_id}
                            WHERE post_name = '{$article_alias}'
                        ");

                        if ($wordpress->affected_rows > 0) {
                            $updates++;
                        }
                    }
                }
            }
        }
    }
}

echo "   Posts actualizados con autor correcto: {$updates}\n";

// 4. Mostrar usuarios creados
echo "\n=== LISTA DE AUTORES EN WORDPRESS ===\n";
$result = $wordpress->query("
    SELECT u.ID, u.display_name, u.user_login,
           (SELECT COUNT(*) FROM wp_posts WHERE post_author = u.ID AND post_status = 'publish') as posts_count
    FROM wp_users u
    INNER JOIN wp_usermeta um ON u.ID = um.user_id
    WHERE um.meta_key = '_joomla_author_id'
    ORDER BY u.display_name
    LIMIT 20
");
echo "Primeros 20 autores:\n";
while ($row = $result->fetch_assoc()) {
    echo "   [{$row['ID']}] {$row['display_name']} (@{$row['user_login']}) - {$row['posts_count']} posts\n";
}

$joomla->close();
$wordpress->close();

echo "\n=== MIGRACIÓN COMPLETADA ===\n";
echo "\nPara ver la página de un autor en WordPress:\n";
echo "URL: http://localhost/index.php/author/[username]/\n";
echo "\nEjemplo: http://localhost/index.php/author/adrian-corona-ibarra/\n";
?>
