<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitWebserver;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\CoquiToolkitWebserver\Runtime\WebServerRunner;

/**
 * Tool for managing web server instances.
 *
 * Provides start, stop, restart, status, and list actions for PHP built-in
 * web servers that serve workspace files over HTTP.
 */
final class WebServerTool implements ToolInterface
{
    public function __construct(
        private readonly WebServerRunner $runner,
    ) {}

    public function name(): string
    {
        return 'webserver';
    }

    public function description(): string
    {
        return <<<'DESC'
            Manage web server instances that serve workspace files over HTTP.

            Available actions:
            - start: Start a new web server. Specify a docroot directory (relative to workspace)
              and optionally a port, host, and features like gzip, directory listing, or PHP execution.
            - stop: Stop a running server instance.
            - restart: Stop and restart a server with the same configuration.
            - status: Get detailed status of a server instance.
            - list: List all managed server instances with their status.
            - stop_all: Stop all running server instances.
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
            'start' => $this->startServer($input),
            'stop' => $this->stopServer($input),
            'restart' => $this->restartServer($input),
            'status' => $this->serverStatus($input),
            'list' => $this->listServers(),
            'stop_all' => $this->stopAll(),
            default => ToolResult::error("Unknown webserver action: '{$action}'"),
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
                            'description' => 'The server management action to perform.',
                            'enum' => ['start', 'stop', 'restart', 'status', 'list', 'stop_all'],
                        ],
                        'docroot' => [
                            'type' => 'string',
                            'description' => 'Directory to serve files from, relative to workspace (e.g. "public", "site/build"). Required for "start".',
                        ],
                        'port' => [
                            'type' => 'integer',
                            'description' => 'Port to listen on. Auto-detected starting from 8000 if not specified.',
                        ],
                        'host' => [
                            'type' => 'string',
                            'description' => 'Host/IP to bind to. Default: "127.0.0.1". Use "0.0.0.0" to allow network access (use with caution).',
                        ],
                        'name' => [
                            'type' => 'string',
                            'description' => 'Server instance name. Auto-generated from workspace if not specified. Use to manage multiple servers.',
                        ],
                        'gzip' => [
                            'type' => 'boolean',
                            'description' => 'Enable gzip compression for text-based responses. Default: true.',
                        ],
                        'directory_listing' => [
                            'type' => 'boolean',
                            'description' => 'Enable auto-generated directory listings when no index file exists. Default: false.',
                        ],
                        'php_enabled' => [
                            'type' => 'boolean',
                            'description' => 'Allow execution of .php files in the docroot. Default: true.',
                        ],
                        'rewrites' => [
                            'type' => 'object',
                            'description' => 'URL rewrite rules as a map of regex pattern to replacement. Example: {"^/api/(.*)$": "/api.php?route=$1"} or {"^(?!/api).*$": "/index.html"} for SPA fallback.',
                            'additionalProperties' => ['type' => 'string'],
                        ],
                    ],
                    'required' => ['action'],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function startServer(array $input): ToolResult
    {
        $docroot = trim((string) ($input['docroot'] ?? ''));

        if ($docroot === '') {
            return ToolResult::error(
                'The "docroot" parameter is required for the "start" action. '
                . 'Specify a directory relative to the workspace to serve files from (e.g. "public", "site").',
            );
        }

        $options = [
            'docroot' => $docroot,
        ];

        if (isset($input['port'])) {
            $options['port'] = (int) $input['port'];
        }
        if (isset($input['host'])) {
            $options['host'] = (string) $input['host'];
        }
        if (isset($input['name'])) {
            $options['name'] = (string) $input['name'];
        }
        if (isset($input['gzip'])) {
            $options['gzip'] = (bool) $input['gzip'];
        }
        if (isset($input['directory_listing'])) {
            $options['directory_listing'] = (bool) $input['directory_listing'];
        }
        if (isset($input['php_enabled'])) {
            $options['php_enabled'] = (bool) $input['php_enabled'];
        }
        if (isset($input['rewrites']) && is_array($input['rewrites'])) {
            $options['rewrites'] = $input['rewrites'];
        }

        $result = $this->runner->start($options);

        if (!$result['success']) {
            return ToolResult::error("## Server Start Failed\n\n" . $result['message']);
        }

        $output = "## Server Started\n\n";
        $output .= "| Setting | Value |\n|---------|-------|\n";
        $output .= "| **URL** | " . ($result['url'] ?? '') . " |\n";
        $output .= "| **Name** | `" . ($result['name'] ?? '') . "` |\n";
        $output .= "| **Port** | " . ($result['port'] ?? '') . " |\n";
        $output .= "| **Host** | " . ($result['host'] ?? '') . " |\n";
        $output .= "| **Docroot** | {$docroot} |\n";

        if (($options['gzip'] ?? true) === true) {
            $output .= "| **Gzip** | Enabled |\n";
        }
        if (($options['directory_listing'] ?? false) === true) {
            $output .= "| **Directory Listing** | Enabled |\n";
        }
        if (($options['php_enabled'] ?? true) === true) {
            $output .= "| **PHP Execution** | Enabled |\n";
        }

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function stopServer(array $input): ToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));

        $result = $this->runner->stop($name);

        if (!$result['success']) {
            return ToolResult::error("## Server Stop Failed\n\n" . $result['message']);
        }

        return ToolResult::success("## Server Stopped\n\n" . $result['message']);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function restartServer(array $input): ToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            $name = $this->runner->defaultName();
        }

        // Get current server config
        $status = $this->runner->status($name);
        if (!$status['running']) {
            return ToolResult::error("Server '{$name}' is not running. Use 'start' to start a new server.");
        }

        // Read the metadata to get the original options
        $metaFile = $this->runner->serverDir() . "/{$name}.json";
        if (!is_file($metaFile)) {
            return ToolResult::error("Server '{$name}' metadata not found. Use 'start' instead.");
        }

        $meta = json_decode(file_get_contents($metaFile) ?: '{}', true);
        if (!is_array($meta)) {
            return ToolResult::error("Server '{$name}' has corrupt metadata. Stop and start manually.");
        }

        // Stop the server
        $stopResult = $this->runner->stop($name);
        if (!$stopResult['success']) {
            return ToolResult::error("Failed to stop server for restart: " . $stopResult['message']);
        }

        // Brief pause for port release
        usleep(300_000); // 300ms

        // Rebuild options from metadata
        $options = [
            'docroot' => $meta['docroot'] ?? '',
            'port' => (int) ($meta['port'] ?? 0),
            'host' => $meta['host'] ?? '127.0.0.1',
            'name' => $name,
            'gzip' => (bool) ($meta['gzip'] ?? true),
            'directory_listing' => (bool) ($meta['directory_listing'] ?? false),
            'php_enabled' => (bool) ($meta['php_enabled'] ?? true),
            'rewrites' => $meta['rewrites'] ?? [],
        ];

        // Apply any overrides from input
        if (isset($input['port'])) {
            $options['port'] = (int) $input['port'];
        }
        if (isset($input['docroot'])) {
            $options['docroot'] = (string) $input['docroot'];
        }

        $startResult = $this->runner->start($options);

        if (!$startResult['success']) {
            return ToolResult::error("Server stopped but restart failed: " . $startResult['message']);
        }

        $restartUrl = $startResult['url'] ?? '';
        return ToolResult::success(
            "## Server Restarted\n\n"
            . "Server '{$name}' restarted at {$restartUrl}",
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function serverStatus(array $input): ToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));

        $status = $this->runner->status($name);

        $displayName = $status['name'];
        $output = "## Server Status: {$displayName}\n\n";

        if (!$status['running']) {
            $output .= "**Status:** Not running\n";
            return ToolResult::success($output);
        }

        $output .= "| Setting | Value |\n|---------|-------|\n";
        $output .= "| **Status** | Running |\n";
        $output .= "| **URL** | " . ($status['url'] ?? '-') . " |\n";
        $output .= "| **PID** | " . ($status['pid'] ?? '-') . " |\n";
        $output .= "| **Port** | " . ($status['port'] ?? '-') . " |\n";
        $output .= "| **Host** | " . ($status['host'] ?? '-') . " |\n";
        $output .= "| **Docroot** | " . ($status['docroot'] ?? '-') . " |\n";
        $output .= "| **Uptime** | " . ($status['uptime'] ?? '-') . " |\n";
        $output .= "| **Started** | " . ($status['started_at'] ?? '-') . " |\n";
        $output .= "| **Gzip** | " . (($status['gzip'] ?? false) ? 'Enabled' : 'Disabled') . " |\n";
        $output .= "| **Directory Listing** | " . (($status['directory_listing'] ?? false) ? 'Enabled' : 'Disabled') . " |\n";
        $output .= "| **PHP Execution** | " . (($status['php_enabled'] ?? false) ? 'Enabled' : 'Disabled') . " |\n";

        return ToolResult::success($output);
    }

    private function listServers(): ToolResult
    {
        $servers = $this->runner->listServers();

        if ($servers === []) {
            return ToolResult::success("## Web Servers\n\nNo managed servers found.");
        }

        $output = "## Web Servers\n\n";
        $output .= "| Name | Status | URL | Docroot | Uptime |\n";
        $output .= "|------|--------|-----|---------|--------|\n";

        foreach ($servers as $server) {
            $status = $server['running'] ? 'Running' : 'Stopped';
            $url = $server['url'] ?? '-';
            $docroot = $server['docroot'] ?? '-';
            $uptime = $server['uptime'] ?? '-';
            $output .= "| `{$server['name']}` | {$status} | {$url} | {$docroot} | {$uptime} |\n";
        }

        return ToolResult::success($output);
    }

    private function stopAll(): ToolResult
    {
        $result = $this->runner->stopAll();

        $output = "## Stop All Servers\n\n";

        foreach ($result['messages'] as $msg) {
            $output .= "- {$msg}\n";
        }

        if ($result['stopped'] > 0 || $result['failed'] > 0) {
            $output .= "\n**Stopped:** {$result['stopped']} | **Failed:** {$result['failed']}";
        }

        return ToolResult::success($output);
    }
}
