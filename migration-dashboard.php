<?php
/**
 * Migration Dashboard
 *
 * Provides a UI for the user to:
 * 1. Analyze the current state of Joomla vs WordPress content.
 * 2. Trigger incremental updates.
 */

// Load WordPress context
require_once('wp-load.php');

// Include our analysis logic
require_once('migration-analyze.php');
require_once('migration-config.php');

// Perform analysis
$stats = get_migration_stats();
if (isset($stats['error'])) {
    die("<div style='color:red; font-size:20px;'>ERROR: " . $stats['error'] . "</div>");
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard de Migración Joomla -> WordPress</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; background: #f0f0f1; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; background: #fff; padding: 30px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-radius: 4px; }
        h1 { margin-top: 0; color: #1d2327; }
        .card-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 20px; }
        .card { background: #f9f9f9; border: 1px solid #ddd; padding: 20px; border-radius: 4px; }
        .card h3 { margin-top: 0; color: #2271b1; }
        .stat-row { display: flex; justify-content: space-between; margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 5px; }
        .stat-label { font-weight: 600; color: #646970; }
        .stat-value { font-weight: bold; color: #1d2327; }
        .action-btn { display: inline-block; background: #2271b1; color: #fff; padding: 10px 20px; text-decoration: none; border-radius: 4px; font-weight: 600; margin-top: 10px; text-align: center; }
        .action-btn:hover { background: #135e96; }
        .action-btn.secondary { background: #f6f7f7; color: #2271b1; border: 1px solid #2271b1; }
        .action-btn.secondary:hover { background: #f0f0f1; }
        .warning { color: #d63638; font-weight: bold; }
        .success { color: #00a32a; font-weight: bold; }
    </style>
</head>
<body>

<div class="container">
    <h1>Dashboard de Migración Incremental</h1>

    <p>Utiliza esta herramienta para migrar contenido nuevo desde tu instalación local de Joomla a WordPress.</p>

    <div class="card-grid">
        <!-- Joomla Analysis -->
        <div class="card">
            <h3>Origen: Joomla (Local)</h3>
            <div class="stat-row">
                <span class="stat-label">Ruta Local:</span>
                <span class="stat-value" style="font-size: 12px;"><?php echo JOOMLA_LOCAL_PATH; ?></span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Artículos (Standard):</span>
                <span class="stat-value"><?php echo $stats['joomla_articles']; ?></span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Items (Zoo):</span>
                <span class="stat-value"><?php echo $stats['joomla_zoo_items']; ?></span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Categorías (Zoo):</span>
                <span class="stat-value"><?php echo $stats['categories_zoo']; ?></span>
            </div>
        </div>

        <!-- WordPress Analysis -->
        <div class="card">
            <h3>Destino: WordPress (Local)</h3>
            <div class="stat-row">
                <span class="stat-label">Posts Publicados:</span>
                <span class="stat-value"><?php echo $stats['wp_posts']; ?></span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Categorías:</span>
                <span class="stat-value"><?php echo $stats['categories_wp']; ?></span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Importados (Zoo):</span>
                <span class="stat-value"><?php echo $stats['imported_zoo']; ?></span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Importados (Standard):</span>
                <span class="stat-value"><?php echo $stats['imported_content']; ?></span>
            </div>
        </div>
    </div>

    <div class="card" style="margin-top: 20px;">
        <h3>Estado de Sincronización</h3>
        <?php
        $pending_zoo = $stats['joomla_zoo_items'] - $stats['imported_zoo'];
        $pending_standard = $stats['joomla_articles'] - $stats['imported_content'];

        if ($pending_zoo > 0) {
            echo "<p class='warning'>Hay <strong>$pending_zoo</strong> items de Zoo pendientes o desincronizados.</p>";
        } elseif ($pending_standard > 0) {
             echo "<p class='warning'>Hay <strong>$pending_standard</strong> artículos estándar pendientes.</p>";
        } else {
            echo "<p class='success'>¡Todo parece estar sincronizado! (Basado en conteo total)</p>";
        }
        ?>

        <p><strong>Nota:</strong> La migración verifica fechas de modificación. Si cambiaste algo en Joomla hoy, usa el botón de abajo para actualizarlo en WordPress.</p>

        <div style="display: flex; gap: 10px;">
            <a href="migration-worker.php?type=zoo&step=categories" class="action-btn">
                1. Sincronizar Categorías (Zoo)
            </a>
            <a href="migration-worker.php?type=zoo&step=posts" class="action-btn">
                2. Sincronizar Posts e Imágenes (Zoo)
            </a>
             <a href="migration-worker.php?type=standard&step=posts" class="action-btn secondary">
                (Opcional) Sincronizar Artículos Standard
            </a>
        </div>
    </div>
</div>

</body>
</html>
