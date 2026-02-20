<?php
/**
 * WordPress Database Backup Tool
 *
 * Crea un backup SQL de la base de datos de WordPress antes de hacer cambios.
 * Ejecutar desde: http://localhost/wp-backup.php
 *
 * Acciones:
 *   (default)         -> Muestra interfaz para crear/descargar/restaurar backups
 *   ?action=create    -> Crear nuevo backup
 *   ?action=download&file=X -> Descargar un backup
 *   ?action=restore&file=X  -> Restaurar un backup (PELIGROSO)
 *   ?action=delete&file=X   -> Eliminar un backup
 */

set_time_limit(600);
ini_set('memory_limit', '512M');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . '/wp-load.php');
require_once(__DIR__ . '/migration-config.php');

// Backup directory
$backup_dir = ABSPATH . 'wp-content/backups';
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0755, true);
    // Protect with .htaccess
    file_put_contents($backup_dir . '/.htaccess', "Order deny,allow\nDeny from all\n");
}

$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$file = isset($_GET['file']) ? basename($_GET['file']) : '';

// Security: only allow .sql files
if ($file && !preg_match('/^wp-backup-[\d\-]+\.sql$/', $file)) {
    die('Nombre de archivo no válido.');
}

// ============================================================
// HTML
// ============================================================
function backup_header($title) {
    echo "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'><title>$title</title>";
    echo "<style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f0f1; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; }
        .card { background: #fff; border: 1px solid #ddd; padding: 20px; border-radius: 6px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        h1 { color: #1d2327; margin-top: 0; }
        h2 { color: #2271b1; margin-top: 0; }
        .btn { display: inline-block; padding: 10px 20px; border-radius: 4px; text-decoration: none; font-weight: 600; margin: 3px; font-size: 14px; border: none; cursor: pointer; }
        .btn-primary { background: #2271b1; color: #fff; }
        .btn-success { background: #00a32a; color: #fff; }
        .btn-warning { background: #dba617; color: #fff; }
        .btn-danger { background: #d63638; color: #fff; }
        .btn-secondary { background: #f0f0f1; color: #2271b1; border: 1px solid #2271b1; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 15px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f6f7f7; }
        .warning-box { background: #fff3cd; border: 2px solid #ffc107; padding: 15px; border-radius: 4px; margin: 15px 0; color: #856404; }
        .success-box { background: #d4edda; border: 2px solid #28a745; padding: 15px; border-radius: 4px; margin: 15px 0; color: #155724; }
        .error-box { background: #f8d7da; border: 2px solid #dc3545; padding: 15px; border-radius: 4px; margin: 15px 0; color: #721c24; }
        .nav { margin-bottom: 20px; padding: 15px; background: #fff; border-radius: 6px; border: 1px solid #ddd; }
        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-box { background: #f9f9f9; border: 1px solid #eee; padding: 15px; border-radius: 4px; text-align: center; }
        .stat-box .number { font-size: 1.8em; font-weight: bold; color: #2271b1; }
        .stat-box .label { color: #646970; font-size: 0.9em; }
        .log { background: #1d2327; color: #50c878; padding: 15px; border-radius: 4px; font-family: Consolas, monospace; font-size: 13px; max-height: 400px; overflow-y: auto; }
        .log .info { color: #6bcbff; }
        .log .success { color: #50c878; }
        .log .error { color: #ff6b6b; }
    </style></head><body><div class='container'>";
    echo "<div class='nav'><strong>Backup WP:</strong> ";
    echo "<a href='wp-backup.php' class='btn btn-primary'>Ver Backups</a> ";
    echo "<a href='wp-backup.php?action=create' class='btn btn-success'>Crear Backup</a> ";
    echo "<a href='sync-joomla.php' class='btn btn-secondary'>Volver a Sync</a>";
    echo "</div>";
}

function backup_footer() {
    echo "</div></body></html>";
}

function format_size($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' bytes';
}

// ============================================================
// CREATE BACKUP
// ============================================================
function create_backup($backup_dir) {
    global $wpdb;

    backup_header('Creando Backup');

    $timestamp = date('Y-m-d-His');
    $filename = "wp-backup-$timestamp.sql";
    $filepath = $backup_dir . '/' . $filename;

    echo "<div class='card'><h2>Creando Backup de Base de Datos</h2>";
    echo "<div class='log'>";

    $tables = $wpdb->get_col("SHOW TABLES");
    $total_tables = count($tables);

    echo "<div class='info'>Encontradas $total_tables tablas</div>";

    $sql = "-- WordPress Database Backup\n";
    $sql .= "-- Fecha: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- Base de datos: " . DB_NAME . "\n";
    $sql .= "-- Generado por: wp-backup.php\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n";
    $sql .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
    $sql .= "SET AUTOCOMMIT=0;\n";
    $sql .= "START TRANSACTION;\n\n";

    $fp = fopen($filepath, 'w');
    if (!$fp) {
        echo "<div class='error'>No se pudo crear el archivo: $filepath</div>";
        echo "</div></div>";
        backup_footer();
        return;
    }

    fwrite($fp, $sql);

    foreach ($tables as $idx => $table) {
        echo "<div class='info'>Respaldando: $table (" . ($idx + 1) . "/$total_tables)</div>";
        if (ob_get_level()) ob_flush();
        flush();

        // Table structure
        $create = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
        fwrite($fp, "-- Tabla: $table\n");
        fwrite($fp, "DROP TABLE IF EXISTS `$table`;\n");
        fwrite($fp, $create[1] . ";\n\n");

        // Table data
        $rows = $wpdb->get_results("SELECT * FROM `$table`", ARRAY_A);
        $count = count($rows);

        if ($count > 0) {
            $columns = array_keys($rows[0]);
            $col_list = '`' . implode('`, `', $columns) . '`';

            // Insert in batches of 100
            $batch = [];
            foreach ($rows as $i => $row) {
                $values = [];
                foreach ($row as $val) {
                    if ($val === null) {
                        $values[] = 'NULL';
                    } else {
                        $values[] = "'" . $wpdb->_real_escape($val) . "'";
                    }
                }
                $batch[] = '(' . implode(', ', $values) . ')';

                if (count($batch) >= 100 || $i === $count - 1) {
                    fwrite($fp, "INSERT INTO `$table` ($col_list) VALUES\n" . implode(",\n", $batch) . ";\n\n");
                    $batch = [];
                }
            }

            echo "<div class='success'>  -> $count registros</div>";
        }
    }

    fwrite($fp, "\nCOMMIT;\nSET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fp);

    $size = filesize($filepath);
    echo "<div class='success'>Backup creado: $filename (" . format_size($size) . ")</div>";
    echo "</div></div>";

    echo "<div class='success-box'>Backup creado exitosamente: <strong>$filename</strong> (" . format_size($size) . ")</div>";
    echo "<div class='card'>";
    echo "<a href='wp-backup.php' class='btn btn-primary'>Ver Todos los Backups</a> ";
    echo "<a href='wp-backup.php?action=download&file=" . urlencode($filename) . "' class='btn btn-success'>Descargar</a> ";
    echo "<a href='sync-joomla.php?action=audit' class='btn btn-secondary'>Ir a Sincronización</a>";
    echo "</div>";

    backup_footer();
}

// ============================================================
// LIST BACKUPS
// ============================================================
function list_backups($backup_dir) {
    global $wpdb;

    backup_header('Backups de WordPress');

    // DB Stats
    $posts = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='post' AND post_status='publish'");
    $cats = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->term_taxonomy WHERE taxonomy='category'");
    $attachments = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='attachment'");
    $users = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->users");

    echo "<div class='stat-grid'>";
    echo "<div class='stat-box'><div class='number'>$posts</div><div class='label'>Posts Publicados</div></div>";
    echo "<div class='stat-box'><div class='number'>$cats</div><div class='label'>Categorías</div></div>";
    echo "<div class='stat-box'><div class='number'>$attachments</div><div class='label'>Imágenes</div></div>";
    echo "<div class='stat-box'><div class='number'>$users</div><div class='label'>Usuarios</div></div>";
    echo "</div>";

    echo "<div class='card'><h2>Backups Disponibles</h2>";
    echo "<p><a href='wp-backup.php?action=create' class='btn btn-success'>Crear Nuevo Backup</a></p>";

    $files = glob($backup_dir . '/wp-backup-*.sql');
    rsort($files); // Most recent first

    if (empty($files)) {
        echo "<div class='warning-box'>No hay backups. Crea uno antes de hacer cambios.</div>";
    } else {
        echo "<table>";
        echo "<tr><th>Archivo</th><th>Fecha</th><th>Tamaño</th><th>Acciones</th></tr>";
        foreach ($files as $f) {
            $fname = basename($f);
            $size = format_size(filesize($f));
            $date = date('d/m/Y H:i:s', filemtime($f));
            echo "<tr>";
            echo "<td><strong>$fname</strong></td>";
            echo "<td>$date</td>";
            echo "<td>$size</td>";
            echo "<td>";
            echo "<a href='wp-backup.php?action=download&file=" . urlencode($fname) . "' class='btn btn-primary' style='padding:5px 10px;font-size:12px;'>Descargar</a> ";
            echo "<a href='wp-backup.php?action=restore&file=" . urlencode($fname) . "' class='btn btn-warning' style='padding:5px 10px;font-size:12px;' onclick=\"return confirm('ADVERTENCIA: Esto reemplazará TODA la base de datos actual. ¿Estás seguro?')\">Restaurar</a> ";
            echo "<a href='wp-backup.php?action=delete&file=" . urlencode($fname) . "' class='btn btn-danger' style='padding:5px 10px;font-size:12px;' onclick=\"return confirm('¿Eliminar este backup?')\">Eliminar</a>";
            echo "</td></tr>";
        }
        echo "</table>";
    }
    echo "</div>";

    echo "<div class='warning-box'>";
    echo "<strong>Recomendaciones:</strong><br>";
    echo "1. Siempre crea un backup ANTES de ejecutar una sincronización<br>";
    echo "2. Si algo sale mal, usa 'Restaurar' para volver al estado anterior<br>";
    echo "3. Los backups se guardan en <code>wp-content/backups/</code><br>";
    echo "4. Los backups incluyen TODA la base de datos (posts, categorías, usuarios, opciones, etc.)";
    echo "</div>";

    backup_footer();
}

// ============================================================
// DOWNLOAD BACKUP
// ============================================================
function download_backup($backup_dir, $file) {
    $filepath = $backup_dir . '/' . $file;
    if (!file_exists($filepath)) {
        die('Archivo no encontrado.');
    }

    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . filesize($filepath));
    readfile($filepath);
    exit;
}

// ============================================================
// RESTORE BACKUP
// ============================================================
function restore_backup($backup_dir, $file) {
    global $wpdb;

    $filepath = $backup_dir . '/' . $file;
    if (!file_exists($filepath)) {
        die('Archivo no encontrado.');
    }

    backup_header('Restaurando Backup');

    echo "<div class='card'><h2>Restaurando: $file</h2>";
    echo "<div class='warning-box'>Restaurando base de datos... NO cierres esta ventana.</div>";
    echo "<div class='log'>";

    $sql = file_get_contents($filepath);

    // Split into individual statements
    // Simple approach: split by semicolon followed by newline
    $statements = array_filter(array_map('trim', explode(";\n", $sql)));

    $executed = 0;
    $errors_list = 0;
    $total = count($statements);

    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if (empty($stmt) || strpos($stmt, '--') === 0) continue;

        // Remove trailing semicolons
        $stmt = rtrim($stmt, ';');
        if (empty($stmt)) continue;

        $result = $wpdb->query($stmt);
        if ($result === false) {
            $err = $wpdb->last_error;
            // Ignore minor errors
            if (strpos($err, 'already exists') === false && strpos($err, 'Duplicate') === false) {
                echo "<div class='error'>Error: $err</div>";
                $errors_list++;
            }
        }
        $executed++;

        if ($executed % 100 === 0) {
            echo "<div class='info'>Ejecutados $executed / ~$total statements...</div>";
            if (ob_get_level()) ob_flush();
            flush();
        }
    }

    echo "<div class='success'>Restauración completada: $executed statements ejecutados, $errors_list errores</div>";
    echo "</div></div>";

    if ($errors_list === 0) {
        echo "<div class='success-box'>Base de datos restaurada exitosamente desde <strong>$file</strong></div>";
    } else {
        echo "<div class='warning-box'>Restauración completada con $errors_list errores menores. Verifica que todo funcione correctamente.</div>";
    }

    echo "<div class='card'>";
    echo "<a href='wp-backup.php' class='btn btn-primary'>Ver Backups</a> ";
    echo "<a href='sync-joomla.php?action=audit' class='btn btn-secondary'>Verificar con Auditoría</a>";
    echo "</div>";

    backup_footer();
}

// ============================================================
// DELETE BACKUP
// ============================================================
function delete_backup($backup_dir, $file) {
    $filepath = $backup_dir . '/' . $file;
    if (file_exists($filepath)) {
        unlink($filepath);
    }
    header('Location: wp-backup.php?deleted=1');
    exit;
}

// ============================================================
// ROUTER
// ============================================================
switch ($action) {
    case 'create':
        create_backup($backup_dir);
        break;
    case 'download':
        download_backup($backup_dir, $file);
        break;
    case 'restore':
        if (!$file) die('No se especificó archivo.');
        restore_backup($backup_dir, $file);
        break;
    case 'delete':
        if (!$file) die('No se especificó archivo.');
        delete_backup($backup_dir, $file);
        break;
    default:
        list_backups($backup_dir);
}
