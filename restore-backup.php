<?php
/**
 * Restore WordPress backup from SQL dump
 * Usage: php restore-backup.php
 */
set_time_limit(0);
ini_set('memory_limit', '512M');

$backup_file = 'C:/Sudcalifornios/wordpress_backup.sql';
$host = '127.0.0.1';
$port = 3308;
$user = 'root';
$pass = 'yue02';
$db   = 'wordpress';

echo "=== RESTAURAR BACKUP WORDPRESS ===\n\n";

// Check backup exists
if (!file_exists($backup_file)) {
    die("ERROR: No se encontro el archivo: $backup_file\n");
}
echo "Backup: $backup_file\n";
echo "Tamano: " . round(filesize($backup_file)/1024/1024, 1) . " MB\n\n";

// Connect
$conn = new mysqli($host, $user, $pass, $db, $port);
if ($conn->connect_error) {
    die("ERROR conexion: " . $conn->connect_error . "\n");
}
$conn->set_charset("utf8mb4");

// Count before
$r = $conn->query("SELECT COUNT(*) c FROM wp_posts WHERE post_type='post' AND post_status='publish'");
$before = $r->fetch_assoc()['c'];
echo "Posts publicados ANTES: $before\n\n";

echo "Restaurando backup... (esto puede tardar unos minutos)\n";

// Use mysql command line for restore (much faster than PHP line-by-line)
$mysql_cmd = "mysql --host=$host --port=$port -u $user -p$pass $db";

// Check if mysql is available
$descriptorspec = [
    0 => ['file', $backup_file, 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$process = proc_open($mysql_cmd, $descriptorspec, $pipes);

if (is_resource($process)) {
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $return_code = proc_close($process);

    if ($return_code === 0) {
        echo "Restore completado exitosamente!\n\n";
    } else {
        echo "mysql command fallo (code: $return_code).\n";
        if ($stderr) echo "Error: $stderr\n";
        echo "Intentando restauracion via PHP...\n\n";

        // Fallback: PHP-based restore
        php_restore($conn, $backup_file);
    }
} else {
    echo "No se pudo ejecutar mysql command.\n";
    echo "Intentando restauracion via PHP...\n\n";
    php_restore($conn, $backup_file);
}

// Count after
$r = $conn->query("SELECT COUNT(*) c FROM wp_posts WHERE post_type='post' AND post_status='publish'");
$after = $r->fetch_assoc()['c'];
echo "Posts publicados DESPUES: $after\n";
echo "\nRestauracion completada.\n";

$conn->close();

function php_restore($conn, $file) {
    $handle = fopen($file, 'r');
    if (!$handle) {
        die("No se pudo abrir el archivo\n");
    }

    $conn->query("SET FOREIGN_KEY_CHECKS=0");
    $conn->query("SET UNIQUE_CHECKS=0");
    $conn->query("SET AUTOCOMMIT=0");

    $query = '';
    $line_num = 0;
    $queries_run = 0;
    $errors = 0;

    while (!feof($handle)) {
        $line = fgets($handle);
        $line_num++;

        // Skip comments and empty lines
        if (empty(trim($line)) || strpos(trim($line), '--') === 0 || strpos(trim($line), '/*') === 0) {
            // But execute SET commands inside /*!...*/ blocks
            if (preg_match('/^\/\*!\d+\s+(.+)\*\/;$/', trim($line), $m)) {
                $conn->query($m[1]);
            }
            continue;
        }

        $query .= $line;

        if (preg_match('/;\s*$/', trim($line))) {
            $query = trim($query);
            if (!empty($query)) {
                if (!$conn->query($query)) {
                    $errors++;
                    if ($errors <= 5) {
                        echo "Error linea ~$line_num: " . substr($conn->error, 0, 100) . "\n";
                    }
                }
                $queries_run++;
                if ($queries_run % 1000 === 0) {
                    echo "  Procesadas $queries_run queries...\r";
                    $conn->query("COMMIT");
                }
            }
            $query = '';
        }
    }

    $conn->query("COMMIT");
    $conn->query("SET FOREIGN_KEY_CHECKS=1");
    $conn->query("SET UNIQUE_CHECKS=1");
    $conn->query("SET AUTOCOMMIT=1");

    fclose($handle);
    echo "\nProcesadas $queries_run queries";
    if ($errors > 0) echo " ($errors errores)";
    echo "\n\n";
}
