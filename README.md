# 🏛️ PHP Monolith IDE & File Manager V2

> **¿Hasta dónde puede llegar un único archivo `.php` sin `vendor/`, sin `node_modules/`, sin binarios externos y sin perder la cordura arquitectónica?**







En una era dominada por árboles de dependencias de 400 MB para renderizar un editor de texto, **PHP Web File Manager V2** plantea una tesis de ingeniería inversa: construir un **explorador de archivos seguro, un IDE multilenguaje con resaltado sintáctico en tiempo real, un motor de autocompletado de rutas en vivo (VS Code Path Intellisense) y un sistema de control de versiones estilo Git con Diff visual y Rollback atómico**, todo contenido dentro de un **único archivo monolítico autocontenido**.

Lo sueltas en cualquier servidor con PHP 8+, abres el navegador y tienes una estación de trabajo completa. Cuando terminas, o incluso mientras trabajas, el propio archivo se protege a sí mismo de ser leído, descargado, sobrescrito o eliminado desde la interfaz.

## 🧠 El Manifiesto del Monolito: ¿Por qué un solo archivo?

El desarrollo moderno suele confundir **modularidad lógica** con **fragmentación física**. En escenarios de administración remota, rescate de servidores, auditoría en contenedores efímeros o despliegues minimalistas, subir una carpeta con 1,200 archivos por SFTP es un antipatrón operativo.

Este proyecto aborda cuatro desafíos de diseño de sistemas en espacio restringido:

| Desafío de Ingeniería | Enfoque Convencional | Solución en este Monolito |
| :--- | :--- | :--- |
| **Edición de código en navegador** | Cargar Monaco Editor o CodeMirror (~2 MB a 8 MB de bundles JS/CSS). | **Doble capa sincronizada (`<textarea>` transparente + `<pre>` tokenizado)** con renderizado léxico en vivo y bloqueo de colisión de tokens. |
| **Autocompletado de rutas (Path Intellisense)** | Demonio local o indexador pesado en memoria. | **Introspección reactiva vía endpoint AJAX propio (`?action=path_intellisense`)** que resuelve contextos relativos (`./`, `../`) y absolutos (`/`) en tiempo real con debounce de 60 ms. |
| **Control de versiones (VCS)** | Dependencia obligatoria del binario `git` en el sistema operativo (`shell_exec`). | **Motor VCS nativo en PHP puro (`.fm_vcs/`)** basado en direccionamiento por contenido (`SHA-1`), snapshots inmutables `.snap`, ramas lógicas y respaldos automáticos previos a cada *rollback*. |
| **Aislamiento y autoprotección** | Reglas complejas fuera de banda o listas negras estáticas de nombres de archivo. | **Identificación por inodo/ruta canónica (`realpath(__FILE__)`)** combinada con jaula léxica y validación del directorio padre antes de que el archivo siquiera exista en disco. |

## ⚙️ Anatomía Técnica: Bajo el Capó

### 1. El Ilusionista Óptico: Editor de Código de Doble Capa

Un `<textarea>` nativo de HTML no permite colorear palabras individuales; un elemento `contenteditable` suele corromper el cursor, desordenar los saltos de línea al pegar código y degradar el rendimiento.

La arquitectura implementada divide la responsabilidad en dos planos superpuestos con coordenadas milimétricamente idénticas (`padding: 14px 16px`, `font-size: 13.5px`, `line-height: 1.6`, `tab-size: 4`):

1. **Plano Superior Interactivo (`#codeEditorTextarea`)**: Recibe todos los eventos de teclado, selección y portapapeles. Su texto es completamente transparente (`color: transparent`), excepto su cursor (`caret-color: #38bdf8`).
2. **Plano Inferior de Renderizado (`#codeEditorHighlight`)**: Un bloque `<pre aria-hidden="true">` con `pointer-events: none` que espeja en cada pulsación tanto el árbol DOM coloreado como las coordenadas exactas de desplazamiento (`scrollTop` y `scrollLeft`) junto con el canalón lateral de numeración de líneas (`#ideLineNumbers`).

#### El problema de la auto-colisión en el resaltador léxico (y cómo se resuelve)

Si aplicas expresiones regulares en cascada sobre código fuente para inyectar etiquetas `<span class="tok-str">...</span>`, la siguiente expresión regular (por ejemplo, la que busca palabras clave o atributos HTML como `class=`) **terminará coloreando el propio HTML que acabas de inyectar**, corrompiendo el DOM.

Para evitar un parser AST de miles de líneas, el motor `highlightCode(code, lang)` utiliza una **tabla de símbolos efímera por pase (Token Vault)**:

```text
Código Fuente Crudo
   │
   ├─► 1. Escape de entidades HTML (&, <, >)
   │
   ├─► 2. Extracción de Comentarios y Directivas (#include) ──► Guardado en Vault como ___IDE_TOK_0___
   │
   ├─► 3. Extracción de Strings (""", ''', "", '', ``)      ──► Guardado en Vault como ___IDE_TOK_1___
   │
   ├─► 4. Reglas de Lenguaje (Tags HTML/PHP, @Decoradores, $vars, Tipos C/Java, Keywords, Hex/Nums)
   │      (Operan sobre un texto donde los strings y comentarios son marcadores opacos inmunes)
   │
   └─► 5. Rehidratación Atómica: ___IDE_TOK_n___ ──► <span class="tok-...">...</span>
```

Soporta detección automática por extensión y **heurística de contenido** (si creas un archivo sin extensión y escribes `#include <iostream>` o `def main():`, el motor cambia automáticamente las reglas léxicas y el diccionario de *snippets*) para **7 ecosistemas**:

* 🐘 **PHP** (`declare(strict_types=1)`, variables `$var`, etiquetas, PDO, seguridad)
* ⚡ **JavaScript / TypeScript** (`async/await`, ES Modules, Fetch API, DOM)
* 🌐 **HTML / XML / SVG** (Etiquetas, atributos coloreados, plantillas HTML5)
* 🎨 **CSS / SCSS** (Propiedades, Flexbox, Grid, Media Queries)
* ⚙️ **C / C++** (Directivas de preprocesador `#include` / `#define` diferenciadas de comentarios, tipos `size_t`/`uint32_t`, STL `std::vector`)
* ☕ **Java** (Anotaciones `@Override`, tipos genéricos, clases, bloques `try/catch`)
* 🐍 **Python** (Decoradores `@staticmethod`, *docstrings* `"""`, *type hints* `-> None`)

### 2. VS Code Path Intellisense Nativo (60ms Reactive Loop)

Escribir rutas relativas de memoria (`../../assets/js/app.js`) es una de las mayores fuentes de errores silenciosos. El editor incorpora un analizador contextual (`detectPathContextBeforeCursor()`) que inspecciona la línea activa desde el inicio hasta el índice exacto del cursor (`selectionStart`).

Cuando detecta cualquiera de estos patrones:
* Rutas explícitas: `./`, `../`, o `/`
* Atributos y llamadas de lenguaje: `src="..."`, `href="..."`, `url('...')`, `include '...'`, `require_once '...'`, `import ... from '...'`, `fetch('...')`, `open('...')` o `#include "..."`

Dispara una consulta asíncrona con *debounce* de 60 ms a `?action=path_intellisense`, la cual:
1. Toma el directorio base del archivo que se está editando (`data-file-dir`).
2. Resuelve la ruta candidata dentro de la jaula de seguridad `safe_path()`.
3. Separa el directorio contenedor del prefijo parcial que el usuario está escribiendo.
4. Devuelve un JSON ordenado (carpetas primero, archivos después) con iconos semánticos y tamaños reales en disco.
5. **Encadenamiento recursivo**: Si seleccionas una carpeta con `Tab` o `Enter`, el editor inserta `carpeta/` y **reabre automáticamente el popup** listando el contenido del subdirectorio recién abierto, replicando el flujo exacto de VS Code.

### 3. Sistema de Control de Versiones Nativo (`.fm_vcs`)

¿Qué ocurre cuando necesitas versionar cambios críticos en un servidor donde no existe `git`, no tienes permisos para ejecutar procesos de consola (`proc_open`/`exec` deshabilitados en `php.ini`) o no quieres contaminar el repositorio Git principal con commits de depuración?

El monolito implementa su propio motor VCS en espacio de usuario dentro del directorio oculto `.fm_vcs/`:

* **Protección de Red y de Interfaz**: Al inicializarse (`vcs_init_storage()`), genera automáticamente un `.htaccess` con `Deny from all` y registra la ruta en `is_vcs_internal_path()` para que nadie pueda explorar, editar ni eliminar el almacén de versiones desde la interfaz web.
* **Desduplicación por Hash de Contenido (`SHA-1`)**: Cada vez que guardas un archivo (`Ctrl+S`), el sistema calcula `sha1($content)`. Si el contenido es idéntico al último commit registrado para ese archivo en la rama activa, evita crear ruido en el historial (a menos que fuerces un snapshot manual).
* **Almacenamiento de Snapshots (`.snap`)**: Cada commit genera un identificador corto de 7 caracteres hexadecimales (`substr(sha1(...), 0, 7)`) y guarda el contenido íntegro en `.fm_vcs/commits/<hash>.snap`, manteniendo un índice rotativo de las últimas 300 revisiones en `index.json`.
* **Escáner del Working Tree (`vcs_scan_workspace_changes`)**: Recorre recursivamente el proyecto comparando los hashes `sha1_file()` en vivo contra el último commit conocido, clasificando los archivos en:
  * `M` (**Modified**): El archivo en disco ha cambiado respecto a su último snapshot.
  * `U` (**Untracked**): Archivo editable nuevo que aún no tiene historial en VCS.
* **Rollback con Red de Seguridad Automática**: Antes de restaurar cualquier archivo a un commit histórico (`restore_commit`), el motor ejecuta automáticamente un commit de respaldo del estado actual en disco (`Auto-backup previo a rollback #<hash>`). **Ninguna restauración puede provocar pérdida accidental de código.**

### 4. Modelo de Seguridad en Capas (Defense-in-Depth)

Un gestor de archivos web es, por definición, una superficie crítica. El diseño rechaza la seguridad por oscuridad y aplica cinco invariantes estrictas:

1. **Neutralización de Path Traversal antes y después de la creación (`safe_path`)**:
   * Todas las rutas relativas pasan por `normalize_relative_path()`, que colapsa segmentos `.` y resuelve `..` en memoria usando una pila (`array_pop`) sin permitir desbordamientos por debajo de la raíz.
   * Si el archivo o enlace simbólico existe, se resuelve con `realpath()` y se verifica que comience estrictamente con el prefijo del directorio raíz (`str_starts_with($real, $root . DIRECTORY_SEPARATOR)`), bloqueando ataques de *Symlink Escape*.
   * Si el archivo aún no existe (al crear un archivo o carpeta nueva), se valida con `realpath(dirname($candidate))` el directorio padre real.
2. **Inmunidad Reflexiva (`is_current_manager_file`)**:
   * Mediante `realpath(__FILE__) === realpath($targetFile)`, el script se reconoce a sí mismo sin importar cómo lo hayas renombrado (`index.php`, `admin_fm.php`, `studio.php`).
   * El propio archivo se oculta del listado de archivos, no aparece en el autocompletado de rutas, y rechaza a nivel de controlador cualquier petición de descarga, edición, eliminación o sobrescritura mediante subida.
3. **Autenticación en Tiempo Constante y Endurecimiento de Sesión**:
   * El usuario se compara con `hash_equals()` y la contraseña con `password_verify()` (Bcrypt) para mitigar ataques de temporización (*timing attacks*).
   * Al iniciar sesión, se invoca `session_regenerate_id(true)` contra fijación de sesión (*Session Fixation*) y las cookies se emiten con `httponly: true`, `samesite: Strict` y detección automática de `secure` bajo HTTPS.
4. **Protección CSRF Criptográfica**:
   * Todas las operaciones mutadoras (`POST` de creación, edición, borrado, subida y endpoints AJAX de VCS) exigen un token de 256 bits (`random_bytes(32)`) validado con `hash_equals()`.
5. **Política de Escape por Defecto**:
   * Toda salida interpolada en el DOM pasa por la función auxiliar `e()` con `ENT_QUOTES | ENT_SUBSTITUTE` bajo codificación `UTF-8`.

## 🚀 Despliegue Rápido (60 Segundos)

### Requisitos
* **PHP 8.0 o superior** (utiliza `declare(strict_types=1)`, expresiones `match`, `str_starts_with` y `str_ends_with` nativos).
* Sin extensiones exóticas: solo funciones estándar del núcleo de PHP (`json`, `session`, `hash`).

### Paso 1: Clonar o descargar el monolito

```bash
git clone https://github.com/tu-usuario/php-monolith-file-manager.git
cd php-monolith-file-manager
```

### Paso 2: Configurar tus credenciales de acceso

Por seguridad, el archivo incluye credenciales genéricas de demostración:
* **Usuario por defecto**: `admin`
* **Contraseña por defecto**: `password`

Antes de exponerlo en cualquier entorno accesible, genera tu propio hash Bcrypt desde tu terminal:

```bash
php -r "echo password_hash('Tu_Clave_Ultra_Segura_2026!', PASSWORD_DEFAULT) . PHP_EOL;"
```

Abre `index.php` y reemplaza las constantes en la sección `CONFIGURACIÓN` (líneas 45-46):

```php
const ADMIN_USERNAME = 'tu_usuario';
const PASSWORD_HASH  = '$2y$10$TuHashGeneradoAqui...';
```

### Paso 3: Ejecutar

Puedes probarlo instantáneamente con el servidor embebido de PHP:

```bash
php -S localhost:8080
```

O colocar el archivo `index.php` (puedes renombrarlo libremente a `ide.php`, `manager.php`, etc.) dentro de cualquier directorio servido por **Apache, Nginx + PHP-FPM, Caddy o LiteSpeed**, asegurándote de que el usuario del servidor web tenga permisos de escritura sobre el directorio si deseas crear archivos o utilizar el sistema de control de versiones `.fm_vcs`.

## ⌨️ Atajos de Productividad del IDE

| Atajo / Acción | Comportamiento en el Editor |
| :--- | :--- |
| `Ctrl + S` / `Cmd + S` | Guarda los cambios en disco y registra automáticamente un commit en la rama activa de `.fm_vcs`. |
| `Ctrl + Espacio` | Invoca el popup de **IntelliSense** contextual (rutas de servidor si el cursor está en una ruta, o *snippets* del lenguaje activo). |
| Escribir `./`, `../` o `/` | Activa en vivo **VS Code Path Intellisense** consultando el árbol de directorios real del servidor. |
| `Flecha Arriba` / `Abajo` | Navega por las sugerencias del popup flotante sin perder el foco del código. |
| `Tab` / `Enter` | Inserta la sugerencia seleccionada (y si es una carpeta, abre automáticamente el siguiente nivel de autocompletado). |
| `Tab` (sin popup abierto) | Inserta una sangría limpia de 4 espacios manteniendo la posición del cursor. |
| `Escape` | Cierra el panel flotante de sugerencias. |

## 📐 Límites de Seguridad Configurables

En la cabecera de `index.php` puedes ajustar las cotas operativas según las capacidades de tu servidor:

| Constante / Variable | Valor por Defecto | Propósito |
| :--- | :--- | :--- |
| `ROOT_DIRECTORY` | `__DIR__` | Define la jaula raíz de la que ninguna operación puede escapar. |
| `MAX_EDIT_SIZE` | `5 * 1024 * 1024` (5 MB) | Tamaño máximo de archivo permitido para abrir en el IDE en memoria. |
| `MAX_UPLOAD_SIZE` | `20 * 1024 * 1024` (20 MB) | Límite de subida de archivos (en conjunto con `upload_max_filesize` de `php.ini`). |
| `$FORBIDDEN_EDIT_EXTENSIONS` | Imágenes, binarios, comprimidos, multimedia y fuentes | Evita corromper archivos binarios al bloquear su apertura en el editor de texto. |

## 📄 Licencia

Distribuido bajo la licencia **MIT**. Libre para estudiar, auditar, destripar, adaptar e integrar en tus propios flujos de trabajo.
