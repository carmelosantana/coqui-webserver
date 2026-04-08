<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitWebserver;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\CoquiToolkitWebserver\Runtime\WebServerRunner;
use CarmeloSantana\CoquiToolkitWebserver\Storage\WebServerLogStore;

/**
 * Tool for querying web server request logs.
 *
 * Each server instance writes incoming requests to its own SQLite database.
 * This tool lets the agent query those logs for debugging, monitoring, and analysis.
 */
final class WebServerLogTool implements ToolInterface
{
    public function __construct(
        private readonly WebServerRunner $runner,
    ) {}

    public function name(): string
    {
        return 'webserver_log';
    }

    public function description(): string
    {
        return <<<'DESC'
            Query web server request logs for debugging and monitoring.

            Each server instance logs all incoming HTTP requests to a SQLite database.
            Use this tool to inspect traffic, debug issues, and analyze usage patterns.

            Available actions:
            - tail: Get the most recent requests (default: last 20).
            - search: Search logs by path, HTTP method, or status code.
            - stats: Get aggregate statistics — total requests, top paths, status distribution,
              average response time, requests per minute.
            - clear: Delete all log entries for a server instance.
            DESC;
    }

    public function parameters(): array
    {
        return [];
    }

    public function execute(array $input): ToolResult
    {
        $action = $input['action'] ?? '';

        return match ($action) {
            'tail' => $this->tail($input),
            'search' => $this->search($input),
            'stats' => $this->stats($input),
            'clear' => $this->clear($input),
            default => ToolResult::error("Unknown webserver_log action: '{$action}'"),
        };
    }

    public function toFunctionSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => [
                            'type' => 'string',
                            'description' => 'The log query action to perform.',
                            'enum' => ['tail', 'search', 'stats', 'clear'],
                        ],
                        'name' => [
                            'type' => 'string',
                            'description' => 'Server instance name. Uses the default server if not specified.',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Maximum number of log entries to return. Default: 20 for tail, 50 for search.',
                        ],
                        'path' => [
                            'type' => 'string',
                            'description' => 'Filter by request path (partial match). Used with "search" action.',
                        ],
                        'method' => [
                            'type' => 'string',
                            'description' => 'Filter by HTTP method (GET, POST, PUT, DELETE, etc.). Used with "search" action.',
                            'enum' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'],
                        ],
                        'status' => [
                            'type' => 'integer',
                            'description' => 'Filter by exact HTTP status code (e.g. 200, 404, 500). Used with "search" action.',
                        ],
                    ],
                    'required' => ['action'],
                ],
            ],
        ];
    }

    private function getLogStore(string $name): ?WebServerLogStore
    {
        $dbPath = $this->runner->logDbPath($name);

        if (!is_file($dbPath)) {
            return null;
        }

        return new WebServerLogStore($dbPath);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function tail(array $input): ToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));
        $limit = (int) ($input['limit'] ?? 20);
        $limit = max(1, min($limit, 200));

        $store = $this->getLogStore($name);
        if ($store === null) {
            return ToolResult::success("## Request Log\n\nNo log database found. The server may not have received any requests yet.");
        }

        $entries = $store->tail($limit);

        if ($entries === []) {
            return ToolResult::success("## Request Log\n\nNo requests logged yet.");
        }

        return ToolResult::success($this->formatEntries($entries, "Recent Requests (last {$limit})"));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function search(array $input): ToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));
        $limit = (int) ($input['limit'] ?? 50);
        $limit = max(1, min($limit, 200));

        $store = $this->getLogStore($name);
        if ($store === null) {
            return ToolResult::success("## Log Search\n\nNo log database found.");
        }

        $filters = [];

        if (isset($input['path']) && is_string($input['path']) && $input['path'] !== '') {
            $filters['path'] = $input['path'];
        }
        if (isset($input['method']) && is_string($input['method']) && $input['method'] !== '') {
            $filters['method'] = $input['method'];
        }
        if (isset($input['status'])) {
            $filters['status'] = (int) $input['status'];
        }

        $entries = $store->search($filters, $limit);

        if ($entries === []) {
            $filterDesc = [];
            foreach ($filters as $key => $value) {
                $filterDesc[] = "{$key}={$value}";
            }
            $desc = $filterDesc !== [] ? ' matching: ' . implode(', ', $filterDesc) : '';
            return ToolResult::success("## Log Search\n\nNo requests found{$desc}.");
        }

        return ToolResult::success($this->formatEntries($entries, 'Search Results'));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function stats(array $input): ToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));

        $store = $this->getLogStore($name);
        if ($store === null) {
            return ToolResult::success("## Server Stats\n\nNo log database found.");
        }

        $stats = $store->stats();

        if ($stats['total_requests'] === 0) {
            return ToolResult::success("## Server Stats\n\nNo requests logged yet.");
        }

        $output = "## Server Stats\n\n";
        $output .= "| Metric | Value |\n|--------|-------|\n";
        $output .= "| **Total Requests** | {$stats['total_requests']} |\n";
        $output .= "| **Avg Response Time** | {$stats['avg_duration_ms']}ms |\n";
        $output .= "| **Total Data Served** | " . $this->formatBytes($stats['total_bytes']) . " |\n";
        $output .= "| **Req/min** | {$stats['requests_per_minute']} |\n";
        $output .= "| **First Request** | {$stats['first_request']} |\n";
        $output .= "| **Last Request** | {$stats['last_request']} |\n";

        // Method distribution
        if ($stats['methods'] !== []) {
            $output .= "\n### Methods\n\n";
            foreach ($stats['methods'] as $method => $count) {
                $output .= "- **{$method}**: {$count}\n";
            }
        }

        // Status code distribution
        if ($stats['status_codes'] !== []) {
            $output .= "\n### Status Codes\n\n";
            foreach ($stats['status_codes'] as $code => $count) {
                $output .= "- **{$code}**: {$count}\n";
            }
        }

        // Top paths
        if ($stats['top_paths'] !== []) {
            $output .= "\n### Top Paths\n\n";
            $output .= "| Path | Hits |\n|------|------|\n";
            foreach ($stats['top_paths'] as $pathInfo) {
                $output .= "| `{$pathInfo['path']}` | {$pathInfo['count']} |\n";
            }
        }

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function clear(array $input): ToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));

        $store = $this->getLogStore($name);
        if ($store === null) {
            return ToolResult::success("## Clear Logs\n\nNo log database found — nothing to clear.");
        }

        $count = $store->clear();

        return ToolResult::success("## Clear Logs\n\nDeleted {$count} log entries.");
    }

    /**
     * Format log entries as a markdown table.
     *
     * @param array<int, array<string, mixed>> $entries
     */
    private function formatEntries(array $entries, string $title): string
    {
        $output = "## {$title}\n\n";
        $output .= "| Time | Method | Path | Status | Size | Duration |\n";
        $output .= "|------|--------|------|--------|------|----------|\n";

        foreach ($entries as $entry) {
            $time = isset($entry['timestamp']) ? substr((string) $entry['timestamp'], 11, 8) : '-';
            $method = $entry['method'] ?? '-';
            $path = $entry['path'] ?? '-';
            $status = $entry['status'] ?? '-';
            $size = $this->formatBytes((int) ($entry['size'] ?? 0));
            $duration = isset($entry['duration_ms']) ? round((float) $entry['duration_ms'], 1) . 'ms' : '-';

            $output .= "| {$time} | {$method} | `{$path}` | {$status} | {$size} | {$duration} |\n";
        }

        $output .= "\n*{$this->pluralize(count($entries), 'entry', 'entries')} shown*";

        return $output;
    }

    private function formatBytes(int $bytes): string
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

    private function pluralize(int $count, string $singular, string $plural): string
    {
        return $count === 1 ? "1 {$singular}" : "{$count} {$plural}";
    }
}
