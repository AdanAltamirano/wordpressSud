# Instrucciones para Analizar Integridad de Joomla

Para evitar problemas en la migración (como imágenes rotas), hemos creado una herramienta de **Solo Lectura** que escanea tu base de datos de Joomla y verifica si las imágenes realmente existen en tu carpeta `C:/Sudcalifornios`.

## 1. Ejecutar el Análisis

Abre tu navegador y ve a:

`http://localhost/wordpress/analyze-joomla-source.php`

## 2. Interpretar los Resultados

El script te mostrará:
*   **Total de artículos escaneados.**
*   **Imágenes encontradas:** Cuántas imágenes referenciadas en la base de datos realmente existen en tu disco duro.
*   **Imágenes faltantes:** Si este número es mayor a 0, verás una lista roja con los detalles.

### ¿Qué hacer si hay imágenes faltantes?
Si el reporte muestra imágenes faltantes (por ejemplo, `/images/noticias/foto1.jpg` no encontrada):
1.  Verifica si la carpeta `C:/Sudcalifornios/images` contiene subcarpetas.
2.  Asegúrate de que no moviste o renombraste carpetas en tu respaldo local.
3.  Si faltan muchas, es posible que tu respaldo de archivos de Joomla esté incompleto.

**Este script NO modifica nada en WordPress ni en Joomla.** Es seguro ejecutarlo tantas veces como quieras para verificar que tu fuente de datos está correcta antes de intentar migrar de nuevo.
