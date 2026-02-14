# Instrucciones para solucionar widgets duplicados en las barras laterales

Has reportado que los elementos en la barra lateral derecha (como las imágenes de Loreto, Comondú y los comentarios) están duplicados, especialmente en la página de inicio. Esto puede deberse a widgets duplicados en la base de datos O a una configuración duplicada en el tema/constructor de páginas.

Para solucionar este problema, he creado tres scripts que puedes ejecutar en tu sitio.

## Pasos para solucionar el problema:

1.  **Paso 1: Verificar duplicados EXACTOS en la base de datos**
    *   Asegúrate de que el archivo `check-sidebar-duplicates.php` esté en la carpeta raíz de tu instalación de WordPress.
    *   Abre tu navegador y visita: `http://tudominio.com/check-sidebar-duplicates.php` (reemplaza `tudominio.com` con tu dominio real).
    *   Este script te mostrará qué widgets tienen el MISMO ID duplicado.
    *   **Si dice "No duplicates found"**, pasa al Paso 2.

2.  **Paso 2: Verificar duplicados de CONTENIDO (MUY IMPORTANTE)**
    *   A veces los widgets tienen IDs diferentes pero el mismo contenido (por ejemplo, dos widgets de texto idénticos).
    *   Sube el archivo `fix-sidebar-content-duplicates.php` a la raíz.
    *   Visita: `http://tudominio.com/fix-sidebar-content-duplicates.php`.
    *   Este script analizará el contenido de cada widget. Si encuentra dos widgets que muestran lo mismo (aunque tengan títulos diferentes), te avisará.
    *   **Si encuentra duplicados:** Verás un botón rojo **"FIX DUPLICATES NOW"**. Haz clic para eliminar automáticamente las copias sobrantes.

3.  **Paso 3: Investigar la estructura del tema (si el problema persiste)**
    *   Si los pasos anteriores no solucionaron el problema, es probable que la página de inicio esté configurada para mostrar DOS barras laterales (una por el tema y otra por el constructor de páginas).
    *   Sube el archivo `inspect-theme-structure.php` a la raíz.
    *   Visita: `http://tudominio.com/inspect-theme-structure.php`.
    *   Este script escaneará **todos los archivos del tema** en busca de llamadas a la barra lateral (`get_sidebar` o `dynamic_sidebar`).
    *   Busca archivos como `header.php`, `footer.php` o `loop.php` que puedan estar llamando a la barra lateral además de tu plantilla principal.
    *   **Solución común:** Si usas un constructor de páginas (como TagDiv Composer o Visual Composer), revisa la configuración de la página de inicio. Asegúrate de que la plantilla de la página esté en "Default Template" (sin barra lateral) si ya agregaste una barra lateral manualmente en el constructor, O viceversa.

4.  **Eliminar los scripts:**
    *   Una vez solucionado el problema, te recomiendo eliminar estos cuatro archivos (`check-sidebar-duplicates.php`, `fix-sidebar-duplicates.php`, `fix-sidebar-content-duplicates.php` y `inspect-theme-structure.php`) de tu servidor por seguridad.

## Nota Técnica

El problema ocurre cuando la opción `sidebars_widgets` en la base de datos contiene el mismo ID de widget varias veces en el mismo array de barra lateral. WordPress normalmente evita esto, pero puede ocurrir durante migraciones manuales o scripts de importación que no verifican duplicados antes de agregar widgets.
