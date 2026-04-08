<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitWebserver\Runtime;

/**
 * Manages the lifecycle of PHP built-in web server processes.
 *
 * Spawns `php -S` as a detached background process, tracks it via PID files,
 * and provides start/stop/status/list operations. All server files (PID, metadata,
 * router scripts, databases) are stored in `.workspace/webserver/`.
 *
 * Each server instance is identified by a name (deterministic default based on
 * workspace path hash, or user-specified). Multiple servers can run concurrently
 * on different ports.
 */
final class WebServerRunner
{
    private const int DEFAULT_PORT_START = 8000;
    private const int MAX_PORT_SCAN = 100;
    private const int STOP_TIMEOUT_MS = 2000;
    private const int STOP_CHECK_INTERVAL_MS = 50;

    public function __construct(
        private readonly string $workspacePath,
        private readonly string $defaultName = '',
    ) {}

    /**
     * Start a new web server instance.
     *
     * @param array{
     *     port?: int,
     *     host?: string,
     *     docroot: string,
     *     name?: string,
     *     gzip?: bool,
     *     directory_listing?: bool,
     *     php_enabled?: bool,
     *     rewrites?: array<string, string>,
     * } $options
     * @return array{success: bool, message: string, port?: int, host?: string, url?: string, name?: string}
     */
    public function start(array $options): array
    {
        $docroot = $options['docroot'];
        if ($docroot === '') {
            return ['success' => false, 'message' => 'Docroot is required.'];
        }

        $resolvedDocroot = $this->resolveDocroot($docroot);
        if ($resolvedDocroot === null) {
            return [
                'success' => false,
                'message' => "Invalid docroot: '{$docroot}' — must be within the workspace directory.",
            ];
        }

        if (!is_dir($resolvedDocroot)) {
            // Create it if it doesn't exist
            if (!@mkdir($resolvedDocroot, 0755, true) && !is_dir($resolvedDocroot)) {
                return ['success' => false, 'message' => "Failed to create docroot directory: {$docroot}"];
            }
        }

        $name = $options['name'] ?? '';
        if ($name === '') {
            $name = $this->defaultName();
        }

        // Check if a server with this name is already running
        $existingStatus = $this->status($name);
        if ($existingStatus['running']) {
            $existingPort = $existingStatus['port'] ?? 0;
            return [
                'success' => false,
                'message' => "Server '{$name}' is already running on port {$existingPort}."
                    . " Stop it first or use a different name.",
            ];
        }

        $host = $options['host'] ?? '127.0.0.1';
        $port = $options['port'] ?? 0;

        if ($port === 0) {
            $port = $this->findAvailablePort(self::DEFAULT_PORT_START);
            if ($port === null) {
                return [
                    'success' => false,
                    'message' => 'Could not find an available port in range '
                        . self::DEFAULT_PORT_START . '-' . (self::DEFAULT_PORT_START + self::MAX_PORT_SCAN) . '.',
                ];
            }
        } elseif (!$this->isPortAvailable($host, $port)) {
            return ['success' => false, 'message' => "Port {$port} is already in use."];
        }

        $gzip = $options['gzip'] ?? true;
        $directoryListing = $options['directory_listing'] ?? false;
        $phpEnabled = $options['php_enabled'] ?? true;
        $rewrites = $options['rewrites'] ?? [];

        // Ensure webserver directory exists
        $serverDir = $this->serverDir();
        if (!is_dir($serverDir)) {
            mkdir($serverDir, 0755, true);
        }

        // Prepare the router script
        $routerSource = dirname(__DIR__) . '/Resources/router.php';
        if (!is_file($routerSource)) {
            return ['success' => false, 'message' => 'Router script not found in toolkit resources.'];
        }

        $routerDest = $serverDir . "/{$name}-router.php";
        if (!copy($routerSource, $routerDest)) {
            return ['success' => false, 'message' => 'Failed to copy router script to workspace.'];
        }

        // Write rewrite rules if any
        $rewritesFile = '';
        if ($rewrites !== []) {
            $rewritesFile = $serverDir . "/{$name}-rewrites.json";
            file_put_contents($rewritesFile, json_encode($rewrites, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        // Prepare the log database path
        $logDbPath = $serverDir . "/{$name}.db";

        // Build environment variables for the router
        $env = [
            'COQUI_WS_GZIP' => $gzip ? '1' : '0',
            'COQUI_WS_DIR_LISTING' => $directoryListing ? '1' : '0',
            'COQUI_WS_PHP_ENABLED' => $phpEnabled ? '1' : '0',
            'COQUI_WS_LOG_DB' => $logDbPath,
            'COQUI_WS_REWRITES_FILE' => $rewritesFile,
            'COQUI_WS_DOCROOT' => $resolvedDocroot,
        ];

        // Build the command
        $phpBinary = PHP_BINARY;
        $listenAddr = escapeshellarg("{$host}:{$port}");
        $docRootArg = escapeshellarg($resolvedDocroot);
        $routerArg = escapeshellarg($routerDest);

        // Build env string for the command
        $envParts = [];
        foreach ($env as $key => $value) {
            $envParts[] = escapeshellarg($key) . '=' . escapeshellarg($value);
        }
        $envString = implode(' ', $envParts);

        // Use nohup + env to detach the server process
        $command = sprintf(
            'nohup env %s %s -S %s -t %s %s > %s 2>&1 & echo $!',
            $envString,
            escapeshellarg($phpBinary),
            $listenAddr,
            $docRootArg,
            $routerArg,
            escapeshellarg($serverDir . "/{$name}.log"),
        );

        $pid = $this->spawnProcess($command);

        if ($pid === null) {
            return ['success' => false, 'message' => 'Failed to start server process.'];
        }

        // Give the server a moment to start, then verify it's running
        usleep(200_000); // 200ms

        if (!$this->isProcessAlive($pid)) {
            // Read the log for error details
            $logFile = $serverDir . "/{$name}.log";
            $logContent = is_file($logFile) ? file_get_contents($logFile) : '';
            $this->cleanupFiles($name);

            return [
                'success' => false,
                'message' => "Server process exited immediately.\n" . ($logContent ?: 'No log output.'),
            ];
        }

        // Write PID and metadata files
        file_put_contents($serverDir . "/{$name}.pid", (string) $pid);

        $metadata = [
            'name' => $name,
            'pid' => $pid,
            'host' => $host,
            'port' => $port,
            'docroot' => $docroot,
            'docroot_absolute' => $resolvedDocroot,
            'gzip' => $gzip,
            'directory_listing' => $directoryListing,
            'php_enabled' => $phpEnabled,
            'rewrites' => $rewrites,
            'started_at' => date('c'),
            'log_db' => $logDbPath,
        ];
        file_put_contents(
            $serverDir . "/{$name}.json",
            json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        $url = "http://{$host}:{$port}";

        return [
            'success' => true,
            'message' => "Server '{$name}' started.",
            'port' => $port,
            'host' => $host,
            'url' => $url,
            'name' => $name,
        ];
    }

    /**
     * Stop a running server instance.
     *
     * @return array{success: bool, message: string}
     */
    public function stop(string $name = ''): array
    {
        if ($name === '') {
            $name = $this->defaultName();
        }

        $pidFile = $this->serverDir() . "/{$name}.pid";

        if (!is_file($pidFile)) {
            return ['success' => false, 'message' => "No server found with name '{$name}'."];
        }

        $pid = (int) file_get_contents($pidFile);

        if ($pid <= 0) {
            $this->cleanupFiles($name);
            return ['success' => false, 'message' => "Invalid PID for server '{$name}'. Files cleaned up."];
        }

        if (!$this->isProcessAlive($pid)) {
            $this->cleanupFiles($name);
            return ['success' => true, 'message' => "Server '{$name}' was not running. Files cleaned up."];
        }

        // Send SIGTERM first
        posix_kill($pid, SIGTERM);

        // Wait for graceful shutdown
        $waited = 0;
        while ($waited < self::STOP_TIMEOUT_MS) {
            usleep(self::STOP_CHECK_INTERVAL_MS * 1000);
            $waited += self::STOP_CHECK_INTERVAL_MS;

            if (!$this->isProcessAlive($pid)) { // @phpstan-ignore booleanNot.alwaysFalse (process may die after SIGTERM)
                $this->cleanupFiles($name);
                return ['success' => true, 'message' => "Server '{$name}' stopped gracefully."];
            }
        }

        // Force kill
        posix_kill($pid, SIGKILL);
        usleep(100_000); // 100ms grace

        $this->cleanupFiles($name);

        return ['success' => true, 'message' => "Server '{$name}' killed (did not respond to SIGTERM)."];
    }

    /**
     * Get the status of a server instance.
     *
     * @return array{running: bool, name: string, pid?: int, host?: string, port?: int, docroot?: string, started_at?: string, uptime?: string, url?: string, gzip?: bool, directory_listing?: bool, php_enabled?: bool}
     */
    public function status(string $name = ''): array
    {
        if ($name === '') {
            $name = $this->defaultName();
        }

        $metaFile = $this->serverDir() . "/{$name}.json";

        if (!is_file($metaFile)) {
            return ['running' => false, 'name' => $name];
        }

        $meta = json_decode(file_get_contents($metaFile) ?: '{}', true);
        if (!is_array($meta)) {
            return ['running' => false, 'name' => $name];
        }

        $pid = (int) ($meta['pid'] ?? 0);
        $running = $pid > 0 && $this->isProcessAlive($pid);

        if (!$running) {
            // Clean up stale files
            $this->cleanupFiles($name);
            return ['running' => false, 'name' => $name];
        }

        $startedAt = $meta['started_at'] ?? '';
        $uptime = '';
        if ($startedAt !== '') {
            try {
                $start = new \DateTimeImmutable($startedAt);
                $now = new \DateTimeImmutable();
                $diff = $now->diff($start);
                $parts = [];
                if ($diff->d > 0) {
                    $parts[] = "{$diff->d}d";
                }
                if ($diff->h > 0) {
                    $parts[] = "{$diff->h}h";
                }
                $parts[] = "{$diff->i}m";
                $uptime = implode(' ', $parts);
            } catch (\Exception) {
                $uptime = 'unknown';
            }
        }

        return [
            'running' => true,
            'name' => $name,
            'pid' => $pid,
            'host' => $meta['host'] ?? '127.0.0.1',
            'port' => (int) ($meta['port'] ?? 0),
            'docroot' => $meta['docroot'] ?? '',
            'started_at' => $startedAt,
            'uptime' => $uptime,
            'url' => 'http://' . ($meta['host'] ?? '127.0.0.1') . ':' . ($meta['port'] ?? 0),
            'gzip' => (bool) ($meta['gzip'] ?? true),
            'directory_listing' => (bool) ($meta['directory_listing'] ?? false),
            'php_enabled' => (bool) ($meta['php_enabled'] ?? true),
        ];
    }

    /**
     * List all managed server instances.
     *
     * @return array<int, array{running: bool, name: string, pid?: int, host?: string, port?: int, docroot?: string, started_at?: string, uptime?: string, url?: string}>
     */
    public function listServers(): array
    {
        $serverDir = $this->serverDir();
        if (!is_dir($serverDir)) {
            return [];
        }

        $pidFiles = glob($serverDir . '/*.pid');
        if ($pidFiles === false) {
            return [];
        }

        $servers = [];
        foreach ($pidFiles as $pidFile) {
            $name = basename($pidFile, '.pid');
            $servers[] = $this->status($name);
        }

        return $servers;
    }

    /**
     * Stop all running servers.
     *
     * @return array{stopped: int, failed: int, messages: string[]}
     */
    public function stopAll(): array
    {
        $servers = $this->listServers();
        $stopped = 0;
        $failed = 0;
        $messages = [];

        foreach ($servers as $server) {
            if (!$server['running']) {
                continue;
            }

            $result = $this->stop($server['name']);
            if ($result['success']) {
                $stopped++;
            } else {
                $failed++;
            }
            $messages[] = $result['message'];
        }

        if ($stopped === 0 && $failed === 0) {
            $messages[] = 'No running servers found.';
        }

        return ['stopped' => $stopped, 'failed' => $failed, 'messages' => $messages];
    }

    /**
     * Get the default server name for this workspace.
     */
    public function defaultName(): string
    {
        if ($this->defaultName !== '') {
            return $this->defaultName;
        }

        return 'coqui-' . substr(md5($this->workspacePath), 0, 8);
    }

    /**
     * Get the webserver data directory within the workspace.
     */
    public function serverDir(): string
    {
        return rtrim($this->workspacePath, '/') . '/webserver';
    }

    /**
     * Get the path to the log database for a server instance.
     */
    public function logDbPath(string $name = ''): string
    {
        if ($name === '') {
            $name = $this->defaultName();
        }

        return $this->serverDir() . "/{$name}.db";
    }

    /**
     * Check if a port is available on a given host.
     */
    public function isPortAvailable(string $host, int $port): bool
    {
        $connection = @fsockopen($host, $port, $errno, $errstr, 1);

        if ($connection !== false) {
            fclose($connection);
            return false; // Port is in use
        }

        return true; // Port is available
    }

    /**
     * Find an available port starting from the given number.
     */
    public function findAvailablePort(int $start, string $host = '127.0.0.1'): ?int
    {
        for ($port = $start; $port < $start + self::MAX_PORT_SCAN; $port++) {
            if ($this->isPortAvailable($host, $port)) {
                return $port;
            }
        }

        return null;
    }

    /**
     * Resolve a docroot path relative to the workspace.
     *
     * Returns null if the resolved path escapes the workspace root (path traversal protection).
     */
    public function resolveDocroot(string $relativePath): ?string
    {
        // Strip leading slashes to prevent absolute path injection
        $relativePath = ltrim($relativePath, '/');

        $path = rtrim($this->workspacePath, '/') . '/' . $relativePath;
        $realWorkspace = realpath($this->workspacePath);

        if ($realWorkspace === false) {
            return null;
        }

        // If the directory exists, verify its real path
        $realPath = realpath($path);
        if ($realPath !== false) {
            if (!str_starts_with($realPath, $realWorkspace)) {
                return null; // Path traversal attempt
            }
            return $realPath;
        }

        // Directory doesn't exist yet — verify the parent path
        $parentDir = dirname($path);
        $realParent = realpath($parentDir);
        if ($realParent === false || !str_starts_with($realParent, $realWorkspace)) {
            return null; // Path traversal attempt
        }

        return $path;
    }

    /**
     * Check if a process is alive.
     */
    private function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        return posix_kill($pid, 0);
    }

    /**
     * Spawn a detached process and return its PID.
     */
    private function spawnProcess(string $command): ?int
    {
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout (captures the PID from echo $!)
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($command, $descriptors, $pipes, $this->workspacePath);

        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        proc_close($process);

        $pid = (int) trim($output ?: '');

        return $pid > 0 ? $pid : null;
    }

    /**
     * Clean up all files for a server instance (PID, metadata, router, rewrites).
     * Preserves the log database.
     */
    private function cleanupFiles(string $name): void
    {
        $serverDir = $this->serverDir();

        $files = [
            "{$serverDir}/{$name}.pid",
            "{$serverDir}/{$name}.json",
            "{$serverDir}/{$name}-router.php",
            "{$serverDir}/{$name}-rewrites.json",
            "{$serverDir}/{$name}.log",
        ];

        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}
