<?php

declare(strict_types=1);

/**
 * Coqui Web Server — Router Script
 *
 * Runs inside PHP's built-in server (`php -S`) as the router script.
 * Every request passes through this file. Configuration is via environment variables
 * set by WebServerRunner when spawning the server process.
 *
 * Environment variables:
 *   COQUI_WS_GZIP           - "1" to enable gzip compression
 *   COQUI_WS_DIR_LISTING    - "1" to enable directory listings
 *   COQUI_WS_PHP_ENABLED    - "1" to allow PHP file execution
 *   COQUI_WS_LOG_DB         - Path to SQLite database for request logging
 *   COQUI_WS_REWRITES_FILE  - Path to JSON file with rewrite rules
 *   COQUI_WS_DOCROOT        - Absolute path to the document root
 *
 * This script runs in an isolated process — it has no access to Coqui's
 * internals, only to the workspace files being served.
 */

// ── Configuration ───────────────────────────────────────────────────────

$config = [
    'gzip' => getenv('COQUI_WS_GZIP') === '1',
    'dir_listing' => getenv('COQUI_WS_DIR_LISTING') === '1',
    'php_enabled' => getenv('COQUI_WS_PHP_ENABLED') === '1',
    'log_db' => getenv('COQUI_WS_LOG_DB') ?: '',
    'rewrites_file' => getenv('COQUI_WS_REWRITES_FILE') ?: '',
    'docroot' => getenv('COQUI_WS_DOCROOT') ?: $_SERVER['DOCUMENT_ROOT'] ?? __DIR__,
    'max_file_size' => 50 * 1024 * 1024, // 50MB
];

$docroot = rtrim($config['docroot'], '/');
$realDocroot = realpath($docroot);

if ($realDocroot === false) {
    http_response_code(500);
    echo 'Document root not found.';
    exit(1);
}

// ── Request Parsing ─────────────────────────────────────────────────────

$requestStart = hrtime(true);
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$parsedUrl = parse_url($uri);
$requestPath = $parsedUrl['path'] ?? '/';
$queryString = $parsedUrl['query'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ── Security Headers ────────────────────────────────────────────────────

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Server: Coqui-WebServer');

// ── URL Rewrites ────────────────────────────────────────────────────────

$rewrites = [];
if ($config['rewrites_file'] !== '' && is_file($config['rewrites_file'])) {
    $rewriteData = json_decode(file_get_contents($config['rewrites_file']) ?: '{}', true);
    if (is_array($rewriteData)) {
        $rewrites = $rewriteData;
    }
}

$originalPath = $requestPath;
foreach ($rewrites as $pattern => $replacement) {
    $regex = '#' . str_replace('#', '\\#', $pattern) . '#';
    $rewritten = @preg_replace($regex, $replacement, $requestPath);
    if ($rewritten !== null && $rewritten !== $requestPath) {
        $requestPath = $rewritten;
        break; // Apply only the first matching rule
    }
}

// ── Path Resolution & Security ──────────────────────────────────────────

// Decode URL-encoded characters
$decodedPath = urldecode($requestPath);

// Normalize path — remove double slashes, resolve . and ..
$segments = explode('/', $decodedPath);
$normalized = [];
foreach ($segments as $segment) {
    if ($segment === '' || $segment === '.') {
        continue;
    }
    if ($segment === '..') {
        array_pop($normalized);
        continue;
    }
    $normalized[] = $segment;
}
$cleanPath = '/' . implode('/', $normalized);

// Resolve to filesystem path
$filePath = $realDocroot . $cleanPath;
$realFilePath = realpath($filePath);

// ── Sensitive File Blocking ─────────────────────────────────────────────

/** @var string[] */
$blockedPatterns = [
    '/^\.env/',                     // .env files
    '/^\.git(\/|$)/',               // .git directory
    '/^\.workspace(\/|$)/',         // .workspace directory
    '/^vendor(\/|$)/',              // vendor directory
    '/^node_modules(\/|$)/',        // node_modules
    '/^composer\.(json|lock)$/',    // Composer manifests
    '/^package(-lock)?\.json$/',    // NPM manifests
    '/\.db$/',                      // SQLite databases
    '/\.sqlite$/',                  // SQLite databases
    '/\.log$/',                     // Log files
    '/\.pid$/',                     // PID files
    '/\.pem$/',                     // Certificates
    '/\.key$/',                     // Private keys
];

$relativePath = ltrim($cleanPath, '/');
foreach ($blockedPatterns as $pattern) {
    if (preg_match($pattern, $relativePath)) {
        logRequest($config, $method, $originalPath, $queryString, 403, 0, $requestStart);
        http_response_code(403);
        echo '403 Forbidden';
        return true;
    }
}

// ── Path Traversal Protection ───────────────────────────────────────────

if ($realFilePath !== false && !str_starts_with($realFilePath, $realDocroot)) {
    logRequest($config, $method, $originalPath, $queryString, 403, 0, $requestStart);
    http_response_code(403);
    echo '403 Forbidden';
    return true;
}

// ── Directory Handling ──────────────────────────────────────────────────

if ($realFilePath !== false && is_dir($realFilePath)) {
    // Check for index files
    $indexFiles = ['index.html', 'index.htm'];
    if ($config['php_enabled']) {
        array_unshift($indexFiles, 'index.php');
    }

    foreach ($indexFiles as $indexFile) {
        $indexPath = $realFilePath . '/' . $indexFile;
        if (is_file($indexPath)) {
            $filePath = $indexPath;
            $realFilePath = realpath($indexPath);
            break;
        }
    }

    // If still a directory (no index found)
    if (is_dir($realFilePath ?: $filePath)) {
        if ($config['dir_listing']) {
            $html = generateDirectoryListing($realFilePath ?: $filePath, $cleanPath, $realDocroot);
            $size = strlen($html);
            logRequest($config, $method, $originalPath, $queryString, 200, $size, $requestStart);

            if ($config['gzip'] && acceptsGzip()) {
                ob_start('ob_gzhandler');
            }

            header('Content-Type: text/html; charset=utf-8');
            echo $html;

            if ($config['gzip'] && acceptsGzip()) {
                ob_end_flush();
            }

            return true;
        }

        logRequest($config, $method, $originalPath, $queryString, 404, 0, $requestStart);
        http_response_code(404);
        echo '404 Not Found';
        return true;
    }
}

// ── File Not Found ──────────────────────────────────────────────────────

if ($realFilePath === false || !is_file($realFilePath)) {
    logRequest($config, $method, $originalPath, $queryString, 404, 0, $requestStart);
    http_response_code(404);
    echo '404 Not Found';
    return true;
}

// ── PHP File Handling ───────────────────────────────────────────────────

$extension = strtolower(pathinfo($realFilePath, PATHINFO_EXTENSION));

if ($extension === 'php') {
    if ($config['php_enabled']) {
        // Let PHP's built-in server handle the .php file natively
        logRequest($config, $method, $originalPath, $queryString, 200, 0, $requestStart);
        return false;
    }

    // PHP disabled — return 403
    logRequest($config, $method, $originalPath, $queryString, 403, 0, $requestStart);
    http_response_code(403);
    echo '403 Forbidden — PHP execution is disabled';
    return true;
}

// ── File Size Check ─────────────────────────────────────────────────────

$fileSize = filesize($realFilePath);
if ($fileSize === false || $fileSize > $config['max_file_size']) {
    logRequest($config, $method, $originalPath, $queryString, 413, 0, $requestStart);
    http_response_code(413);
    echo '413 Content Too Large';
    return true;
}

// ── MIME Type Detection ─────────────────────────────────────────────────

$mimeType = getMimeType($extension);

// ── Gzip Compression ────────────────────────────────────────────────────

$useGzip = $config['gzip'] && acceptsGzip() && isCompressible($mimeType) && $fileSize > 1024;

if ($useGzip) {
    ob_start('ob_gzhandler');
}

// ── Serve the File ──────────────────────────────────────────────────────

header("Content-Type: {$mimeType}");

// Cache headers for static assets
if (isStaticAsset($extension)) {
    header('Cache-Control: public, max-age=3600');
} else {
    header('Cache-Control: no-cache');
}

readfile($realFilePath);

logRequest($config, $method, $originalPath, $queryString, 200, $fileSize, $requestStart);

if ($useGzip) {
    ob_end_flush();
}

return true;

// ═══════════════════════════════════════════════════════════════════════
// Helper Functions
// ═══════════════════════════════════════════════════════════════════════

/**
 * Log a request to the SQLite database.
 *
 * @param array<string, mixed> $config
 */
function logRequest(
    array $config,
    string $method,
    string $path,
    string $query,
    int $status,
    int $size,
    int $startTimeNs,
): void {
    $logDb = $config['log_db'] ?? '';
    if ($logDb === '' || !is_string($logDb)) {
        return;
    }

    $durationMs = (hrtime(true) - $startTimeNs) / 1_000_000;

    try {
        $db = new PDO("sqlite:{$logDb}");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode=WAL');

        // Create table if needed (first request)
        $db->exec(
            'CREATE TABLE IF NOT EXISTS request_log (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp   TEXT    NOT NULL,
                method      TEXT    NOT NULL,
                path        TEXT    NOT NULL,
                query       TEXT    DEFAULT "",
                status      INTEGER NOT NULL,
                size        INTEGER DEFAULT 0,
                duration_ms REAL    DEFAULT 0,
                user_agent  TEXT    DEFAULT "",
                remote_addr TEXT    DEFAULT ""
            )',
        );

        $stmt = $db->prepare(
            'INSERT INTO request_log (timestamp, method, path, query, status, size, duration_ms, user_agent, remote_addr)
             VALUES (:ts, :method, :path, :query, :status, :size, :dur, :ua, :ra)',
        );

        $stmt->execute([
            ':ts' => date('c'),
            ':method' => $method,
            ':path' => $path,
            ':query' => $query,
            ':status' => $status,
            ':size' => $size,
            ':dur' => round($durationMs, 2),
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ':ra' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
    } catch (PDOException) {
        // Silently fail — logging should not break serving
    }
}

/**
 * Check if the client accepts gzip encoding.
 */
function acceptsGzip(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
    return str_contains($accept, 'gzip');
}

/**
 * Determine if a MIME type is eligible for compression.
 */
function isCompressible(string $mimeType): bool
{
    $compressible = [
        'text/',
        'application/json',
        'application/javascript',
        'application/xml',
        'application/xhtml+xml',
        'application/rss+xml',
        'application/atom+xml',
        'image/svg+xml',
        'application/wasm',
    ];

    foreach ($compressible as $prefix) {
        if (str_starts_with($mimeType, $prefix) || $mimeType === $prefix) {
            return true;
        }
    }

    return false;
}

/**
 * Check if an extension represents a static asset (for caching).
 */
function isStaticAsset(string $ext): bool
{
    return in_array($ext, [
        'css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico',
        'woff', 'woff2', 'ttf', 'eot', 'otf',
        'webp', 'avif',
        'mp4', 'webm', 'ogg', 'mp3', 'wav',
        'wasm',
    ], true);
}

/**
 * Get MIME type from file extension.
 */
function getMimeType(string $ext): string
{
    return match ($ext) {
        // Text
        'html', 'htm' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js', 'mjs' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'xml' => 'application/xml; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'md' => 'text/markdown; charset=utf-8',
        'csv' => 'text/csv; charset=utf-8',
        'yaml', 'yml' => 'text/yaml; charset=utf-8',
        'toml' => 'text/toml; charset=utf-8',

        // Images
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'bmp' => 'image/bmp',

        // Fonts
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'eot' => 'application/vnd.ms-fontobject',

        // Audio/Video
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogg' => 'audio/ogg',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'flac' => 'audio/flac',

        // Archives
        'zip' => 'application/zip',
        'gz', 'tar' => 'application/gzip',

        // Documents
        'pdf' => 'application/pdf',

        // Binary / WASM
        'wasm' => 'application/wasm',

        // Default
        default => 'application/octet-stream',
    };
}

/**
 * Generate an HTML directory listing.
 */
function generateDirectoryListing(string $dirPath, string $urlPath, string $docroot): string
{
    $entries = scandir($dirPath);
    if ($entries === false) {
        return '<html><body><h1>Cannot read directory</h1></body></html>';
    }

    $urlPath = rtrim($urlPath, '/');
    $displayPath = $urlPath === '' ? '/' : $urlPath . '/';

    $rows = '';

    // Parent directory link
    if ($urlPath !== '' && $urlPath !== '/') {
        $parent = dirname($urlPath);
        if ($parent === '.') {
            $parent = '/';
        }
        $rows .= '<tr><td><a href="' . htmlspecialchars($parent, ENT_QUOTES) . '">..</a></td><td>-</td><td>-</td></tr>';
    }

    $dirs = [];
    $files = [];

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        // Skip hidden files
        if (str_starts_with($entry, '.')) {
            continue;
        }

        $fullPath = $dirPath . '/' . $entry;
        $entryUrl = $urlPath . '/' . rawurlencode($entry);

        if (is_dir($fullPath)) {
            $dirs[] = [
                'name' => $entry . '/',
                'url' => $entryUrl . '/',
                'size' => '-',
                'modified' => date('Y-m-d H:i', filemtime($fullPath) ?: 0),
            ];
        } else {
            $size = filesize($fullPath);
            $files[] = [
                'name' => $entry,
                'url' => $entryUrl,
                'size' => formatSize($size ?: 0),
                'modified' => date('Y-m-d H:i', filemtime($fullPath) ?: 0),
            ];
        }
    }

    // Directories first, then files
    foreach (array_merge($dirs, $files) as $item) {
        $escapedName = htmlspecialchars($item['name'], ENT_QUOTES);
        $escapedUrl = htmlspecialchars($item['url'], ENT_QUOTES);
        $rows .= "<tr><td><a href=\"{$escapedUrl}\">{$escapedName}</a></td>"
            . "<td>{$item['size']}</td>"
            . "<td>{$item['modified']}</td></tr>";
    }

    $escapedDisplayPath = htmlspecialchars($displayPath, ENT_QUOTES);

    return <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Index of {$escapedDisplayPath}</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 2em; color: #333; }
            h1 { font-size: 1.4em; border-bottom: 1px solid #ddd; padding-bottom: 0.5em; }
            table { border-collapse: collapse; width: 100%; max-width: 800px; }
            th, td { text-align: left; padding: 0.4em 1em 0.4em 0; }
            th { border-bottom: 2px solid #ddd; font-size: 0.9em; color: #666; }
            td { border-bottom: 1px solid #eee; }
            a { color: #0366d6; text-decoration: none; }
            a:hover { text-decoration: underline; }
            .meta { color: #999; font-size: 0.85em; }
        </style>
    </head>
    <body>
        <h1>Index of {$escapedDisplayPath}</h1>
        <table>
            <thead><tr><th>Name</th><th>Size</th><th>Modified</th></tr></thead>
            <tbody>{$rows}</tbody>
        </table>
        <p class="meta">Coqui WebServer</p>
    </body>
    </html>
    HTML;
}

/**
 * Format a file size in human-readable form.
 */
function formatSize(int $bytes): string
{
    if ($bytes === 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $size = (float) $bytes;

    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }

    return round($size, 1) . ' ' . $units[$i];
}
