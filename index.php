<?php
declare(strict_types=1);

/*
 * ============================================================
 * PHP WEB FILE MANAGER - V2 (CON VS CODE PATH INTELLISENSE & MULTI-LANGUAGE IDE)
 * ============================================================
 *
 * MONOLITO PHP
 *
 * Características originales intactas:
 * - Login protegido
 * - Explorador de archivos y carpetas
 * - Crear carpetas y archivos
 * - Subir y descargar archivos
 * - Editar archivos de texto
 * - Eliminar archivos y carpetas recursivamente
 * - Protección CSRF y contra Path Traversal
 * - Protección del propio File Manager
 * - Tema Light / Dark
 * - Mobile First (compatible desde 320px)
 *
 * Nuevas características del Editor de Código Nativo:
 * - Numeración de líneas sincronizada y resaltado de línea activa
 * - Extensión nativa estilo VS Code Path Intellisense (autocompletado real
 *   de rutas ./, ../, /, src="", href="", include, import, etc. vía AJAX)
 * - Detección automática y Syntax Highlighting + Autocompletado para:
 *   PHP, JavaScript, HTML, CSS, C/C++, Java y Python
 * - Atajos de teclado (Ctrl+S / Cmd+S para guardar, Ctrl+Espacio para IntelliSense,
 *   Tab para sangría inteligente y auto-cierre de llaves/comillas)
 * ============================================================
 */

/* ============================================================
 * CONFIGURACIÓN
 * ============================================================ */
const ROOT_DIRECTORY = __DIR__;

/*
 * ⚠️ CREDENCIALES POR DEFECTO PARA DEMOSTRACIÓN (CAMBIAR EN PRODUCCIÓN):
 * Usuario por defecto:    admin
 * Contraseña por defecto: changeme123
 *
 * Genera tu propio hash seguro con:
 * php -r "echo password_hash('TU_PASSWORD_SEGURO', PASSWORD_DEFAULT) . PHP_EOL;"
 */
const ADMIN_USERNAME = 'admin';
const PASSWORD_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'; // Hash genérico (password: "password")

/*
 * Tamaño máximo permitido para editar (5 MB).
 */
const MAX_EDIT_SIZE = 5 * 1024 * 1024;

/*
 * Tamaño máximo permitido para subir (20 MB).
 */
const MAX_UPLOAD_SIZE = 20 * 1024 * 1024;

/*
 * Extensiones que NO se pueden editar.
 */
$FORBIDDEN_EDIT_EXTENSIONS = [
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'ico',
    'pdf', 'zip', 'gz', 'tar', 'rar', '7z',
    'mp3', 'mp4', 'avi', 'mov',
    'woff', 'woff2', 'ttf', 'otf'
];

/* ============================================================
 * SESIÓN
 * ============================================================ */
session_name('php_file_manager_session');
session_set_cookie_params([
    'httponly' => true,
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Strict'
]);
session_start();

/* ============================================================
 * FUNCIONES GENERALES
 * ============================================================ */

/*
 * Escapa contenido HTML.
 */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/*
 * Redireccionamiento.
 */
function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/*
 * Comprueba si el usuario está autenticado.
 */
function is_logged_in(): bool
{
    return !empty($_SESSION['file_manager_authenticated']);
}

/*
 * Obliga al usuario a iniciar sesión.
 */
function require_login(): void
{
    if (!is_logged_in()) {
        redirect('?action=login');
    }
}

/*
 * Obtiene el directorio raíz.
 */
function root_directory(): string
{
    $real = realpath(ROOT_DIRECTORY);
    if ($real === false || !is_dir($real)) {
        die('El directorio raíz no existe.');
    }
    return rtrim($real, DIRECTORY_SEPARATOR);
}

/*
 * ============================================================
 * IDENTIFICAR EL PROPIO FILE MANAGER
 * ============================================================
 * Determina automáticamente cuál es el archivo PHP que está
 * ejecutando actualmente este sistema.
 */
function is_current_manager_file(string $absolutePath): bool
{
    $currentFile = realpath(__FILE__);
    $targetFile  = realpath($absolutePath);
    if ($currentFile === false || $targetFile === false) {
        return false;
    }
    return $currentFile === $targetFile;
}

/*
 * Normaliza una ruta relativa.
 */
function normalize_relative_path(string $path): string
{
    $path  = str_replace('\\', '/', $path);
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            if (!empty($parts)) {
                array_pop($parts);
            }
            continue;
        }
        $parts[] = $part;
    }
    return implode('/', $parts);
}

/*
 * ============================================================
 * RUTA SEGURA
 * ============================================================
 */
function safe_path(string $relativePath = ''): string
{
    $root         = root_directory();
    $relativePath = normalize_relative_path($relativePath);
    if ($relativePath === '') {
        return $root;
    }

    $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

    /*
     * El elemento existe.
     */
    if (file_exists($candidate) || is_link($candidate)) {
        $real = realpath($candidate);
        if ($real === false) {
            throw new RuntimeException('Ruta inválida.');
        }
        if ($real !== $root && !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Acceso fuera del directorio permitido.');
        }
        return $real;
    }

    /*
     * El elemento todavía no existe. Validamos su directorio padre.
     */
    $parentReal = realpath(dirname($candidate));
    if ($parentReal === false) {
        throw new RuntimeException('El directorio padre no existe.');
    }
    if ($parentReal !== $root && !str_starts_with($parentReal, $root . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Acceso fuera del directorio permitido.');
    }
    return $candidate;
}

/*
 * Convierte una ruta absoluta en relativa.
 */
function relative_path(string $absolutePath): string
{
    $root         = root_directory();
    $absolutePath = str_replace('\\', '/', $absolutePath);
    $root         = str_replace('\\', '/', $root);
    if ($absolutePath === $root) {
        return '';
    }
    return ltrim(substr($absolutePath, strlen($root)), '/');
}

/*
 * ============================================================
 * FLASH MESSAGES
 * ============================================================
 */
function set_flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = [
        'message' => $message,
        'type'    => $type
    ];
}

function get_flash(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

/*
 * ============================================================
 * CSRF
 * ============================================================
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (
        empty($token) ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], (string)$token)
    ) {
        http_response_code(403);
        die('CSRF token inválido.');
    }
}

/*
 * ============================================================
 * PERMISOS DE EDICIÓN
 * ============================================================
 */
function can_edit_file(string $filename, int $size, array $forbiddenExtensions): bool
{
    if ($size > MAX_EDIT_SIZE) {
        return false;
    }
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return !($extension !== '' && in_array($extension, $forbiddenExtensions, true));
}

/*
 * ============================================================
 * FORMATEAR BYTES
 * ============================================================
 */
function format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    if ($bytes < 1024 * 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 1) . ' MB';
    }
    return number_format($bytes / (1024 * 1024 * 1024), 1) . ' GB';
}

/*
 * ============================================================
 * ICONOS (Ampliado con soporte C/C++, Java y Python)
 * ============================================================
 */
function file_icon(string $name, bool $isDirectory): string
{
    if ($isDirectory) {
        return '📁';
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return match ($ext) {
        'php', 'phtml'                      => '🐘',
        'html', 'htm', 'xhtml'              => '🌐',
        'css', 'scss', 'less'               => '🎨',
        'js', 'mjs', 'cjs', 'ts', 'jsx'     => '⚡',
        'c', 'cpp', 'cc', 'cxx', 'h', 'hpp' => '⚙️',
        'java', 'jar', 'class'              => '☕',
        'py', 'pyw', 'ipynb'                => '🐍',
        'json', 'xml', 'yml', 'yaml'        => '🧩',
        'md', 'txt', 'log', 'ini', 'env'    => '📄',
        'jpg', 'jpeg', 'png', 'gif', 'webp' => '🖼️',
        'zip', 'rar', 'gz', 'tar', '7z'     => '📦',
        default                             => '📄'
    };
}

/*
 * ============================================================
 * DETECTAR LENGUAJE POR EXTENSIÓN DE ARCHIVO
 * ============================================================
 */
function detect_code_language(string $filename): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return match ($ext) {
        'php', 'phtml'                                 => 'php',
        'js', 'mjs', 'cjs', 'ts', 'jsx', 'tsx', 'json' => 'javascript',
        'html', 'htm', 'xhtml', 'xml', 'svg'           => 'html',
        'css', 'scss', 'less'                          => 'css',
        'c', 'cpp', 'cc', 'cxx', 'h', 'hpp'            => 'cpp',
        'java'                                         => 'java',
        'py', 'pyw'                                    => 'python',
        default                                        => 'auto'
    };
}

/*
 * ============================================================
 * ELIMINAR DIRECTORIO RECURSIVAMENTE
 * ============================================================
 */
function delete_directory_recursive(string $directory): bool
{
    if (!is_dir($directory)) {
        return false;
    }
    $items = scandir($directory);
    if ($items === false) {
        return false;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && !is_link($path)) {
            delete_directory_recursive($path);
        } else {
            unlink($path);
        }
    }
    return rmdir($directory);
}

/*
 * ============================================================
 * SISTEMA DE CONTROL DE VERSIONES NATIVO (VCS / GIT-STYLE)
 * ============================================================
 * Almacena commits, ramas (branches), snapshots y diffs en
 * el directorio oculto y protegido .fm_vcs sin requerir binarios externos.
 */
const VCS_DIRECTORY_NAME = '.fm_vcs';

function is_vcs_internal_path(string $absolutePath): bool
{
    $vcsRoot = root_directory() . DIRECTORY_SEPARATOR . VCS_DIRECTORY_NAME;
    $normAbs = str_replace('\\', '/', $absolutePath);
    $normVcs = str_replace('\\', '/', $vcsRoot);
    return $normAbs === $normVcs || str_starts_with($normAbs, $normVcs . '/');
}

function vcs_init_storage(): string
{
    $vcsDir     = root_directory() . DIRECTORY_SEPARATOR . VCS_DIRECTORY_NAME;
    $commitsDir = $vcsDir . DIRECTORY_SEPARATOR . 'commits';

    if (!is_dir($vcsDir)) {
        @mkdir($vcsDir, 0755, true);
    }
    if (!is_dir($commitsDir)) {
        @mkdir($commitsDir, 0755, true);
    }

    $htaccess = $vcsDir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Order allow,deny\nDeny from all\n");
    }

    $indexFile = $vcsDir . DIRECTORY_SEPARATOR . 'index.json';
    if (!file_exists($indexFile)) {
        $initial = [
            'current_branch' => 'main',
            'branches'       => ['main', 'develop'],
            'commits'        => []
        ];
        @file_put_contents($indexFile, json_encode($initial, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    return $vcsDir;
}

function vcs_read_index(): array
{
    $vcsDir    = vcs_init_storage();
    $indexFile = $vcsDir . DIRECTORY_SEPARATOR . 'index.json';
    $raw       = @file_get_contents($indexFile);
    $data      = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return [
            'current_branch' => 'main',
            'branches'       => ['main', 'develop'],
            'commits'        => []
        ];
    }
    if (empty($data['current_branch'])) {
        $data['current_branch'] = 'main';
    }
    if (empty($data['branches']) || !is_array($data['branches'])) {
        $data['branches'] = ['main'];
    }
    if (!isset($data['commits']) || !is_array($data['commits'])) {
        $data['commits'] = [];
    }
    return $data;
}

function vcs_write_index(array $data): void
{
    $vcsDir    = vcs_init_storage();
    $indexFile = $vcsDir . DIRECTORY_SEPARATOR . 'index.json';
    @file_put_contents($indexFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function vcs_create_commit(string $relPath, string $content, string $message = '', bool $force = false): array
{
    $relPath     = normalize_relative_path($relPath);
    $index       = vcs_read_index();
    $branch      = (string)$index['current_branch'];
    $contentSha1 = sha1($content);

    // Verificar si el último commit de este archivo en la misma rama tiene exactamente el mismo contenido
    if (!$force) {
        foreach ($index['commits'] as $existing) {
            if (($existing['path'] ?? '') === $relPath && ($existing['branch'] ?? 'main') === $branch) {
                if (($existing['sha1'] ?? '') === $contentSha1) {
                    return $existing;
                }
                break;
            }
        }
    }

    $hash       = substr(sha1($relPath . '|' . $branch . '|' . microtime(true) . '|' . random_int(1000, 999999)), 0, 7);
    $vcsDir     = vcs_init_storage();
    $snapPath   = $vcsDir . DIRECTORY_SEPARATOR . 'commits' . DIRECTORY_SEPARATOR . $hash . '.snap';
    @file_put_contents($snapPath, $content);

    $cleanMsg = trim($message);
    if ($cleanMsg === '') {
        $cleanMsg = 'Snapshot automático de ' . basename($relPath);
    }

    $commitRecord = [
        'hash'      => $hash,
        'path'      => $relPath,
        'branch'    => $branch,
        'message'   => $cleanMsg,
        'author'    => ADMIN_USERNAME,
        'timestamp' => date('Y-m-d H:i:s'),
        'size'      => strlen($content),
        'lines'     => substr_count($content, "\n") + 1,
        'sha1'      => $contentSha1
    ];

    array_unshift($index['commits'], $commitRecord);
    // Conservar hasta 300 commits recientes en el índice
    if (count($index['commits']) > 300) {
        $index['commits'] = array_slice($index['commits'], 0, 300);
    }
    vcs_write_index($index);

    return $commitRecord;
}

function vcs_get_commit_snapshot(string $hash): ?string
{
    if (!preg_match('/^[a-f0-9]{7,40}$/', $hash)) {
        return null;
    }
    $vcsDir   = vcs_init_storage();
    $snapPath = $vcsDir . DIRECTORY_SEPARATOR . 'commits' . DIRECTORY_SEPARATOR . $hash . '.snap';
    if (!is_file($snapPath)) {
        return null;
    }
    $raw = @file_get_contents($snapPath);
    return is_string($raw) ? $raw : null;
}

function vcs_get_file_commits(string $relPath): array
{
    $relPath = normalize_relative_path($relPath);
    $index   = vcs_read_index();
    $list    = [];
    foreach ($index['commits'] as $c) {
        if (($c['path'] ?? '') === $relPath) {
            $list[] = $c;
        }
    }
    return $list;
}

function vcs_scan_workspace_changes(string $subDirRel = '', array $forbiddenExtensions = []): array
{
    $index      = vcs_read_index();
    $latestSha1 = [];
    foreach ($index['commits'] as $c) {
        $p = (string)($c['path'] ?? '');
        if ($p !== '' && !isset($latestSha1[$p])) {
            $latestSha1[$p] = (string)($c['sha1'] ?? '');
        }
    }

    $root    = root_directory();
    $results = [];

    $walker = function (string $dirAbs) use (&$walker, &$results, $root, $forbiddenExtensions, $latestSha1): void {
        $items = @scandir($dirAbs);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === VCS_DIRECTORY_NAME || $item === '.git') {
                continue;
            }
            $abs = $dirAbs . DIRECTORY_SEPARATOR . $item;
            if (is_current_manager_file($abs) || is_vcs_internal_path($abs)) {
                continue;
            }
            if (is_dir($abs) && !is_link($abs)) {
                $walker($abs);
            } elseif (is_file($abs)) {
                $size = (int)@filesize($abs);
                if (!can_edit_file($item, $size, $forbiddenExtensions)) {
                    continue;
                }
                $rel  = relative_path($abs);
                $sha1 = @sha1_file($abs) ?: '';
                if (!isset($latestSha1[$rel])) {
                    $results[] = [
                        'path'   => $rel,
                        'status' => 'untracked',
                        'badge'  => 'U',
                        'size'   => format_bytes($size)
                    ];
                } elseif ($latestSha1[$rel] !== $sha1) {
                    $results[] = [
                        'path'   => $rel,
                        'status' => 'modified',
                        'badge'  => 'M',
                        'size'   => format_bytes($size)
                    ];
                }
            }
        }
    };

    $startAbs = safe_path($subDirRel);
    if (is_dir($startAbs)) {
        $walker($startAbs);
    }
    return $results;
}


/* ============================================================
 * LOGIN / LOGOUT
 * ============================================================ */
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
    redirect('?action=login');
}

/* ============================================================
 * LOGIN
 * ============================================================ */
if (isset($_GET['action']) && $_GET['action'] === 'login') {
    if (is_logged_in()) {
        redirect('?');
    }
    $loginError = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = (string)($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        if (
            hash_equals(ADMIN_USERNAME, $username) &&
            password_verify($password, PASSWORD_HASH)
        ) {
            session_regenerate_id(true);
            $_SESSION['file_manager_authenticated'] = true;
            redirect('?');
        } else {
            $loginError = 'Usuario o contraseña incorrectos.';
        }
    }
    ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f1f5f9">
    <title>Login - PHP File Manager</title>
    <style>
        /* ============================================================
           LOGIN
           ============================================================ */
        :root {
            --bg: #f1f5f9;
            --card: #ffffff;
            --border: #cbd5e1;
            --input: #f8fafc;
            --text: #0f172a;
            --muted: #64748b;
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --error: #fee2e2;
            --error-text: #991b1b;
        }
        *, *::before, *::after {
            box-sizing: border-box;
        }
        html {
            min-width: 320px;
        }
        body {
            margin: 0;
            min-width: 320px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 12px;
            background: var(--bg);
            color: var(--text);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        .login {
            width: 100%;
            max-width: 420px;
            padding: 22px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 15px 45px rgba(15, 23, 42, .12);
        }
        .login h2 {
            margin: 0 0 24px;
            font-size: clamp(20px, 7vw, 28px);
            line-height: 1.2;
            overflow-wrap: anywhere;
        }
        .login label {
            display: block;
            margin-bottom: 7px;
            color: var(--muted);
            font-size: 14px;
            font-weight: 700;
        }
        .login input {
            width: 100%;
            min-height: 46px;
            padding: 11px 12px;
            margin-bottom: 16px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--input);
            color: var(--text);
            font: inherit;
        }
        .login input:focus {
            outline: 3px solid rgba(37, 99, 235, .18);
            border-color: var(--primary);
        }
        .login button {
            width: 100%;
            min-height: 46px;
            padding: 11px;
            border: 0;
            border-radius: 8px;
            background: var(--primary);
            color: white;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }
        .login button:hover {
            background: var(--primary-hover);
        }
        .error {
            width: 100%;
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 8px;
            background: var(--error);
            color: var(--error-text);
            overflow-wrap: anywhere;
        }
        @media (min-width: 480px) {
            body { padding: 20px; }
            .login { padding: 32px; }
        }
    </style>
</head>
<body>
    <main class="login">
        <h2>🗂️ PHP File Manager</h2>
        <?php if ($loginError): ?>
            <div class="error"><?= e($loginError) ?></div>
        <?php endif; ?>
        <form method="post">
            <label for="username">Usuario</label>
            <input id="username" name="username" autocomplete="username" required>
            <label for="password">Contraseña</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            <button type="submit">Entrar</button>
        </form>
    </main>
</body>
</html>
<?php
    exit;
}

/* ============================================================
 * PROTECCIÓN
 * ============================================================ */
require_login();
$action      = $_GET['action'] ?? 'browse';
$currentPath = $_GET['path'] ?? '';

/* ============================================================
 * ENDPOINT AJAX NATIVO: VS CODE PATH INTELLISENSE
 * ============================================================
 * Permite autocompletar rutas reales del servidor al escribir
 * ./, ../, /, include, require, src="", href="", import, etc.
 */
if ($action === 'path_intellisense') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $baseFileDir = (string)($_GET['base_dir'] ?? '');
        $queryPath   = (string)($_GET['query'] ?? '');

        // Resolver ruta base dependiendo de si empieza con / (raíz del proyecto) o relativa al archivo editado
        if (str_starts_with($queryPath, '/')) {
            $lookupRel = ltrim($queryPath, '/');
        } else {
            $lookupRel = ($baseFileDir !== '' && $baseFileDir !== '.')
                ? $baseFileDir . '/' . $queryPath
                : $queryPath;
        }

        // Separar carpeta contenedora y prefijo parcial del archivo/carpeta
        $endsWithSlash = str_ends_with($queryPath, '/');
        if ($endsWithSlash || $queryPath === '.' || $queryPath === '..') {
            $targetDirRel = normalize_relative_path($lookupRel);
            $prefixFilter = '';
        } else {
            $dirPart      = dirname(str_replace('\\', '/', $lookupRel));
            $targetDirRel = ($dirPart === '.' || $dirPart === '/') ? '' : normalize_relative_path($dirPart);
            $prefixFilter = strtolower(basename(str_replace('\\', '/', $lookupRel)));
        }

        $absDir = safe_path($targetDirRel);
        if (!is_dir($absDir)) {
            echo json_encode(['ok' => true, 'items' => []]);
            exit;
        }

        $rawItems = scandir($absDir) ?: [];
        $folders  = [];
        $files    = [];

        foreach ($rawItems as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $absEntry = $absDir . DIRECTORY_SEPARATOR . $entry;
            if (is_current_manager_file($absEntry)) {
                continue;
            }
            if ($prefixFilter !== '' && !str_starts_with(strtolower($entry), $prefixFilter)) {
                continue;
            }
            $isDir = is_dir($absEntry);
            $itemData = [
                'name'  => $entry,
                'isDir' => $isDir,
                'icon'  => file_icon($entry, $isDir),
                'ext'   => $isDir ? 'dir' : strtolower(pathinfo($entry, PATHINFO_EXTENSION)),
                'size'  => $isDir ? '' : format_bytes((int)@filesize($absEntry))
            ];
            if ($isDir) {
                $folders[] = $itemData;
            } else {
                $files[] = $itemData;
            }
        }

        usort($folders, fn($a, $b) => strcmp($a['name'], $b['name']));
        usort($files, fn($a, $b) => strcmp($a['name'], $b['name']));

        echo json_encode([
            'ok'       => true,
            'resolved' => $targetDirRel,
            'items'    => array_merge($folders, $files)
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'items' => [], 'error' => $e->getMessage()]);
    }
    exit;
}


/* ============================================================
 * ENDPOINT AJAX NATIVO: CONTROL DE VERSIONES (VCS / GIT-STYLE)
 * ============================================================ */
if ($action === 'vcs_ajax') {
    header('Content-Type: application/json; charset=utf-8');
    $sub = (string)($_GET['sub'] ?? 'status');

    try {
        $index = vcs_read_index();

        // 1. Estado global o historial de un archivo específico
        if ($sub === 'file_history') {
            $relPath = normalize_relative_path((string)($_GET['path'] ?? ''));
            $commits = vcs_get_file_commits($relPath);
            echo json_encode([
                'ok'             => true,
                'current_branch' => $index['current_branch'],
                'branches'       => $index['branches'],
                'path'           => $relPath,
                'commits'        => $commits
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 2. Obtener el contenido de un commit específico para Diff o previsualización
        if ($sub === 'get_commit') {
            $hash    = (string)($_GET['hash'] ?? '');
            $content = vcs_get_commit_snapshot($hash);
            if ($content === null) {
                throw new RuntimeException('No se encontró el snapshot del commit solicitado.');
            }
            $meta = null;
            foreach ($index['commits'] as $c) {
                if (($c['hash'] ?? '') === $hash) {
                    $meta = $c;
                    break;
                }
            }
            echo json_encode([
                'ok'      => true,
                'hash'    => $hash,
                'meta'    => $meta,
                'content' => $content
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 3. Estado global del repositorio/directorio (archivos modificados M / sin seguimiento U + historial global)
        if ($sub === 'workspace_overview') {
            $changes = vcs_scan_workspace_changes('', $FORBIDDEN_EDIT_EXTENSIONS);
            echo json_encode([
                'ok'             => true,
                'current_branch' => $index['current_branch'],
                'branches'       => $index['branches'],
                'changes'        => $changes,
                'recent_commits' => array_slice($index['commits'], 0, 50)
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Acciones mutadoras por POST con verificación CSRF
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verify_csrf();

            // 4. Crear un commit de un archivo desde el editor sin recargar o vía formulario
            if ($sub === 'commit_file') {
                $relPath = normalize_relative_path((string)($_POST['path'] ?? ''));
                $fileAbs = safe_path($relPath);
                if (!is_file($fileAbs) || is_current_manager_file($fileAbs) || is_vcs_internal_path($fileAbs)) {
                    throw new RuntimeException('Archivo inválido para versionar.');
                }
                $content = isset($_POST['content']) ? (string)$_POST['content'] : (string)file_get_contents($fileAbs);
                if (isset($_POST['save_disk']) && $_POST['save_disk'] === '1') {
                    file_put_contents($fileAbs, $content);
                }
                $msg    = trim((string)($_POST['message'] ?? 'Commit manual'));
                $record = vcs_create_commit($relPath, $content, $msg, true);
                echo json_encode([
                    'ok'      => true,
                    'commit'  => $record,
                    'commits' => vcs_get_file_commits($relPath)
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // 5. Restaurar (Rollback) un archivo a un commit anterior
            if ($sub === 'restore_commit') {
                $hash    = (string)($_POST['hash'] ?? '');
                $snap    = vcs_get_commit_snapshot($hash);
                if ($snap === null) {
                    throw new RuntimeException('Snapshot no encontrado.');
                }
                $targetCommit = null;
                foreach ($index['commits'] as $c) {
                    if (($c['hash'] ?? '') === $hash) {
                        $targetCommit = $c;
                        break;
                    }
                }
                if (!$targetCommit) {
                    throw new RuntimeException('Metadatos del commit no encontrados.');
                }
                $relPath = normalize_relative_path((string)$targetCommit['path']);
                $fileAbs = safe_path($relPath);
                if (is_current_manager_file($fileAbs) || is_vcs_internal_path($fileAbs)) {
                    throw new RuntimeException('Operación no permitida.');
                }
                // Guardar respaldo previo automático antes de restaurar
                if (is_file($fileAbs)) {
                    $currentContent = (string)file_get_contents($fileAbs);
                    vcs_create_commit($relPath, $currentContent, 'Auto-backup previo a rollback #' . $hash, false);
                }
                if (file_put_contents($fileAbs, $snap) === false) {
                    throw new RuntimeException('No se pudo escribir el archivo restaurado.');
                }
                $newCommit = vcs_create_commit($relPath, $snap, 'Rollback a revisión #' . $hash, true);
                echo json_encode([
                    'ok'      => true,
                    'path'    => $relPath,
                    'content' => $snap,
                    'commit'  => $newCommit,
                    'commits' => vcs_get_file_commits($relPath)
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // 6. Commit masivo (Stage & Commit All) de todos los archivos modificados/nuevos
            if ($sub === 'commit_all') {
                $msg     = trim((string)($_POST['message'] ?? 'Commit general del proyecto'));
                $changes = vcs_scan_workspace_changes('', $FORBIDDEN_EDIT_EXTENSIONS);
                $created = [];
                foreach ($changes as $chg) {
                    $rel     = (string)$chg['path'];
                    $abs     = safe_path($rel);
                    if (is_file($abs)) {
                        $body      = (string)file_get_contents($abs);
                        $created[] = vcs_create_commit($rel, $body, $msg, true);
                    }
                }
                echo json_encode([
                    'ok'            => true,
                    'committed_cnt' => count($created),
                    'commits'       => $created
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // 7. Cambiar de rama o crear nueva rama (Branch)
            if ($sub === 'switch_branch') {
                $branchName = preg_replace('/[^a-zA-Z0-9_\-\/]/', '-', trim((string)($_POST['branch'] ?? 'main')));
                if ($branchName === '') {
                    throw new RuntimeException('Nombre de rama inválido.');
                }
                if (!in_array($branchName, $index['branches'], true)) {
                    $index['branches'][] = $branchName;
                }
                $index['current_branch'] = $branchName;
                vcs_write_index($index);
                echo json_encode([
                    'ok'             => true,
                    'current_branch' => $index['current_branch'],
                    'branches'       => $index['branches']
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        echo json_encode(['ok' => false, 'error' => 'Subacción VCS desconocida.']);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ============================================================
 * DOWNLOAD
 * ============================================================ */
if ($action === 'download') {
    try {
        $file = safe_path($currentPath);
        if (!is_file($file)) {
            throw new RuntimeException('El archivo no existe.');
        }
        /*
         * Nunca permitir descargar el propio File Manager.
         */
        if (is_current_manager_file($file)) {
            throw new RuntimeException('No puedes descargar el propio File Manager.');
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    } catch (Throwable $e) {
        die(e($e->getMessage()));
    }
}

/* ============================================================
 * CREATE FOLDER / FILE
 * ============================================================ */
if ($action === 'create_folder' || $action === 'create_file') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $name = trim((string)($_POST['name'] ?? ''));
        try {
            if ($name === '' || str_contains($name, '/') || str_contains($name, '\\')) {
                throw new RuntimeException('Nombre inválido.');
            }
            $parentPath = safe_path($currentPath);
            $newPath    = $parentPath . DIRECTORY_SEPARATOR . $name;
            if (file_exists($newPath)) {
                throw new RuntimeException('Ya existe un elemento con ese nombre.');
            }
            if ($action === 'create_folder') {
                if (!mkdir($newPath, 0755)) {
                    throw new RuntimeException('Error al crear carpeta.');
                }
                set_flash('Carpeta creada.');
            } else {
                if (file_put_contents($newPath, '') === false) {
                    throw new RuntimeException('Error al crear archivo.');
                }
                set_flash('Archivo creado.');
            }
        } catch (Throwable $e) {
            set_flash($e->getMessage(), 'error');
        }
        redirect('?' . http_build_query(['path' => $currentPath]));
    }
}

/* ============================================================
 * DELETE
 * ============================================================ */
if ($action === 'delete') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $target = (string)($_POST['target'] ?? '');
        try {
            $fullPath = safe_path($target);
            /*
             * Nunca permitir eliminar la raíz.
             */
            if ($fullPath === root_directory()) {
                throw new RuntimeException('No puedes eliminar la raíz.');
            }
            /*
             * PROTECCIÓN DEL PROPIO FILE MANAGER
             */
            if (is_current_manager_file($fullPath) || is_vcs_internal_path($fullPath)) {
                throw new RuntimeException('Por seguridad, no puedes eliminar el propio File Manager ni el repositorio de versiones.');
            }
            if (is_dir($fullPath)) {
                if (!delete_directory_recursive($fullPath)) {
                    throw new RuntimeException('Error al eliminar carpeta.');
                }
            } else {
                if (!unlink($fullPath)) {
                    throw new RuntimeException('Error al eliminar archivo.');
                }
            }
            set_flash('Elemento eliminado.');
        } catch (Throwable $e) {
            set_flash($e->getMessage(), 'error');
        }
        redirect('?' . http_build_query(['path' => dirname(normalize_relative_path($target))]));
    }
}

/* ============================================================
 * UPLOAD
 * ============================================================ */
if ($action === 'upload') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        try {
            $destPath = safe_path($currentPath);
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Error al subir el archivo.');
            }
            if ($_FILES['file']['size'] > MAX_UPLOAD_SIZE) {
                throw new RuntimeException('Archivo demasiado grande.');
            }
            $filename = basename($_FILES['file']['name']);
            if ($filename === '' || $filename === '.' || $filename === '..') {
                throw new RuntimeException('Nombre de archivo inválido.');
            }
            $finalDest = $destPath . DIRECTORY_SEPARATOR . $filename;
            /*
             * Evitar sobrescribir el File Manager.
             */
            if (file_exists($finalDest) && is_current_manager_file($finalDest)) {
                throw new RuntimeException('No puedes sobrescribir el propio File Manager.');
            }
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $finalDest)) {
                throw new RuntimeException('Error al mover el archivo subido.');
            }
            set_flash('Archivo subido correctamente.');
        } catch (Throwable $e) {
            set_flash($e->getMessage(), 'error');
        }
        redirect('?' . http_build_query(['path' => $currentPath]));
    }
}

/* ============================================================
 * EDIT
 * ============================================================ */
if ($action === 'edit') {
    try {
        $requestedEditFile = safe_path($currentPath);
        if (is_current_manager_file($requestedEditFile)) {
            set_flash('Por seguridad, no puedes editar el propio File Manager.', 'error');
            redirect('?' . http_build_query(['path' => dirname(normalize_relative_path($currentPath))]));
        }
    } catch (Throwable $e) {
        set_flash($e->getMessage(), 'error');
        redirect('?');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        try {
            $file = safe_path($currentPath);
            if (is_current_manager_file($file)) {
                throw new RuntimeException('Por seguridad, no puedes editar el propio File Manager.');
            }
            if (!is_file($file)) {
                throw new RuntimeException('Archivo no válido.');
            }
            if (filesize($file) > MAX_EDIT_SIZE) {
                throw new RuntimeException('El archivo es demasiado grande para editar.');
            }
            $content = (string)($_POST['content'] ?? '');
            // Respaldo inicial en VCS antes de sobrescribir si aún no tenía commits
            $relEditPath = relative_path($file);
            if (empty(vcs_get_file_commits($relEditPath))) {
                $prevContent = (string)@file_get_contents($file);
                vcs_create_commit($relEditPath, $prevContent, 'Versión base inicial de ' . basename($file), false);
            }
            if (file_put_contents($file, $content) === false) {
                throw new RuntimeException('Error al guardar el archivo.');
            }
            $commitMsg = trim((string)($_POST['commit_message'] ?? ''));
            if ($commitMsg === '') {
                $commitMsg = 'Guardado desde el editor (' . basename($file) . ')';
            }
            $commitRec = vcs_create_commit($relEditPath, $content, $commitMsg, false);
            set_flash('Archivo guardado y registrado en Control de Versiones (Commit #' . $commitRec['hash'] . ' en rama ' . $commitRec['branch'] . ').');
        } catch (Throwable $e) {
            set_flash($e->getMessage(), 'error');
        }
        redirect('?' . http_build_query(['action' => 'edit', 'path' => $currentPath]));
    }
}

/* ============================================================
 * FLASH
 * ============================================================ */
$flash = get_flash();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f1f5f9">
    <meta name="color-scheme" content="light dark">
    <title>PHP File Manager & Code IDE</title>
    <style>
        /* ============================================================
           PHP FILE MANAGER V2 - LIGHT / DARK MOBILE FIRST
           ============================================================ */
        :root {
            --bg: #f1f5f9;
            --surface: #ffffff;
            --surface-2: #f8fafc;
            --surface-3: #e2e8f0;
            --border: #cbd5e1;
            --border-soft: #e2e8f0;
            --text: #0f172a;
            --text-muted: #64748b;
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --danger: #dc2626;
            --danger-hover: #b91c1c;
            --success-bg: #dcfce7;
            --success-text: #166534;
            --error-bg: #fee2e2;
            --error-text: #991b1b;
            --input: #ffffff;
            --editor-bg: #0f172a;
            --editor-gutter-bg: #090e1a;
            --editor-gutter-text: #64748b;
            --editor-text: #e2e8f0;
            --shadow: 0 8px 30px rgba(15, 23, 42, .08);
            --radius: 10px;
            --radius-small: 7px;
            --touch-size: 44px;
        }

        html[data-theme="dark"] {
            --bg: #0b1120;
            --surface: #111827;
            --surface-2: #1f2937;
            --surface-3: #273449;
            --border: #374151;
            --border-soft: #263244;
            --text: #f9fafb;
            --text-muted: #9ca3af;
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --danger: #dc2626;
            --danger-hover: #b91c1c;
            --success-bg: #065f46;
            --success-text: #d1fae5;
            --error-bg: #991b1b;
            --error-text: #fee2e2;
            --input: #0f172a;
            --editor-bg: #020617;
            --editor-gutter-bg: #01040d;
            --editor-gutter-text: #475569;
            --editor-text: #e2e8f0;
            --shadow: 0 15px 45px rgba(0, 0, 0, .25);
        }

        *, *::before, *::after {
            box-sizing: border-box;
        }
        html {
            width: 100%;
            min-width: 320px;
            scroll-behavior: smooth;
        }
        body {
            margin: 0;
            width: 100%;
            min-width: 320px;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-size: 16px;
            line-height: 1.5;
            overflow-x: hidden;
            transition: background .2s ease, color .2s ease;
        }
        a {
            color: #2563eb;
            text-decoration: none;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        html[data-theme="dark"] a {
            color: #60a5fa;
        }
        a:hover {
            text-decoration: underline;
        }
        button, input, textarea, select {
            font: inherit;
        }
        button:focus-visible, a:focus-visible, input:focus-visible, textarea:focus-visible, select:focus-visible {
            outline: 3px solid rgba(37, 99, 235, .25);
            outline-offset: 2px;
        }
        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 10px 8px 40px;
        }
        header {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: 10px;
            padding: 10px 0 16px;
            margin-bottom: 16px;
            border-bottom: 1px solid var(--border);
        }
        header > div:first-child {
            min-width: 0;
        }
        header h2 {
            margin: 0;
            font-size: clamp(18px, 5vw, 26px);
            line-height: 1.25;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        header > div:last-child {
            width: 100%;
        }
        .btn {
            min-height: var(--touch-size);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 9px 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius-small);
            background: var(--surface-3);
            color: var(--text);
            cursor: pointer;
            font-size: 14px;
            font-weight: 650;
            line-height: 1.2;
            text-decoration: none;
            transition: background .15s ease, transform .1s ease, border-color .15s ease;
        }
        .btn:hover {
            background: var(--surface-2);
            text-decoration: none;
        }
        .btn:active {
            transform: translateY(1px);
        }
        .btn-primary {
            border-color: var(--primary);
            background: var(--primary);
            color: white;
        }
        .btn-primary:hover {
            background: var(--primary-hover);
            color: white;
        }
        .btn-danger {
            border-color: var(--danger);
            background: var(--danger);
            color: white;
        }
        .btn-danger:hover {
            background: var(--danger-hover);
            color: white;
        }
        .theme-button {
            background: var(--surface);
            color: var(--text);
            border: 1px solid var(--border);
        }
        .alert {
            width: 100%;
            padding: 12px;
            margin-bottom: 16px;
            border: 1px solid transparent;
            border-radius: var(--radius);
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .alert-success {
            background: var(--success-bg);
            color: var(--success-text);
        }
        .alert-error {
            background: var(--error-bg);
            color: var(--error-text);
        }
        .toolbar {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: 10px;
            margin-bottom: 16px;
            padding: 10px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }
        .toolbar form {
            width: 100% !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 8px !important;
            margin: 0 !important;
        }
        input[type="text"], input[type="file"], select.lang-select {
            width: 100%;
            min-width: 0;
            min-height: var(--touch-size);
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius-small);
            background: var(--input);
            color: var(--text);
            outline: none;
        }
        input[type="text"]:focus, input[type="file"]:focus, select.lang-select:focus {
            border-color: var(--primary);
            outline: 3px solid rgba(37, 99, 235, .12);
        }
        input[type="file"] {
            padding: 8px;
        }
        input::placeholder {
            color: var(--text-muted);
        }
        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: transparent;
        }
        thead {
            display: none;
        }
        tbody {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        tr {
            display: flex;
            flex-direction: column;
            width: 100%;
            padding: 11px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
        }
        td {
            width: 100%;
            display: block;
            padding: 5px 0;
            border: 0;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        td:first-child {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            min-width: 0;
            font-weight: 500;
        }
        td:first-child a, td:first-child span {
            min-width: 0;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        td:nth-child(2)::before {
            content: "Tamaño: ";
            color: var(--text-muted);
            font-weight: 650;
        }
        td:nth-child(3)::before {
            content: "Modificado: ";
            color: var(--text-muted);
            font-weight: 650;
        }
        .actions {
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 8px;
            padding-top: 10px;
            border-top: 1px solid var(--border-soft);
        }
        .actions .btn, .actions form, .actions form .btn {
            width: 100%;
        }
        .actions form {
            display: block !important;
            margin: 0 !important;
        }

        /* ============================================================
           NATIVE VS CODE STYLE CODE EDITOR + LINE NUMBERS + PATH INTELLISENSE
           ============================================================ */
        .ide-shell {
            position: relative;
            width: 100%;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            background: var(--editor-bg);
            box-shadow: var(--shadow);
        }
        .ide-header-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 8px 12px;
            background: #090e1a;
            border-bottom: 1px solid #1e293b;
            color: #cbd5e1;
            font-size: 12px;
        }
        .ide-badges {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px;
        }
        .ide-badge {
            padding: 3px 8px;
            border-radius: 5px;
            background: rgba(56, 189, 248, 0.14);
            border: 1px solid rgba(56, 189, 248, 0.35);
            color: #38bdf8;
            font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
            font-size: 11px;
            font-weight: 600;
        }
        .ide-badge-path {
            background: rgba(16, 185, 129, 0.14);
            border-color: rgba(16, 185, 129, 0.35);
            color: #34d399;
        }
        .ide-body {
            position: relative;
            display: flex;
            width: 100%;
            height: 62vh;
            min-height: 360px;
            overflow: hidden;
            background: var(--editor-bg);
        }
        .ide-line-numbers {
            width: 54px;
            padding: 14px 8px 14px 4px;
            text-align: right;
            background: var(--editor-gutter-bg);
            color: var(--editor-gutter-text);
            border-right: 1px solid #1e293b;
            user-select: none;
            font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
            font-size: 13.5px;
            line-height: 1.6;
            overflow: hidden;
            flex-shrink: 0;
        }
        .ide-line-numbers .active-ln {
            color: #38bdf8;
            font-weight: 700;
        }
        .ide-stage {
            position: relative;
            flex: 1;
            height: 100%;
            overflow: hidden;
        }
        #codeEditorTextarea,
        #codeEditorHighlight {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            margin: 0;
            padding: 14px 16px;
            border: 0;
            font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
            font-size: 13.5px;
            line-height: 1.6;
            tab-size: 4;
            white-space: pre;
            overflow: auto;
            word-wrap: normal;
        }
        #codeEditorTextarea {
            color: transparent;
            background: transparent;
            caret-color: #38bdf8;
            z-index: 2;
            resize: none;
            outline: none;
        }
        #codeEditorTextarea::selection {
            background: rgba(56, 189, 248, 0.25);
            color: transparent;
        }
        #codeEditorHighlight {
            z-index: 1;
            pointer-events: none;
            color: var(--editor-text);
        }
        .ide-status-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 6px 12px;
            background: #090e1a;
            border-top: 1px solid #1e293b;
            color: #94a3b8;
            font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
            font-size: 11.5px;
        }

        /* VS Code Path Intellisense & Code Completion Floating Popup */
        .vsc-intellisense-popup {
            position: absolute;
            z-index: 30;
            width: min(360px, 90vw);
            max-height: 240px;
            display: none;
            flex-direction: column;
            background: #0f172a;
            border: 1px solid #38bdf8;
            border-radius: 8px;
            box-shadow: 0 18px 40px rgba(0, 0, 0, 0.65);
            overflow: hidden;
            font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
        }
        .vsc-intellisense-popup.open {
            display: flex;
        }
        .vsc-popup-header {
            padding: 5px 10px;
            background: #1e293b;
            border-bottom: 1px solid #334155;
            color: #94a3b8;
            font-size: 10.5px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .vsc-popup-list {
            list-style: none;
            margin: 0;
            padding: 0;
            overflow-y: auto;
            max-height: 200px;
        }
        .vsc-popup-item {
            padding: 6px 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            font-size: 12px;
            color: #e2e8f0;
            cursor: pointer;
            border-bottom: 1px solid rgba(51, 65, 85, 0.4);
        }
        .vsc-popup-item:hover,
        .vsc-popup-item.active {
            background: rgba(37, 99, 235, 0.38);
            color: #ffffff;
        }
        .vsc-item-left {
            display: flex;
            align-items: center;
            gap: 7px;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .vsc-item-kind {
            font-size: 10px;
            color: #94a3b8;
            padding: 1px 5px;
            border-radius: 4px;
            background: rgba(15, 23, 42, 0.6);
            flex-shrink: 0;
        }

        /* Syntax Highlight Tokens (PHP, JS, HTML, CSS, C/C++, Java, Python) */
        .tok-kw   { color: #c084fc; font-weight: 600; } /* Keywords */
        .tok-type { color: #2dd4bf; font-weight: 600; } /* C/C++/Java/PHP Types */
        .tok-pre  { color: #fb7185; font-weight: 600; } /* Preprocessor #include / #define / @Override */
        .tok-var  { color: #38bdf8; }                   /* PHP $vars / self / this */
        .tok-str  { color: #4ade80; }                   /* Strings */
        .tok-com  { color: #64748b; font-style: italic; } /* Comments */
        .tok-num  { color: #fb923c; }                   /* Numbers */
        .tok-fn   { color: #60a5fa; }                   /* Functions / Methods */
        .tok-tag  { color: #f472b6; }                   /* HTML/PHP tags */
        .tok-attr { color: #facc15; }                   /* HTML attrs / CSS properties */


        /* ============================================================
           VERSION CONTROL SYSTEM (VCS / GIT-STYLE DIFF & HISTORY MODAL)
           ============================================================ */
        .vcs-modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 60;
            background: rgba(2, 6, 23, 0.78);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 12px;
        }
        .vcs-modal-backdrop.open {
            display: flex;
        }
        .vcs-modal-card {
            width: 100%;
            max-width: 1040px;
            max-height: 90vh;
            background: var(--surface);
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.45);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .vcs-modal-header {
            padding: 14px 18px;
            border-bottom: 1px solid var(--border);
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            background: var(--surface-2);
        }
        .vcs-modal-body {
            padding: 16px;
            overflow-y: auto;
            flex: 1;
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
        }
        @media (min-width: 860px) {
            .vcs-modal-body {
                grid-template-columns: 360px 1fr;
            }
        }
        .vcs-panel-box {
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--surface-2);
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .vcs-commit-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
            max-height: 360px;
            overflow-y: auto;
        }
        .vcs-commit-item {
            padding: 10px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--surface);
            cursor: pointer;
            transition: border-color .15s ease, background .15s ease;
        }
        .vcs-commit-item:hover,
        .vcs-commit-item.active {
            border-color: var(--primary);
            background: var(--surface-3);
        }
        .vcs-hash-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 5px;
            font-family: "SFMono-Regular", Consolas, monospace;
            font-size: 11px;
            font-weight: 700;
            background: rgba(37, 99, 235, 0.15);
            color: #3b82f6;
        }
        .vcs-branch-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 999px;
            font-family: "SFMono-Regular", Consolas, monospace;
            font-size: 11px;
            font-weight: 700;
            background: rgba(16, 185, 129, 0.16);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.35);
        }
        .vcs-diff-viewer {
            background: #020617;
            color: #e2e8f0;
            border: 1px solid #1e293b;
            border-radius: 8px;
            padding: 10px;
            font-family: "SFMono-Regular", Consolas, monospace;
            font-size: 12.5px;
            line-height: 1.55;
            overflow: auto;
            max-height: 400px;
            white-space: pre;
        }
        .diff-line-add {
            background: rgba(16, 185, 129, 0.2);
            color: #6ee7b7;
            display: block;
            padding: 0 6px;
        }
        .diff-line-del {
            background: rgba(239, 68, 68, 0.22);
            color: #fca5a5;
            display: block;
            padding: 0 6px;
        }
        .diff-line-ctx {
            color: #94a3b8;
            display: block;
            padding: 0 6px;
        }
        .vcs-status-badge-M {
            padding: 2px 6px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 11px;
            font-weight: 700;
            background: rgba(245, 158, 11, 0.2);
            color: #f59e0b;
        }
        .vcs-status-badge-U {
            padding: 2px 6px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 11px;
            font-weight: 700;
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
        }

        .toolbar > span {
            min-width: 0;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        tbody tr td[colspan] {
            text-align: center;
            padding: 30px 15px;
            color: var(--text-muted);
        }

        @media (min-width: 320px) and (max-width: 359px) {
            .container { padding-left: 7px; padding-right: 7px; }
            header { gap: 8px; }
            header h2 { font-size: 18px; }
            .toolbar { padding: 8px; }
            .btn { font-size: 13px; padding-left: 9px; padding-right: 9px; }
            tr { padding: 9px; }
            #codeEditorTextarea, #codeEditorHighlight, .ide-line-numbers { font-size: 12.5px; }
        }
        @media (min-width: 360px) {
            .container { padding-left: 10px; padding-right: 10px; }
        }
        @media (min-width: 480px) {
            .container { padding-left: 16px; padding-right: 16px; }
            .toolbar form { flex-direction: row !important; align-items: stretch; }
            .toolbar form input[type="text"] { flex: 1; }
            .toolbar form .btn { width: auto; }
            .actions { flex-direction: row; flex-wrap: wrap; }
            .actions .btn, .actions form, .actions form .btn { width: auto; }
        }
        @media (min-width: 768px) {
            .container { padding: 20px 20px 50px; }
            header { flex-direction: row; align-items: center; justify-content: space-between; }
            header > div:last-child { width: auto; }
            .toolbar { flex-direction: row; align-items: center; flex-wrap: wrap; }
            .toolbar form { width: auto !important; }
            thead { display: table-header-group; }
            tbody { display: table-row-group; }
            tr { display: table-row; padding: 0; background: var(--surface); border: 0; border-radius: 0; box-shadow: none; }
            td, th { display: table-cell; padding: 12px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: middle; }
            td:nth-child(2)::before, td:nth-child(3)::before { content: ""; }
            .actions { display: flex; flex-direction: row; margin: 0; padding: 0; border: 0; }
            .actions form { display: inline !important; }
            .actions .btn, .actions form, .actions form .btn { width: auto; }
            tr:hover { background: var(--surface-3); }
        }
        @media (min-width: 1024px) {
            .container { padding-left: 20px; padding-right: 20px; }
            .toolbar { padding: 15px; }
            .btn { min-height: 40px; }
            td, th { padding: 12px; }
            .ide-body { height: 68vh; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                scroll-behavior: auto !important;
                transition: none !important;
                animation: none !important;
            }
        }
        @media print {
            body { background: white; color: black; }
            .toolbar, header > div:last-child, .actions { display: none !important; }
            .container { max-width: none; }
            tr { background: white; color: black; }
        }
    </style>
</head>
<body>
<div class="container">
    <!-- ==========================================================
         HEADER
         ========================================================== -->
    <header>
        <div>
            <h2>🗂️ / <?= e($currentPath) ?></h2>
        </div>
        <?php $vcsHeaderIndex = vcs_read_index(); ?>
        <div style="display:flex; flex-wrap:wrap; align-items:center; gap:8px;">
            <!-- CONTROL DE VERSIONES GLOBAL -->
            <button type="button" class="btn" id="btnOpenGlobalVcs" style="border-color:#10b981;">
                🌿 Versiones / Git (<span id="headerBranchName"><?= e((string)$vcsHeaderIndex['current_branch']) ?></span>)
            </button>
            <!-- THEME -->
            <button type="button" class="btn theme-button" id="themeToggle" aria-label="Cambiar tema">
                ☀️ Light
            </button>
            <!-- LOGOUT -->
            <a href="?action=logout" class="btn btn-danger">
                Cerrar Sesión
            </a>
        </div>
    </header>

    <!-- ==========================================================
         FLASH
         ========================================================== -->
    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>">
            <?= e($flash['message']) ?>
        </div>
    <?php endif; ?>

    <!-- ==========================================================
         EDITOR DE CÓDIGO NATIVO (CON VS CODE PATH INTELLISENSE)
         ========================================================== -->
    <?php if ($action === 'edit'): ?>
        <?php
        $fullPath = safe_path($currentPath);
        if (!is_file($fullPath)) {
            die('Archivo no válido.');
        }
        if (is_current_manager_file($fullPath)) {
            die('No puedes editar el propio File Manager.');
        }
        $content      = (string)file_get_contents($fullPath);
        $detectedLang = detect_code_language(basename($fullPath));
        $fileDirRel   = dirname(normalize_relative_path($currentPath));
        if ($fileDirRel === '.') {
            $fileDirRel = '';
        }
        ?>
        <div class="toolbar" style="justify-content: space-between;">
            <div style="display:flex; flex-wrap:wrap; align-items:center; gap:10px;">
                <a href="?path=<?= e(dirname($currentPath)) ?>" class="btn">
                    ⬅️ Volver
                </a>
                <span>
                    Editando: <strong><?= e(basename($fullPath)) ?></strong>
                </span>
            </div>

            <div style="display:flex; flex-wrap:wrap; align-items:center; gap:8px;">
                <label for="ideLangSelect" style="font-size:13px; color:var(--text-muted); font-weight:600;">Lenguaje:</label>
                <select id="ideLangSelect" class="lang-select" style="width:auto; min-height:40px; padding:6px 10px; font-size:13px;">
                    <option value="auto" <?= $detectedLang === 'auto' ? 'selected' : '' ?>>Auto-detectar</option>
                    <option value="php" <?= $detectedLang === 'php' ? 'selected' : '' ?>>🐘 PHP</option>
                    <option value="javascript" <?= $detectedLang === 'javascript' ? 'selected' : '' ?>>⚡ JavaScript / TS</option>
                    <option value="html" <?= $detectedLang === 'html' ? 'selected' : '' ?>>🌐 HTML / XML</option>
                    <option value="css" <?= $detectedLang === 'css' ? 'selected' : '' ?>>🎨 CSS / SCSS</option>
                    <option value="cpp" <?= $detectedLang === 'cpp' ? 'selected' : '' ?>>⚙️ C / C++</option>
                    <option value="java" <?= $detectedLang === 'java' ? 'selected' : '' ?>>☕ Java</option>
                    <option value="python" <?= $detectedLang === 'python' ? 'selected' : '' ?>>🐍 Python</option>
                </select>
                <button type="button" class="btn" id="btnTriggerPathIntellisense" title="Autocompletar rutas del servidor (./ o ../ o /)">
                    📂 Rutas (Path)
                </button>
                <button type="button" class="btn" id="btnTriggerCodeIntellisense" title="Atajo: Ctrl + Espacio">
                    ✨ Autocompletar
                </button>
                <?php $fileCommitsList = vcs_get_file_commits($currentPath); ?>
                <button type="button" class="btn btn-primary" id="btnOpenFileVcs" data-vcs-path="<?= e(normalize_relative_path($currentPath)) ?>">
                    🌿 Historial / Diff (<span id="fileCommitCountBadge"><?= count($fileCommitsList) ?></span>)
                </button>
            </div>
        </div>

        <form method="post" id="editFileForm">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <div class="ide-shell">
                <!-- Barra Informativa Superior del Editor -->
                <div class="ide-header-bar">
                    <div class="ide-badges">
                        <span class="ide-badge" id="badgeDetectedLang">LENGUAJE: <?= strtoupper(e($detectedLang)) ?></span>
                        <span class="ide-badge ide-badge-path">✓ VS Code Path Intellisense Activo (`./`, `../`, `/`)</span>
                    </div>
                    <div style="font-size:11px; color:#94a3b8;">
                        Escribe <code>./</code>, <code>../</code> o <code>/</code> para autocompletar archivos reales • <code>Ctrl+Espacio</code> Sugerencias
                    </div>
                </div>

                <!-- Cuerpo Principal del Editor con Números de Línea + Highlight + Textarea -->
                <div class="ide-body">
                    <div class="ide-line-numbers" id="ideLineNumbers" aria-hidden="true">1</div>
                    <div class="ide-stage">
                        <pre id="codeEditorHighlight" aria-hidden="true"></pre>
                        <textarea
                            id="codeEditorTextarea"
                            name="content"
                            spellcheck="false"
                            autocomplete="off"
                            autocorrect="off"
                            autocapitalize="off"
                            data-file-dir="<?= e($fileDirRel) ?>"
                            data-file-name="<?= e(basename($fullPath)) ?>"
                        ><?= e($content) ?></textarea>

                        <!-- Popup Flotante Nativo: VS Code Path Intellisense + Autocompletado -->
                        <div class="vsc-intellisense-popup" id="vscPopup" role="listbox" aria-label="Sugerencias de autocompletado">
                            <div class="vsc-popup-header">
                                <span id="vscPopupTitle">📂 VS Code Path Intellisense</span>
                                <span>Tab / Enter ↵</span>
                            </div>
                            <ul class="vsc-popup-list" id="vscPopupList"></ul>
                        </div>
                    </div>
                </div>

                <!-- Barra de Estado Inferior -->
                <div class="ide-status-bar">
                    <div style="display:flex; align-items:center; gap:14px;">
                        <span id="statusLineCol">Ln 1, Col 1</span>
                        <span id="statusLinesTotal">1 líneas</span>
                        <span id="statusCharCount">0 caracteres</span>
                    </div>
                    <div style="display:flex; align-items:center; gap:14px;">
                        <span id="statusPathMode">Path Base: /<?= e($fileDirRel) ?></span>
                        <span>UTF-8</span>
                    </div>
                </div>
            </div>

            <div style="height:16px;"></div>
            <div class="toolbar" style="margin-bottom:0; align-items:center; justify-content:space-between;">
                <div style="display:flex; flex-wrap:wrap; align-items:center; gap:8px; flex:1;">
                    <input
                        type="text"
                        name="commit_message"
                        id="inputCommitMessage"
                        placeholder="📝 Mensaje del commit (ej. Fix validación, nueva función)..."
                        style="flex:1; min-width:220px;"
                    >
                    <button type="submit" class="btn btn-primary">
                        💾 Guardar + Commit (Ctrl+S)
                    </button>
                    <button type="button" class="btn" id="btnQuickAjaxCommit" title="Crear snapshot en el Control de Versiones sin salir del editor">
                        🌿 Snapshot Rápido
                    </button>
                </div>
                <a href="?action=download&path=<?= urlencode($currentPath) ?>" class="btn">
                    ⬇️ Descargar Archivo
                </a>
            </div>
        </form>
    <?php else: ?>
        <!-- ==========================================================
             BROWSE MODE (INTACTO)
             ========================================================== -->
        <div class="toolbar">
            <!-- SUBIR NIVEL -->
            <?php if ($currentPath !== ''): ?>
                <a href="?path=<?= e(dirname($currentPath)) ?>" class="btn">
                    ⬅️ Subir Nivel
                </a>
            <?php endif; ?>

            <!-- CREAR CARPETA -->
            <form method="post" action="?action=create_folder&path=<?= e($currentPath) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="text" name="name" placeholder="Nueva Carpeta" autocomplete="off" required>
                <button type="submit" class="btn btn-primary">
                    📁 Crear Carpeta
                </button>
            </form>

            <!-- CREAR ARCHIVO -->
            <form method="post" action="?action=create_file&path=<?= e($currentPath) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="text" name="name" placeholder="Nuevo Archivo" autocomplete="off" required>
                <button type="submit" class="btn btn-primary">
                    📄 Crear Archivo
                </button>
            </form>

            <!-- UPLOAD -->
            <form method="post" action="?action=upload&path=<?= e($currentPath) ?>" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="file" name="file" required>
                <button type="submit" class="btn btn-primary">
                    📤 Subir
                </button>
            </form>
        </div>

        <!-- ==========================================================
             FILE LIST
             ========================================================== -->
        <table>
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Tamaño</th>
                    <th>Última Modificación</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $dirPath = safe_path($currentPath);
                $items   = scandir($dirPath) ?: [];
                $folders = [];
                $files   = [];

                foreach ($items as $item) {
                    if ($item === '.' || $item === '..' || $item === VCS_DIRECTORY_NAME) {
                        continue;
                    }
                    $itemAbsolutePath = $dirPath . DIRECTORY_SEPARATOR . $item;
                    /*
                     * NO MOSTRAR EL PROPIO FILE MANAGER
                     */
                    if (is_current_manager_file($itemAbsolutePath)) {
                        continue;
                    }
                    if (is_dir($itemAbsolutePath)) {
                        $folders[] = $item;
                    } else {
                        $files[] = $item;
                    }
                }

                sort($folders);
                sort($files);

                foreach (array_merge($folders, $files) as $item):
                    $itemPath    = $dirPath . DIRECTORY_SEPARATOR . $item;
                    $relItemPath = relative_path($itemPath);
                    $isDir       = is_dir($itemPath);
                    $size        = $isDir ? '-' : format_bytes((int)filesize($itemPath));
                    $mtime       = date('Y-m-d H:i', (int)filemtime($itemPath));
                ?>
                    <tr>
                        <!-- NAME -->
                        <td>
                            <span aria-hidden="true"><?= file_icon($item, $isDir) ?></span>
                            <?php if ($isDir): ?>
                                <a href="?path=<?= urlencode($relItemPath) ?>">
                                    <strong><?= e($item) ?></strong>
                                </a>
                            <?php else: ?>
                                <span><?= e($item) ?></span>
                            <?php endif; ?>
                        </td>

                        <!-- SIZE -->
                        <td><?= e($size) ?></td>

                        <!-- MODIFICATION -->
                        <td><?= e($mtime) ?></td>

                        <!-- ACTIONS -->
                        <td>
                            <div class="actions">
                                <?php if (!$isDir): ?>
                                    <!-- DOWNLOAD -->
                                    <a href="?action=download&path=<?= urlencode($relItemPath) ?>" class="btn">
                                        ⬇️ Descargar
                                    </a>
                                    <!-- EDIT & VCS HISTORY -->
                                    <?php if (can_edit_file($item, (int)filesize($itemPath), $FORBIDDEN_EDIT_EXTENSIONS)): ?>
                                        <a href="?action=edit&path=<?= urlencode($relItemPath) ?>" class="btn btn-primary">
                                            ✏️ Editar
                                        </a>
                                        <button type="button" class="btn btn-row-vcs" data-vcs-path="<?= e($relItemPath) ?>">
                                            🌿 Versiones (<?= count(vcs_get_file_commits($relItemPath)) ?>)
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <!-- DELETE -->
                                <form method="post" action="?action=delete" onsubmit="return confirm('¿Seguro que deseas eliminar esto?');">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="target" value="<?= e($relItemPath) ?>">
                                    <button type="submit" class="btn btn-danger">
                                        🗑️ Borrar
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <!-- EMPTY DIRECTORY -->
                <?php if (empty($folders) && empty($files)): ?>
                    <tr>
                        <td colspan="4">📂 El directorio está vacío.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- ==========================================================
         MODAL DE CONTROL DE VERSIONES (GIT-STYLE VCS: RAMAS, COMMITS, DIFF & ROLLBACK)
         ========================================================== -->
    <div class="vcs-modal-backdrop" id="vcsModalBackdrop" role="dialog" aria-modal="true" aria-labelledby="vcsModalTitle">
        <div class="vcs-modal-card">
            <div class="vcs-modal-header">
                <div style="display:flex; flex-wrap:wrap; align-items:center; gap:10px;">
                    <strong id="vcsModalTitle" style="font-size:16px;">🌿 Control de Versiones Nativo (VCS)</strong>
                    <span class="vcs-branch-pill">Rama: <span id="vcsActiveBranchLabel"><?= e((string)$vcsHeaderIndex['current_branch']) ?></span></span>
                </div>
                <div style="display:flex; flex-wrap:wrap; align-items:center; gap:8px;">
                    <input type="text" id="vcsNewBranchInput" placeholder="Nueva rama (ej. feature-1)" style="width:175px; min-height:36px; padding:6px 10px; font-size:13px;">
                    <button type="button" class="btn" id="btnCreateOrSwitchBranch" style="min-height:36px; padding:6px 10px; font-size:13px;">
                        🔀 Cambiar / Crear Rama
                    </button>
                    <button type="button" class="btn btn-danger" id="btnCloseVcsModal" style="min-height:36px; padding:6px 12px; font-size:13px;">
                        ✕ Cerrar
                    </button>
                </div>
            </div>

            <div class="vcs-modal-body">
                <!-- Columna Izquierda: Estado de Cambios + Lista de Commits -->
                <div style="display:flex; flex-direction:column; gap:12px;">
                    <!-- Bloque de Commit General o de Archivo -->
                    <div class="vcs-panel-box">
                        <div style="display:flex; align-items:center; justify-content:space-between;">
                            <strong style="font-size:13px;" id="vcsLeftTopTitle">📌 Crear Nuevo Commit / Snapshot</strong>
                            <button type="button" class="btn" id="btnShowWorkspaceOverview" style="min-height:30px; padding:4px 8px; font-size:11.5px;">
                                🌐 Ver Todo el Proyecto
                            </button>
                        </div>
                        <input type="text" id="vcsModalCommitMsg" placeholder="Mensaje del commit (ej. Refactorización, versión estable)..." style="min-height:38px; font-size:13px;">
                        <button type="button" class="btn btn-primary" id="btnVcsCommitAction" style="min-height:38px; font-size:13px;">
                            ✅ Confirmar Commit
                        </button>
                    </div>

                    <!-- Archivos Modificados (M) / Sin Seguimiento (U) -->
                    <div class="vcs-panel-box" id="vcsWorkspaceChangesBox">
                        <strong style="font-size:13px;">📂 Estado de Archivos (Working Tree)</strong>
                        <div id="vcsChangesList" style="font-size:12.5px; max-height:130px; overflow-y:auto; display:flex; flex-direction:column; gap:6px;"></div>
                    </div>

                    <!-- Historial de Commits -->
                    <div class="vcs-panel-box" style="flex:1;">
                        <strong style="font-size:13px;" id="vcsHistoryHeaderLabel">🕒 Historial de Commits</strong>
                        <ul class="vcs-commit-list" id="vcsCommitList"></ul>
                    </div>
                </div>

                <!-- Columna Derecha: Visor de Diferencias (Visual Diff +/-) y Rollback -->
                <div class="vcs-panel-box" style="justify-content:space-between;">
                    <div style="display:flex; flex-direction:column; gap:10px;">
                        <div style="display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:8px;">
                            <div>
                                <strong style="font-size:14px;" id="vcsDiffTitle">Selecciona un commit para inspeccionar el Diff</strong>
                                <div id="vcsDiffMeta" style="font-size:12px; color:var(--text-muted);"> Comparación línea por línea (+ añadidas / - eliminadas)</div>
                            </div>
                            <div style="display:flex; flex-wrap:wrap; gap:6px;" id="vcsCommitActionsBar">
                                <button type="button" class="btn" id="btnLoadCommitIntoEditor" style="display:none; min-height:36px; padding:6px 10px; font-size:12.5px;">
                                    👁️ Cargar en Editor
                                </button>
                                <button type="button" class="btn btn-primary" id="btnRestoreCommitDisk" style="display:none; min-height:36px; padding:6px 10px; font-size:12.5px;">
                                    ⏪ Restaurar esta Versión (Rollback)
                                </button>
                            </div>
                        </div>

                        <div class="vcs-diff-viewer" id="vcsDiffViewer">Selecciona cualquier versión en la lista izquierda para comparar sus diferencias o restaurarla con un clic.</div>
                    </div>

                    <div style="font-size:12px; color:var(--text-muted); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                        <span>🛡️ Al restaurar una versión anterior, el sistema crea automáticamente un backup previo.</span>
                        <span id="vcsFeedbackStatus" style="font-weight:700; color:#10b981;"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- ==========================================================
     JAVASCRIPT: THEME MANAGER + VS CODE PATH INTELLISENSE & MULTI-LANGUAGE IDE
     ========================================================== -->
<script>
(function () {
    'use strict';

    /* ============================================================
       1. THEME MANAGER (ORIGINAL INTACTO)
       ============================================================ */
    const html   = document.documentElement;
    const button = document.getElementById('themeToggle');
    let savedTheme = null;
    try {
        savedTheme = localStorage.getItem('php_file_manager_theme');
    } catch (_) {}

    if (savedTheme !== 'dark' && savedTheme !== 'light') {
        savedTheme = 'light';
    }
    html.dataset.theme = savedTheme;

    function updateThemeButton() {
        if (!button) return;
        if (html.dataset.theme === 'dark') {
            button.textContent = '🌙 Dark';
            button.setAttribute('aria-label', 'Cambiar a tema claro');
        } else {
            button.textContent = '☀️ Light';
            button.setAttribute('aria-label', 'Cambiar a tema oscuro');
        }
    }
    updateThemeButton();

    if (button) {
        button.addEventListener('click', function () {
            const newTheme = html.dataset.theme === 'dark' ? 'light' : 'dark';
            html.dataset.theme = newTheme;
            try {
                localStorage.setItem('php_file_manager_theme', newTheme);
            } catch (_) {}
            updateThemeButton();
        });
    }


    /* ============================================================
       4. CONTROL DE VERSIONES NATIVO (GIT-STYLE VCS: RAMAS, COMMITS, DIFF, ROLLBACK)
       ============================================================ */
    const vcsModal          = document.getElementById('vcsModalBackdrop');
    const btnOpenGlobalVcs  = document.getElementById('btnOpenGlobalVcs');
    const btnCloseVcsModal  = document.getElementById('btnCloseVcsModal');
    const vcsCommitList     = document.getElementById('vcsCommitList');
    const vcsChangesList    = document.getElementById('vcsChangesList');
    const vcsDiffViewer     = document.getElementById('vcsDiffViewer');
    const vcsDiffTitle      = document.getElementById('vcsDiffTitle');
    const vcsDiffMeta       = document.getElementById('vcsDiffMeta');
    const btnLoadIntoEditor = document.getElementById('btnLoadCommitIntoEditor');
    const btnRestoreDisk    = document.getElementById('btnRestoreCommitDisk');
    const vcsFeedbackStatus = document.getElementById('vcsFeedbackStatus');
    const csrfTokenInput    = document.querySelector('input[name="csrf_token"]');
    const csrfTokenValue    = csrfTokenInput ? csrfTokenInput.value : '';

    let vcsFocusedFilePath  = ''; // '' = modo global del proyecto, o ruta relativa del archivo
    let vcsSelectedCommit   = null; // { hash, meta, content }

    function setVcsStatus(msg, isError = false) {
        if (!vcsFeedbackStatus) return;
        vcsFeedbackStatus.textContent = msg;
        vcsFeedbackStatus.style.color = isError ? '#ef4444' : '#10b981';
    }

    function escapeVcsHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    // Algoritmo de Diff visual línea por línea (compara snapshot del commit vs contenido actual)
    function buildVisualDiffHtml(oldText, newText, oldLabel, newLabel) {
        const oldLines = String(oldText).split('\n');
        const newLines = String(newText).split('\n');
        let out = `<span class="diff-line-ctx">--- ${escapeVcsHtml(oldLabel)} (${oldLines.length} líneas)</span>`;
        out    += `<span class="diff-line-ctx">+++ ${escapeVcsHtml(newLabel)} (${newLines.length} líneas)</span>\n`;

        const maxLen = Math.max(oldLines.length, newLines.length);
        let changesCount = 0;

        for (let i = 0; i < maxLen; i++) {
            const lineA = i < oldLines.length ? oldLines[i] : undefined;
            const lineB = i < newLines.length ? newLines[i] : undefined;
            const lnNum = String(i + 1).padStart(4, ' ');

            if (lineA === lineB) {
                out += `<span class="diff-line-ctx">${lnNum}   ${escapeVcsHtml(lineA)}</span>`;
            } else {
                changesCount++;
                if (lineA !== undefined) {
                    out += `<span class="diff-line-del">${lnNum} - ${escapeVcsHtml(lineA)}</span>`;
                }
                if (lineB !== undefined) {
                    out += `<span class="diff-line-add">${lnNum} + ${escapeVcsHtml(lineB)}</span>`;
                }
            }
        }

        if (changesCount === 0) {
            out += `\n<span class="diff-line-add">✓ El contenido de esta versión es idéntico al archivo actual.</span>`;
        }
        return out;
    }

    async function openVcsForFile(relPath) {
        vcsFocusedFilePath = relPath;
        vcsSelectedCommit  = null;
        if (vcsModal) vcsModal.classList.add('open');
        setVcsStatus('Cargando historial de versiones...');

        document.getElementById('vcsLeftTopTitle').textContent      = `📌 Commit de: ${relPath}`;
        document.getElementById('vcsHistoryHeaderLabel').textContent = `🕒 Versiones de ${relPath}`;
        document.getElementById('vcsWorkspaceChangesBox').style.display = 'none';

        try {
            const res  = await fetch(`?action=vcs_ajax&sub=file_history&path=${encodeURIComponent(relPath)}`);
            const data = await res.json();
            if (!data.ok) {
                setVcsStatus(data.error || 'Error al cargar historial', true);
                return;
            }
            updateBranchLabels(data.current_branch);
            renderCommitsList(data.commits || []);
            setVcsStatus(`Listo (${(data.commits || []).length} versiones registradas)`);
        } catch (err) {
            setVcsStatus('Error de red al consultar VCS', true);
        }
    }

    async function openVcsWorkspaceOverview() {
        vcsFocusedFilePath = '';
        vcsSelectedCommit  = null;
        if (vcsModal) vcsModal.classList.add('open');
        setVcsStatus('Escaneando cambios en el directorio...');

        document.getElementById('vcsLeftTopTitle').textContent      = '📌 Commit Global del Proyecto';
        document.getElementById('vcsHistoryHeaderLabel').textContent = '🕒 Últimos Commits del Proyecto';
        document.getElementById('vcsWorkspaceChangesBox').style.display = 'flex';

        try {
            const res  = await fetch('?action=vcs_ajax&sub=workspace_overview');
            const data = await res.json();
            if (!data.ok) {
                setVcsStatus(data.error || 'Error al consultar estado', true);
                return;
            }
            updateBranchLabels(data.current_branch);
            renderWorkspaceChanges(data.changes || []);
            renderCommitsList(data.recent_commits || []);
            setVcsStatus(`${(data.changes || []).length} archivo(s) con cambios pendientes`);
        } catch (err) {
            setVcsStatus('Error al cargar resumen VCS', true);
        }
    }

    function updateBranchLabels(branchName) {
        const b1 = document.getElementById('headerBranchName');
        const b2 = document.getElementById('vcsActiveBranchLabel');
        if (b1) b1.textContent = branchName;
        if (b2) b2.textContent = branchName;
    }

    function renderWorkspaceChanges(changes) {
        if (!vcsChangesList) return;
        vcsChangesList.innerHTML = '';
        if (changes.length === 0) {
            vcsChangesList.innerHTML = '<div style="color:var(--text-muted);">✓ Working tree limpio: todos los archivos están al día con el último commit.</div>';
            return;
        }
        changes.forEach(ch => {
            const row = document.createElement('div');
            row.style.cssText = 'display:flex; align-items:center; justify-content:space-between; gap:8px; padding:4px 6px; border-radius:6px; background:var(--surface); border:1px solid var(--border);';
            const badgeClass = ch.badge === 'M' ? 'vcs-status-badge-M' : 'vcs-status-badge-U';
            row.innerHTML = `
                <div style="display:flex; align-items:center; gap:6px; min-width:0; overflow:hidden;">
                    <span class="${badgeClass}">${escapeVcsHtml(ch.badge)}</span>
                    <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${escapeVcsHtml(ch.path)}</span>
                </div>
                <button type="button" class="btn" data-inspect-file="${escapeVcsHtml(ch.path)}" style="min-height:26px; padding:2px 8px; font-size:11px;">Historial</button>
            `;
            vcsChangesList.appendChild(row);
        });

        vcsChangesList.querySelectorAll('[data-inspect-file]').forEach(btn => {
            btn.addEventListener('click', () => {
                openVcsForFile(btn.getAttribute('data-inspect-file'));
            });
        });
    }

    function renderCommitsList(commits) {
        if (!vcsCommitList) return;
        vcsCommitList.innerHTML = '';
        const badgeCnt = document.getElementById('fileCommitCountBadge');
        if (badgeCnt && vcsFocusedFilePath) {
            badgeCnt.textContent = String(commits.length);
        }

        if (commits.length === 0) {
            vcsCommitList.innerHTML = '<li style="color:var(--text-muted); font-size:12.5px; padding:8px;">Aún no hay commits registrados. Escribe un mensaje arriba y pulsa "Confirmar Commit".</li>';
            return;
        }

        commits.forEach(c => {
            const li = document.createElement('li');
            li.className = 'vcs-commit-item';
            li.innerHTML = `
                <div style="display:flex; align-items:center; justify-content:space-between; gap:6px; margin-bottom:4px;">
                    <span class="vcs-hash-badge">#${escapeVcsHtml(c.hash)}</span>
                    <span class="vcs-branch-pill">${escapeVcsHtml(c.branch || 'main')}</span>
                    <span style="font-size:11px; color:var(--text-muted);">${escapeVcsHtml(c.timestamp)}</span>
                </div>
                <div style="font-size:13px; font-weight:650; margin-bottom:2px;">${escapeVcsHtml(c.message)}</div>
                <div style="font-size:11px; color:var(--text-muted); display:flex; justify-content:space-between;">
                    <span>📄 ${escapeVcsHtml(c.path)}</span>
                    <span>${c.lines || 1} lín • ${c.size || 0} B</span>
                </div>
            `;
            li.addEventListener('click', () => {
                vcsCommitList.querySelectorAll('.vcs-commit-item').forEach(el => el.classList.remove('active'));
                li.classList.add('active');
                inspectCommitDiff(c.hash);
            });
            vcsCommitList.appendChild(li);
        });
    }

    async function inspectCommitDiff(hash) {
        setVcsStatus(`Cargando snapshot #${hash}...`);
        try {
            const res  = await fetch(`?action=vcs_ajax&sub=get_commit&hash=${encodeURIComponent(hash)}`);
            const data = await res.json();
            if (!data.ok) {
                setVcsStatus(data.error || 'No se pudo cargar el commit', true);
                return;
            }
            vcsSelectedCommit = data;
            const meta = data.meta || {};
            vcsDiffTitle.textContent = `Commit #${data.hash}: ${meta.message || ''}`;
            vcsDiffMeta.textContent  = `Archivo: ${meta.path || ''} • Rama: ${meta.branch || 'main'} • Fecha: ${meta.timestamp || ''}`;

            const editorTextarea = document.getElementById('codeEditorTextarea');
            if (editorTextarea && vcsFocusedFilePath === (meta.path || vcsFocusedFilePath)) {
                vcsDiffViewer.innerHTML = buildVisualDiffHtml(
                    data.content,
                    editorTextarea.value,
                    `Commit #${data.hash} (${meta.timestamp || 'histórico'})`,
                    `Editor Actual (${meta.path || 'en pantalla'})`
                );
                btnLoadIntoEditor.style.display = 'inline-flex';
            } else {
                vcsDiffViewer.textContent = data.content;
                btnLoadIntoEditor.style.display = 'none';
            }
            btnRestoreDisk.style.display = 'inline-flex';
            setVcsStatus(`Mostrando revisión #${data.hash}`);
        } catch (err) {
            setVcsStatus('Error al cargar diff del commit', true);
        }
    }

    // Botón: Confirmar Commit (Individual si estamos en archivo, o Global si estamos en vista de proyecto)
    const btnVcsCommitAction = document.getElementById('btnVcsCommitAction');
    if (btnVcsCommitAction) {
        btnVcsCommitAction.addEventListener('click', async () => {
            const msgInput = document.getElementById('vcsModalCommitMsg');
            const message  = msgInput ? msgInput.value.trim() : '';
            const formData = new FormData();
            formData.append('csrf_token', csrfTokenValue);
            formData.append('message', message || 'Commit desde panel de versiones');

            const editorTextarea = document.getElementById('codeEditorTextarea');

            if (vcsFocusedFilePath !== '') {
                formData.append('path', vcsFocusedFilePath);
                if (editorTextarea) {
                    formData.append('content', editorTextarea.value);
                    formData.append('save_disk', '1');
                }
                setVcsStatus('Guardando commit del archivo...');
                const res  = await fetch('?action=vcs_ajax&sub=commit_file', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.ok) {
                    if (msgInput) msgInput.value = '';
                    renderCommitsList(data.commits || []);
                    setVcsStatus(`✓ Commit #${data.commit.hash} creado con éxito`);
                } else {
                    setVcsStatus(data.error || 'Error al crear commit', true);
                }
            } else {
                setVcsStatus('Haciendo snapshot de los archivos modificados...');
                const res  = await fetch('?action=vcs_ajax&sub=commit_all', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.ok) {
                    if (msgInput) msgInput.value = '';
                    await openVcsWorkspaceOverview();
                    setVcsStatus(`✓ Se versionaron ${data.committed_cnt} archivo(s)`);
                } else {
                    setVcsStatus(data.error || 'Error en commit global', true);
                }
            }
        });
    }

    // Botón: Cargar versión seleccionada en el editor de código sin sobrescribir disco aún
    if (btnLoadIntoEditor) {
        btnLoadIntoEditor.addEventListener('click', () => {
            const editorTextarea = document.getElementById('codeEditorTextarea');
            if (editorTextarea && vcsSelectedCommit) {
                editorTextarea.value = vcsSelectedCommit.content;
                editorTextarea.dispatchEvent(new Event('input'));
                vcsModal.classList.remove('open');
            }
        });
    }

    // Botón: Restaurar (Rollback) archivo en disco a la versión seleccionada
    if (btnRestoreDisk) {
        btnRestoreDisk.addEventListener('click', async () => {
            if (!vcsSelectedCommit) return;
            const formData = new FormData();
            formData.append('csrf_token', csrfTokenValue);
            formData.append('hash', vcsSelectedCommit.hash);

            setVcsStatus(`Restaurando versión #${vcsSelectedCommit.hash}...`);
            const res  = await fetch('?action=vcs_ajax&sub=restore_commit', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.ok) {
                const editorTextarea = document.getElementById('codeEditorTextarea');
                if (editorTextarea && vcsFocusedFilePath === data.path) {
                    editorTextarea.value = data.content;
                    editorTextarea.dispatchEvent(new Event('input'));
                }
                renderCommitsList(data.commits || []);
                setVcsStatus(`✓ Archivo restaurado a #${vcsSelectedCommit.hash} (con backup previo automático)`);
            } else {
                setVcsStatus(data.error || 'No se pudo restaurar la versión', true);
            }
        });
    }

    // Botón: Cambiar o crear nueva rama (Branch)
    const btnBranch = document.getElementById('btnCreateOrSwitchBranch');
    if (btnBranch) {
        btnBranch.addEventListener('click', async () => {
            const branchInput = document.getElementById('vcsNewBranchInput');
            const branchName  = branchInput ? branchInput.value.trim() : '';
            if (!branchName) {
                setVcsStatus('Escribe el nombre de la rama (ej. main, develop, hotfix)', true);
                return;
            }
            const formData = new FormData();
            formData.append('csrf_token', csrfTokenValue);
            formData.append('branch', branchName);

            const res  = await fetch('?action=vcs_ajax&sub=switch_branch', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.ok) {
                if (branchInput) branchInput.value = '';
                updateBranchLabels(data.current_branch);
                setVcsStatus(`✓ Rama activa cambiada a "${data.current_branch}"`);
            } else {
                setVcsStatus(data.error || 'Error al cambiar de rama', true);
            }
        });
    }

    // Eventos de apertura del modal VCS
    if (btnOpenGlobalVcs) {
        btnOpenGlobalVcs.addEventListener('click', openVcsWorkspaceOverview);
    }
    const btnShowOverview = document.getElementById('btnShowWorkspaceOverview');
    if (btnShowOverview) {
        btnShowOverview.addEventListener('click', openVcsWorkspaceOverview);
    }
    if (btnCloseVcsModal) {
        btnCloseVcsModal.addEventListener('click', () => vcsModal.classList.remove('open'));
    }

    const btnOpenFileVcs = document.getElementById('btnOpenFileVcs');
    if (btnOpenFileVcs) {
        btnOpenFileVcs.addEventListener('click', () => {
            openVcsForFile(btnOpenFileVcs.getAttribute('data-vcs-path') || '');
        });
    }

    const btnQuickAjaxCommit = document.getElementById('btnQuickAjaxCommit');
    if (btnQuickAjaxCommit && btnOpenFileVcs) {
        btnQuickAjaxCommit.addEventListener('click', async () => {
            const relPath        = btnOpenFileVcs.getAttribute('data-vcs-path') || '';
            const editorTextarea = document.getElementById('codeEditorTextarea');
            const msgInput       = document.getElementById('inputCommitMessage');
            const formData       = new FormData();
            formData.append('csrf_token', csrfTokenValue);
            formData.append('path', relPath);
            formData.append('content', editorTextarea ? editorTextarea.value : '');
            formData.append('save_disk', '1');
            formData.append('message', (msgInput && msgInput.value.trim()) ? msgInput.value.trim() : 'Snapshot rápido desde editor');

            btnQuickAjaxCommit.textContent = '⏳ Guardando...';
            const res  = await fetch('?action=vcs_ajax&sub=commit_file', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.ok) {
                if (msgInput) msgInput.value = '';
                const badgeCnt = document.getElementById('fileCommitCountBadge');
                if (badgeCnt && data.commits) badgeCnt.textContent = String(data.commits.length);
                btnQuickAjaxCommit.textContent = `✓ Commit #${data.commit.hash}`;
                setTimeout(() => { btnQuickAjaxCommit.textContent = '🌿 Snapshot Rápido'; }, 2200);
            } else {
                btnQuickAjaxCommit.textContent = '🌿 Snapshot Rápido';
            }
        });
    }

    document.querySelectorAll('.btn-row-vcs').forEach(btn => {
        btn.addEventListener('click', () => {
            openVcsForFile(btn.getAttribute('data-vcs-path') || '');
        });
    });


    /* ============================================================
       2. NATIVE CODE EDITOR + VS CODE PATH INTELLISENSE + 7 LANGUAGES
       ============================================================ */
    const textarea = document.getElementById('codeEditorTextarea');
    if (!textarea) return;

    const highlightPre = document.getElementById('codeEditorHighlight');
    const lineNumsEl   = document.getElementById('ideLineNumbers');
    const langSelect   = document.getElementById('ideLangSelect');
    const badgeLang    = document.getElementById('badgeDetectedLang');
    const popup        = document.getElementById('vscPopup');
    const popupTitle   = document.getElementById('vscPopupTitle');
    const popupList    = document.getElementById('vscPopupList');
    const editForm     = document.getElementById('editFileForm');

    const baseFileDir  = textarea.getAttribute('data-file-dir') || '';
    const fileName     = textarea.getAttribute('data-file-name') || '';

    let currentSuggestions = [];
    let selectedIndex      = 0;
    let activeReplaceRange = null; // { start, end, isPath }
    let pathFetchDebounce  = null;

    // Diccionario de Autocompletado / Snippets para los 7 lenguajes:
    // PHP, JS, HTML, CSS, C/C++, Java y Python
    const LANGUAGE_COMPLETIONS = {
        php: [
            { label: '\x3C?php declare(strict_types=1);', insert: "\x3C?php\ndeclare(strict_types=1);\n", kind: 'PHP' },
            { label: 'require_once', insert: "require_once __DIR__ . '/", kind: 'Path' },
            { label: 'include_once', insert: "include_once __DIR__ . '/", kind: 'Path' },
            { label: 'function', insert: "function nombre(string $param): void\n{\n    \n}", kind: 'Fn' },
            { label: 'foreach', insert: "foreach ($items as $key => $item) {\n    \n}", kind: 'Loop' },
            { label: 'PDO::prepare', insert: "$stmt = $pdo->prepare('SELECT * FROM tabla WHERE id = :id');\n$stmt->execute(['id' => $id]);", kind: 'PDO' },
            { label: 'json_encode', insert: "json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)", kind: 'JSON' },
            { label: 'htmlspecialchars', insert: "htmlspecialchars($str, ENT_QUOTES, 'UTF-8')", kind: 'Sec' }
        ],
        javascript: [
            { label: 'import ... from', insert: "import module from './", kind: 'Path' },
            { label: 'fetch (AJAX)', insert: "const res = await fetch('./", kind: 'AJAX' },
            { label: 'async function', insert: "async function nombre() {\n    \n}", kind: 'Fn' },
            { label: 'document.querySelector', insert: "document.querySelector('')", kind: 'DOM' },
            { label: 'addEventListener', insert: "addEventListener('click', (e) => {\n    \n});", kind: 'Event' },
            { label: 'console.log', insert: "console.log();", kind: 'Log' }
        ],
        html: [
            { label: '<script src="...">', insert: '<script src="./', kind: 'Path' },
            { label: '<link href="...">', insert: '<link rel="stylesheet" href="./', kind: 'Path' },
            { label: '<img src="...">', insert: '<img src="./', kind: 'Path' },
            { label: '<a href="...">', insert: '<a href="./', kind: 'Path' },
            { label: '<!DOCTYPE html>', insert: "<!DOCTYPE html>\n<html lang=\"es\">\n<head>\n    <meta charset=\"UTF-8\">\n    <title>Documento</title>\n</head>\n<body>\n    \n</body>\n</html>", kind: 'HTML5' }
        ],
        css: [
            { label: '@import url(...)', insert: "@import url('./", kind: 'Path' },
            { label: 'background-image: url(...)', insert: "background-image: url('./", kind: 'Path' },
            { label: 'display: flex', insert: "display: flex;\nalign-items: center;\njustify-content: space-between;", kind: 'Flex' },
            { label: 'display: grid', insert: "display: grid;\ngrid-template-columns: repeat(auto-fit, minmax(240px, 1fr));\ngap: 16px;", kind: 'Grid' },
            { label: '@media (min-width: 768px)', insert: "@media (min-width: 768px) {\n    \n}", kind: 'Media' }
        ],
        cpp: [
            { label: '#include "..." (Archivo local)', insert: '#include "./', kind: 'Path' },
            { label: '#include <iostream>', insert: "#include <iostream>\n#include <vector>\n#include <string>\n\nint main(int argc, char* argv[]) {\n    std::cout << \"Hola C++\" << std::endl;\n    return 0;\n}", kind: 'C++' },
            { label: '#include <stdio.h>', insert: "#include <stdio.h>\n#include <stdlib.h>\n\nint main(void) {\n    printf(\"Hola C\\n\");\n    return 0;\n}", kind: 'C' },
            { label: 'std::vector<T>', insert: "std::vector<int> items;", kind: 'STL' },
            { label: 'class Nombre', insert: "class Nombre {\npublic:\n    Nombre() {}\n    ~Nombre() {}\nprivate:\n};", kind: 'Class' },
            { label: 'for (size_t i = 0; ...)', insert: "for (size_t i = 0; i < count; ++i) {\n    \n}", kind: 'Loop' }
        ],
        java: [
            { label: 'public class Main', insert: "public class Main {\n    public static void main(String[] args) {\n        System.out.println(\"Hola Java\");\n    }\n}", kind: 'Class' },
            { label: 'System.out.println', insert: 'System.out.println("");', kind: 'IO' },
            { label: 'List<String> ArrayList', insert: "List<String> list = new ArrayList<>();", kind: 'Util' },
            { label: 'try / catch (Exception e)', insert: "try {\n    \n} catch (Exception e) {\n    e.printStackTrace();\n}", kind: 'Err' },
            { label: '@Override public', insert: "@Override\npublic String toString() {\n    return super.toString();\n}", kind: 'Anno' }
        ],
        python: [
            { label: 'with open("./...")', insert: 'with open("./', kind: 'Path' },
            { label: 'def funcion():', insert: "def nombre_funcion(param: str) -> None:\n    pass", kind: 'Def' },
            { label: 'class Nombre:', insert: "class NombreClase:\n    def __init__(self) -> None:\n        pass", kind: 'Class' },
            { label: 'if __name__ == "__main__":', insert: "if __name__ == \"__main__\":\n    main()", kind: 'Main' },
            { label: 'try / except Exception as e:', insert: "try:\n    pass\nexcept Exception as e:\n    print(f\"Error: {e}\")", kind: 'Err' },
            { label: 'import os, sys, pathlib', insert: "import os\nimport sys\nfrom pathlib import Path", kind: 'Import' }
        ]
    };

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    // Detección inteligente de lenguaje por extensión y por contenido del código
    function resolveActiveLanguage(code) {
        const chosen = langSelect ? langSelect.value : 'auto';
        if (chosen !== 'auto') return chosen;

        const ext = (fileName.split('.').pop() || '').toLowerCase();
        if (['php', 'phtml'].includes(ext)) return 'php';
        if (['js', 'mjs', 'cjs', 'ts', 'jsx', 'tsx', 'json'].includes(ext)) return 'javascript';
        if (['html', 'htm', 'xhtml', 'xml', 'svg'].includes(ext)) return 'html';
        if (['css', 'scss', 'less'].includes(ext)) return 'css';
        if (['c', 'cpp', 'cc', 'cxx', 'h', 'hpp'].includes(ext)) return 'cpp';
        if (ext === 'java') return 'java';
        if (['py', 'pyw'].includes(ext)) return 'python';

        // Heurística por contenido si el archivo no tiene extensión conocida
        if (code.includes('\x3C?php') || /\$[a-zA-Z_]/.test(code)) return 'php';
        if (/^\s*#include\s+[<"]/m.test(code) || /\bstd::/.test(code)) return 'cpp';
        if (/\bpublic\s+class\s+\w+/.test(code) || /\bSystem\.out\.print/.test(code)) return 'java';
        if (/^\s*def\s+\w+\s*\(/m.test(code) || /^\s*import\s+\w+/m.test(code) && code.includes(':\n')) return 'python';
        if (/<(!DOCTYPE|html|div|section|script|body)\b/i.test(code)) return 'html';
        if (/\{\s*[a-z\-]+\s*:\s*[^;]+;/i.test(code)) return 'css';
        return 'javascript';
    }

    // Motor de Resaltado de Sintaxis para PHP, JS, HTML, CSS, C/C++, Java y Python
    function highlightCode(code, lang) {
        let escaped = escapeHtml(code);
        const tokens = [];
        const saveToken = (htmlStr) => {
            const key = `___IDE_TOK_${tokens.length}___`;
            tokens.push(htmlStr);
            return key;
        };

        // 1. Comentarios (C/C++/Java/JS/PHP /* */ y //, Python/PHP #, HTML <!-- -->)
        escaped = escaped.replace(/(\/\*[\s\S]*?\*\/|\/\/[^\n]*|#[^\n]*|&lt;!--[\s\S]*?--&gt;)/g, (m) => {
            // En C/C++ #include / #define / #pragma es preprocesador, no comentario
            if (lang === 'cpp' && /^#\s*(include|define|ifdef|ifndef|endif|pragma|if|else|elif)\b/.test(m)) {
                return saveToken(`<span class="tok-pre">${m}</span>`);
            }
            return saveToken(`<span class="tok-com">${m}</span>`);
        });

        // 2. Strings (Triples comillas de Python, comillas dobles, simples y template literals)
        escaped = escaped.replace(/("""[\s\S]*?"""|'''[\s\S]*?'''|"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|`(?:\\.|[^`\\])*`)/g, (m) =>
            saveToken(`<span class="tok-str">${m}</span>`)
        );

        // 3. Etiquetas PHP
        escaped = escaped.replace(/(&lt;\?php|&lt;\?=|\?&gt;)/g, (m) =>
            saveToken(`<span class="tok-tag">${m}</span>`)
        );

        // 4. Reglas específicas para HTML y CSS
        if (lang === 'html') {
            escaped = escaped.replace(/(&lt;\/?[a-zA-Z0-9\-]+)(\s[^&]*?)?(\/?&gt;)/g, (full, openTag, attrs, closeTag) => {
                let coloredAttrs = attrs || '';
                coloredAttrs = coloredAttrs.replace(/([a-zA-Z\-:]+)=/g, '<span class="tok-attr">$1</span>=');
                return saveToken(`<span class="tok-tag">${openTag}</span>${coloredAttrs}<span class="tok-tag">${closeTag}</span>`);
            });
        } else if (lang === 'css') {
            escaped = escaped.replace(/([a-zA-Z\-]+)(\s*:)/g, '<span class="tok-attr">$1</span>$2');
        }

        // 5. Anotaciones Java / Decoradores Python (@Override, @Autowired, @staticmethod)
        if (lang === 'java' || lang === 'python') {
            escaped = escaped.replace(/(@[a-zA-Z_][a-zA-Z0-9_]*)/g, (m) =>
                saveToken(`<span class="tok-pre">${m}</span>`)
            );
        }

        // 6. Variables PHP ($variable)
        escaped = escaped.replace(/(\$[a-zA-Z_][a-zA-Z0-9_]*)/g, '<span class="tok-var">$1</span>');

        // 7. Tipos de datos (C/C++, Java, PHP, Python typing)
        const typesRegex = /\b(int|long|short|char|void|float|double|bool|boolean|byte|signed|unsigned|size_t|uint8_t|uint16_t|uint32_t|uint64_t|int32_t|int64_t|string|String|Object|Integer|Double|Boolean|List|ArrayList|Map|HashMap|Set|Optional|vector|map|set|shared_ptr|unique_ptr|array|callable|iterable|mixed|never)\b/g;
        escaped = escaped.replace(typesRegex, '<span class="tok-type">$1</span>');

        // 8. Palabras clave multi-lenguaje (PHP, JS, C/C++, Java, Python)
        const kwRegex = /\b(function|def|return|if|else|elseif|elif|foreach|for|while|do|switch|case|break|continue|default|try|catch|except|finally|throw|throws|raise|class|struct|enum|union|interface|extends|implements|package|import|from|as|pass|lambda|with|yield|global|nonlocal|public|private|protected|static|final|abstract|synchronized|volatile|const|constexpr|let|var|auto|register|extern|inline|virtual|override|friend|template|typename|namespace|using|sizeof|typedef|new|delete|this|self|super|echo|print|require|require_once|include|include_once|declare|strict_types|match|fn|async|await|export|true|false|null|None|True|False|nullptr|std|cout|cin|endl|System|out|println|printf)\b/g;
        escaped = escaped.replace(kwRegex, '<span class="tok-kw">$1</span>');

        // 9. Números (enteros, decimales y hexadecimales)
        escaped = escaped.replace(/\b(0x[0x0-9a-fA-F]+|\d+(?:\.\d+)?)\b/g, '<span class="tok-num">$1</span>');

        // Restaurar tokens protegidos
        tokens.forEach((tokHtml, i) => {
            escaped = escaped.replace(`___IDE_TOK_${i}___`, tokHtml);
        });

        if (escaped.endsWith('\n')) {
            escaped += ' ';
        }
        return escaped;
    }

    // Actualizar vista del editor, numeración de líneas y barra de estado
    function refreshEditorUI() {
        const code = textarea.value;
        const lang = resolveActiveLanguage(code);

        if (badgeLang) {
            badgeLang.textContent = 'LENGUAJE: ' + lang.toUpperCase();
        }

        highlightPre.innerHTML = highlightCode(code, lang);

        const pos        = textarea.selectionStart || 0;
        const upToCursor = code.slice(0, pos).split('\n');
        const activeLine = upToCursor.length;
        const activeCol  = upToCursor[upToCursor.length - 1].length + 1;

        const allLines   = code.split('\n');
        const totalLines = allLines.length;

        let gutterHtml = '';
        for (let i = 1; i <= totalLines; i++) {
            if (i === activeLine) {
                gutterHtml += `<span class="active-ln">${i}</span><br>`;
            } else {
                gutterHtml += `${i}<br>`;
            }
        }
        lineNumsEl.innerHTML = gutterHtml;

        document.getElementById('statusLineCol').textContent    = `Ln ${activeLine}, Col ${activeCol}`;
        document.getElementById('statusLinesTotal').textContent = `${totalLines} línea${totalLines === 1 ? '' : 's'}`;
        document.getElementById('statusCharCount').textContent  = `${code.length} caracteres`;
    }

    function syncScrollbars() {
        highlightPre.scrollTop  = textarea.scrollTop;
        highlightPre.scrollLeft = textarea.scrollLeft;
        lineNumsEl.scrollTop    = textarea.scrollTop;
    }

    textarea.addEventListener('scroll', syncScrollbars);
    textarea.addEventListener('click', refreshEditorUI);
    textarea.addEventListener('keyup', (e) => {
        if (!['ArrowDown', 'ArrowUp', 'Enter', 'Tab', 'Escape'].includes(e.key)) {
            refreshEditorUI();
        }
    });
    if (langSelect) {
        langSelect.addEventListener('change', refreshEditorUI);
    }

    /* ============================================================
       3. VS CODE PATH INTELLISENSE DETECTOR & AJAX FETCHER
       ============================================================ */
    function detectPathContextBeforeCursor() {
        const pos  = textarea.selectionStart || 0;
        const left = textarea.value.slice(0, pos);
        const line = left.split('\n').pop() || '';

        // 1. Ruta explícita relativa o absoluta: ./algo, ../algo, o dentro de comillas/paréntesis
        // Detecta: "./...", "../...", "/...", src="...", href="...", include '...', import '... from "..."', #include "..."
        const quotePathMatch = line.match(/(?:src|href|url|include|include_once|require|require_once|from|open|fetch|#include)\s*(?:\(|=|\s)\s*['"]([^'"]*)$/i);
        if (quotePathMatch) {
            const rawPath = quotePathMatch[1];
            return {
                query: rawPath,
                start: pos - rawPath.length,
                end: pos
            };
        }

        // 2. Cualquier token que empiece con ./ o ../ o / dentro o fuera de comillas
        const slashMatch = line.match(/(?:^|[\s('"=`])(\.\.?\/[^\s'"`)]*|\/[^\s'"`/*)]*)$/);
        if (slashMatch) {
            const rawPath = slashMatch[1];
            return {
                query: rawPath,
                start: pos - rawPath.length,
                end: pos
            };
        }

        return null;
    }

    function positionPopupNearCursor() {
        const pos   = textarea.selectionStart || 0;
        const lines = textarea.value.slice(0, pos).split('\n');
        const row   = lines.length;
        const col   = lines[lines.length - 1].length;

        const topPx  = Math.min(220, Math.max(24, row * 21 - textarea.scrollTop + 12));
        const leftPx = Math.min(280, Math.max(16, col * 7.8 - textarea.scrollLeft + 12));
        popup.style.top  = `${topPx}px`;
        popup.style.left = `${leftPx}px`;
    }

    function closePopup() {
        popup.classList.remove('open');
        currentSuggestions = [];
        activeReplaceRange = null;
    }

    function renderPopupList() {
        popupList.innerHTML = '';
        currentSuggestions.forEach((item, idx) => {
            const li = document.createElement('li');
            li.className = 'vsc-popup-item' + (idx === selectedIndex ? ' active' : '');
            li.innerHTML = `
                <span class="vsc-item-left">
                    <span>${item.icon || '📄'}</span>
                    <span>${escapeHtml(item.label)}</span>
                </span>
                <span class="vsc-item-kind">${escapeHtml(item.kind || '')}</span>
            `;
            li.addEventListener('mousedown', (e) => {
                e.preventDefault();
                applySuggestion(idx);
            });
            popupList.appendChild(li);
        });
    }

    function applySuggestion(idx) {
        const item = currentSuggestions[idx];
        if (!item || !activeReplaceRange) return;

        const val    = textarea.value;
        const before = val.slice(0, activeReplaceRange.start);
        const after  = val.slice(activeReplaceRange.end);

        textarea.value = before + item.insert + after;
        const newPos   = activeReplaceRange.start + item.insert.length;
        textarea.focus();
        textarea.setSelectionRange(newPos, newPos);
        refreshEditorUI();

        // Si el usuario seleccionó una carpeta (termina en /), encadenar inmediatamente el autocompletado del subdirectorio igual que VS Code Path Intellisense
        if (item.isDir) {
            triggerPathIntellisense(false);
        } else {
            closePopup();
        }
    }

    async function triggerPathIntellisense(forceRootIfEmpty = false) {
        let ctx = detectPathContextBeforeCursor();
        const pos = textarea.selectionStart || 0;

        if (!ctx && forceRootIfEmpty) {
            ctx = { query: './', start: pos, end: pos };
            textarea.value = textarea.value.slice(0, pos) + './' + textarea.value.slice(pos);
            textarea.setSelectionRange(pos + 2, pos + 2);
            ctx = detectPathContextBeforeCursor();
            refreshEditorUI();
        }

        if (!ctx) return false;

        try {
            const url = `?action=path_intellisense&base_dir=${encodeURIComponent(baseFileDir)}&query=${encodeURIComponent(ctx.query)}`;
            const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
            if (!res.ok) return false;
            const data = await res.json();
            if (!data || !Array.isArray(data.items) || data.items.length === 0) {
                closePopup();
                return false;
            }

            // Calcular el prefijo de carpeta que se conserva al insertar el nombre del archivo/carpeta
            const lastSlashIdx = ctx.query.lastIndexOf('/');
            const folderPrefix = lastSlashIdx >= 0 ? ctx.query.slice(0, lastSlashIdx + 1) : '';

            currentSuggestions = data.items.map(entry => ({
                label: entry.name + (entry.isDir ? '/' : ''),
                insert: folderPrefix + entry.name + (entry.isDir ? '/' : ''),
                icon: entry.icon,
                kind: entry.isDir ? 'Carpeta' : (entry.size || entry.ext),
                isDir: Boolean(entry.isDir)
            }));

            selectedIndex      = 0;
            activeReplaceRange = { start: ctx.start, end: ctx.end, isPath: true };
            popupTitle.textContent = `📂 VS Code Path Intellisense (/${data.resolved || ''})`;
            positionPopupNearCursor();
            renderPopupList();
            popup.classList.add('open');
            return true;
        } catch (_) {
            return false;
        }
    }

    function triggerLanguageIntellisense(forceShowAll = false) {
        const pos  = textarea.selectionStart || 0;
        const left = textarea.value.slice(0, pos);
        const wordMatch = left.match(/([#@<$a-zA-Z_][a-zA-Z0-9_:]*)$/);
        const prefix    = wordMatch ? wordMatch[1] : '';

        if (!forceShowAll && prefix.length < 2) {
            closePopup();
            return;
        }

        const lang = resolveActiveLanguage(textarea.value);
        const pool = LANGUAGE_COMPLETIONS[lang] || LANGUAGE_COMPLETIONS.javascript;
        const filtered = pool.filter(item =>
            forceShowAll ||
            item.label.toLowerCase().includes(prefix.toLowerCase()) ||
            item.insert.toLowerCase().startsWith(prefix.toLowerCase())
        );

        if (filtered.length === 0) {
            closePopup();
            return;
        }

        currentSuggestions = filtered.map(item => ({
            label: item.label,
            insert: item.insert,
            icon: '✨',
            kind: item.kind,
            isDir: item.insert.endsWith('/')
        }));
        selectedIndex = 0;
        activeReplaceRange = {
            start: pos - prefix.length,
            end: pos,
            isPath: false
        };
        popupTitle.textContent = `✨ IntelliSense (${lang.toUpperCase()})`;
        positionPopupNearCursor();
        renderPopupList();
        popup.classList.add('open');
    }

    textarea.addEventListener('input', () => {
        refreshEditorUI();
        if (pathFetchDebounce) clearTimeout(pathFetchDebounce);

        const pathCtx = detectPathContextBeforeCursor();
        if (pathCtx) {
            pathFetchDebounce = setTimeout(() => {
                triggerPathIntellisense(false);
            }, 60);
        } else {
            triggerLanguageIntellisense(false);
        }
    });

    textarea.addEventListener('keydown', (e) => {
        const isPopupOpen = popup.classList.contains('open');

        // Atajo Ctrl+S / Cmd+S para guardar directamente
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
            e.preventDefault();
            if (editForm) editForm.submit();
            return;
        }

        // Atajo Ctrl+Espacio para abrir IntelliSense o Path Intellisense
        if (e.ctrlKey && e.code === 'Space') {
            e.preventDefault();
            const pathCtx = detectPathContextBeforeCursor();
            if (pathCtx) {
                triggerPathIntellisense(false);
            } else {
                triggerLanguageIntellisense(true);
            }
            return;
        }

        if (isPopupOpen) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                selectedIndex = (selectedIndex + 1) % currentSuggestions.length;
                renderPopupList();
                return;
            }
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                selectedIndex = (selectedIndex - 1 + currentSuggestions.length) % currentSuggestions.length;
                renderPopupList();
                return;
            }
            if (e.key === 'Tab' || e.key === 'Enter') {
                e.preventDefault();
                applySuggestion(selectedIndex);
                return;
            }
            if (e.key === 'Escape') {
                e.preventDefault();
                closePopup();
                return;
            }
        }

        // Sangría con Tab (4 espacios)
        if (e.key === 'Tab') {
            e.preventDefault();
            const start = textarea.selectionStart;
            const end   = textarea.selectionEnd;
            textarea.value = textarea.value.substring(0, start) + '    ' + textarea.value.substring(end);
            textarea.selectionStart = textarea.selectionEnd = start + 4;
            refreshEditorUI();
        }
    });

    const btnPath = document.getElementById('btnTriggerPathIntellisense');
    if (btnPath) {
        btnPath.addEventListener('click', () => {
            textarea.focus();
            triggerPathIntellisense(true);
        });
    }

    const btnCode = document.getElementById('btnTriggerCodeIntellisense');
    if (btnCode) {
        btnCode.addEventListener('click', () => {
            textarea.focus();
            triggerLanguageIntellisense(true);
        });
    }

    document.addEventListener('click', (e) => {
        if (!popup.contains(e.target) && e.target !== textarea && e.target !== btnPath && e.target !== btnCode) {
            closePopup();
        }
    });

    // Inicializar numeración y resaltado al cargar
    refreshEditorUI();
})();
</script>
</body>
</html>