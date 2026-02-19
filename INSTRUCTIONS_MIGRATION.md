# Instrucciones para la Migración Incremental de Joomla a WordPress

Este set de herramientas te permite analizar y migrar contenido desde tu instalación local de Joomla a WordPress. Está diseñado para ser ejecutado "bajo demanda" y actualizar solo el contenido nuevo o modificado.

## 1. Configuración

El archivo `migration-config.php` contiene la configuración de conexión. Ya ha sido configurado con los datos que proporcionaste:

*   **Joomla DB:** sudcalifornios (127.0.0.1:3306)
*   **WordPress DB:** wordpress (127.0.0.1:3308)
*   **Ruta de Archivos Joomla:** `C:/Sudcaliforniosjoomla`

**Importante:** Asegúrate de que la ruta `C:/Sudcaliforniosjoomla` sea correcta y accesible desde tu servidor web local, ya que el script buscará las imágenes allí para copiarlas a WordPress.

## 2. Acceso al Dashboard

Abre tu navegador y ve a la siguiente dirección (ajusta la ruta según donde tengas instalado WordPress):

`http://localhost/wordpress/migration-dashboard.php`

Verás un panel con:
*   Conteo de artículos en Joomla (Standard y Zoo).
*   Conteo de posts en WordPress.
*   Estado de sincronización (cuántos faltan por importar).

## 3. Ejecutar la Migración

El proceso se divide en pasos para evitar sobrecargar el servidor.

### Paso A: Sincronizar Categorías
Haz clic en el botón **"1. Sincronizar Categorías (Zoo)"**.
*   Esto leerá las categorías de Joomla y las creará en WordPress si no existen.
*   Si usas artículos estándar en lugar de Zoo, usa el botón correspondiente (si aparece habilitado o mediante la URL).

### Paso B: Sincronizar Posts e Imágenes
Haz clic en el botón **"2. Sincronizar Posts e Imágenes (Zoo)"**.
*   El script procesará los artículos en bloques de 50.
*   **Imágenes:** Buscará las imágenes dentro del contenido. Si las encuentra en tu carpeta local `C:/Sudcaliforniosjoomla/images/...`, las copiará físicamente a `wp-content/uploads/...` y las adjuntará al post.
*   **Incremental:** Si un post ya existe en WordPress, el script comparará la fecha de modificación. Si en Joomla es más reciente, actualizará el post en WordPress. Si es igual o anterior, lo saltará.

## 4. Notas Importantes

*   **Tiempos de espera:** Si tienes muchas imágenes, el proceso puede tardar. El script está diseñado para recargar automáticamente cada 50 items. No cierres la ventana hasta que veas el mensaje "¡Migración Completada!".
*   **Zoo vs Standard:** El sistema detecta automáticamente si usas Zoo. Si tus contenidos están en el gestor de artículos normal de Joomla, el dashboard te avisará y deberás usar la opción de "Artículos Standard".
