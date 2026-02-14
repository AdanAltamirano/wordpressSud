# Instrucciones para solucionar widgets duplicados en las barras laterales

Has reportado que los elementos en la barra lateral derecha (como las imágenes de Loreto, Comondú y los comentarios) están duplicados, especialmente en la página de inicio. Esto puede deberse a widgets duplicados en la base de datos O a una configuración duplicada en el tema/constructor de páginas.

Para solucionar este problema, he creado tres scripts que puedes ejecutar en tu sitio.

## Pasos para solucionar el problema:

1.  **Paso 1: Verificar duplicados en la base de datos**
    *   Asegúrate de que el archivo `check-sidebar-duplicates.php` esté en la carpeta raíz de tu instalación de WordPress.
    *   Abre tu navegador y visita: `http://tudominio.com/check-sidebar-duplicates.php` (reemplaza `tudominio.com` con tu dominio real).
    *   Este script te mostrará qué widgets están duplicados en cada barra lateral.
    *   **Si dice "No duplicates found"**, pasa al Paso 3.

2.  **Paso 2: Corregir duplicados en la base de datos (si el paso 1 encontró problemas)**
    *   Si el script anterior confirma que hay duplicados, asegúrate de que el archivo `fix-sidebar-duplicates.php` esté en la carpeta raíz.
    *   Abre tu navegador y visita: `http://tudominio.com/fix-sidebar-duplicates.php`.
    *   Este script limpiará automáticamente las entradas duplicadas en la configuración de las barras laterales.
    *   Verás un mensaje de "SUCCESS" si se realizaron cambios.

    **Importante:** Debes haber iniciado sesión como **Administrador** en WordPress para que estos scripts funcionen. Si no estás logueado, verás un mensaje de "Access Denied".

3.  **Paso 3: Investigar la estructura del tema (si el problema persiste en la página de inicio)**
    *   Si los widgets siguen duplicados SOLO en la página de inicio y el Paso 1 no encontró nada, es probable que la página de inicio esté configurada para mostrar DOS barras laterales (una por el tema y otra por el constructor de páginas).
    *   Sube el archivo `inspect-theme-structure.php` a la raíz.
    *   Visita: `http://tudominio.com/inspect-theme-structure.php`.
    *   Este script analizará qué plantilla usa tu página de inicio y si hay llamadas duplicadas a `get_sidebar()`.
    *   **Solución común:** Si usas un constructor de páginas (como TagDiv Composer o Visual Composer), revisa la configuración de la página de inicio. Asegúrate de que la plantilla de la página esté en "Default Template" (sin barra lateral) si ya agregaste una barra lateral manualmente en el constructor, O viceversa.

4.  **Eliminar los scripts:**
    *   Una vez solucionado el problema, te recomiendo eliminar estos tres archivos (`check-sidebar-duplicates.php`, `fix-sidebar-duplicates.php` y `inspect-theme-structure.php`) de tu servidor por seguridad.

## Nota Técnica

El problema ocurre cuando la opción `sidebars_widgets` en la base de datos contiene el mismo ID de widget varias veces en el mismo array de barra lateral. WordPress normalmente evita esto, pero puede ocurrir durante migraciones manuales o scripts de importación que no verifican duplicados antes de agregar widgets.
