<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitWebserver\Storage;

/**
 * SQLite-backed request log for a web server instance.
 *
 * Each server instance has its own database file at `.workspace/webserver/{name}.db`.
 * Provides structured logging and querying of HTTP requests served by the router script.
 *
 * The router script writes to this database directly (via PDO in the router process).
 * The toolkit reads from it to provide log querying tools to the agent.
 */
final class WebServerLogStore
{
    private ?\PDO $db = null;

    public function __construct(
        private readonly string $dbPath,
    ) {}

    /**
     * Log a request. Used by the router script.
     */
    public function log(
        string $method,
        string $path,
        string $query,
        int $status,
        int $size,
        float $durationMs,
        string $userAgent,
        string $remoteAddr,
    ): void {
        $db = $this->connect();

        $stmt = $db->prepare(
            'INSERT INTO request_log (timestamp, method, path, query, status, size, duration_ms, user_agent, remote_addr)
             VALUES (:timestamp, :method, :path, :query, :status, :size, :duration_ms, :user_agent, :remote_addr)',
        );

        $stmt->execute([
            ':timestamp' => date('c'),
            ':method' => $method,
            ':path' => $path,
            ':query' => $query,
            ':status' => $status,
            ':size' => $size,
            ':duration_ms' => $durationMs,
            ':user_agent' => $userAgent,
            ':remote_addr' => $remoteAddr,
        ]);
    }

    /**
     * Get the most recent N requests.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tail(int $limit = 20): array
    {
        $db = $this->connect();

        $stmt = $db->prepare(
            'SELECT * FROM request_log ORDER BY id DESC LIMIT :limit',
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Search log entries with filters.
     *
     * @param array{path?: string, method?: string, status?: int, status_min?: int, status_max?: int} $filters
     * @return array<int, array<string, mixed>>
     */
    public function search(array $filters, int $limit = 50): array
    {
        $db = $this->connect();

        $conditions = [];
        $params = [];

        if (isset($filters['path']) && $filters['path'] !== '') {
            $conditions[] = 'path LIKE :path';
            $params[':path'] = '%' . $filters['path'] . '%';
        }

        if (isset($filters['method']) && $filters['method'] !== '') {
            $conditions[] = 'method = :method';
            $params[':method'] = strtoupper($filters['method']);
        }

        if (isset($filters['status'])) {
            $conditions[] = 'status = :status';
            $params[':status'] = $filters['status'];
        }

        if (isset($filters['status_min'])) {
            $conditions[] = 'status >= :status_min';
            $params[':status_min'] = $filters['status_min'];
        }

        if (isset($filters['status_max'])) {
            $conditions[] = 'status <= :status_max';
            $params[':status_max'] = $filters['status_max'];
        }

        $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $stmt = $db->prepare(
            "SELECT * FROM request_log {$where} ORDER BY id DESC LIMIT :limit",
        );

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get aggregate statistics from the request log.
     *
     * @return array{total_requests: int, methods: array<string, int>, status_codes: array<string, int>, top_paths: array<int, array{path: string, count: int}>, avg_duration_ms: float, total_bytes: int, first_request: ?string, last_request: ?string, requests_per_minute: float}
     */
    public function stats(): array
    {
        $db = $this->connect();

        // Total requests
        $stmt = $db->query('SELECT COUNT(*) FROM request_log');
        $total = $stmt !== false ? (int) $stmt->fetchColumn() : 0;

        if ($total === 0) {
            return [
                'total_requests' => 0,
                'methods' => [],
                'status_codes' => [],
                'top_paths' => [],
                'avg_duration_ms' => 0.0,
                'total_bytes' => 0,
                'first_request' => null,
                'last_request' => null,
                'requests_per_minute' => 0.0,
            ];
        }

        // Method distribution
        $methods = [];
        $methodStmt = $db->query('SELECT method, COUNT(*) as cnt FROM request_log GROUP BY method ORDER BY cnt DESC');
        if ($methodStmt !== false) {
            foreach ($methodStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
                $methods[$row['method']] = (int) $row['cnt'];
            }
        }

        // Status code distribution
        $statusCodes = [];
        $statusStmt = $db->query('SELECT status, COUNT(*) as cnt FROM request_log GROUP BY status ORDER BY status');
        if ($statusStmt !== false) {
            foreach ($statusStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
                $statusCodes[(string) $row['status']] = (int) $row['cnt'];
            }
        }

        // Top paths
        $topPaths = [];
        $pathStmt = $db->query('SELECT path, COUNT(*) as cnt FROM request_log GROUP BY path ORDER BY cnt DESC LIMIT 10');
        if ($pathStmt !== false) {
            foreach ($pathStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
                $topPaths[] = ['path' => $row['path'], 'count' => (int) $row['cnt']];
            }
        }

        // Averages and totals
        $aggStmt = $db->query(
            'SELECT AVG(duration_ms) as avg_ms, SUM(size) as total_bytes, MIN(timestamp) as first_ts, MAX(timestamp) as last_ts FROM request_log',
        );
        $agg = $aggStmt !== false ? $aggStmt->fetch(\PDO::FETCH_ASSOC) : false;

        $avgDuration = is_array($agg) ? round((float) ($agg['avg_ms'] ?? 0), 2) : 0.0;
        $totalBytes = is_array($agg) ? (int) ($agg['total_bytes'] ?? 0) : 0;
        $firstRequest = is_array($agg) ? ($agg['first_ts'] ?? null) : null;
        $lastRequest = is_array($agg) ? ($agg['last_ts'] ?? null) : null;

        // Requests per minute
        $rpm = 0.0;
        if ($firstRequest !== null && $lastRequest !== null) {
            try {
                $first = new \DateTimeImmutable($firstRequest);
                $last = new \DateTimeImmutable($lastRequest);
                $diffSeconds = abs($last->getTimestamp() - $first->getTimestamp());
                if ($diffSeconds > 0) {
                    $rpm = round(($total / $diffSeconds) * 60, 2);
                }
            } catch (\Exception) {
                // Ignore date parse errors
            }
        }

        return [
            'total_requests' => $total,
            'methods' => $methods,
            'status_codes' => $statusCodes,
            'top_paths' => $topPaths,
            'avg_duration_ms' => $avgDuration,
            'total_bytes' => $totalBytes,
            'first_request' => $firstRequest,
            'last_request' => $lastRequest,
            'requests_per_minute' => $rpm,
        ];
    }

    /**
     * Clear all log entries.
     */
    public function clear(): int
    {
        $db = $this->connect();

        $stmt = $db->query('SELECT COUNT(*) FROM request_log');
        $count = $stmt !== false ? (int) $stmt->fetchColumn() : 0;
        if ($stmt !== false) {
            $stmt->closeCursor();
        }
        $stmt = null;
        $db->exec('DELETE FROM request_log');
        $db->exec('VACUUM');

        return $count;
    }

    /**
     * Check if the database file exists and has any entries.
     */
    public function hasEntries(): bool
    {
        if (!is_file($this->dbPath)) {
            return false;
        }

        try {
            $db = $this->connect();
            $stmt = $db->query('SELECT COUNT(*) FROM request_log');
            return $stmt !== false && ((int) $stmt->fetchColumn()) > 0;
        } catch (\PDOException) {
            return false;
        }
    }

    /**
     * Get or create the database connection.
     */
    private function connect(): \PDO
    {
        if ($this->db !== null) {
            return $this->db;
        }

        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $db = new \PDO("sqlite:{$this->dbPath}");
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA foreign_keys=ON');

        $this->db = $db;
        $this->createTable();

        return $db;
    }

    /**
     * Create the request_log table if it doesn't exist.
     */
    private function createTable(): void
    {
        if ($this->db === null) {
            return;
        }

        $this->db->exec(
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

        // Index for common queries
        $this->db->exec(
            'CREATE INDEX IF NOT EXISTS idx_request_log_path ON request_log (path)',
        );
        $this->db->exec(
            'CREATE INDEX IF NOT EXISTS idx_request_log_status ON request_log (status)',
        );
        $this->db->exec(
            'CREATE INDEX IF NOT EXISTS idx_request_log_timestamp ON request_log (timestamp)',
        );
    }
}
