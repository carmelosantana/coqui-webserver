# Coqui Web Server Toolkit

Web server toolkit for [Coqui](https://github.com/AgentCoqui/coqui). Gives agents the ability to serve workspace files over HTTP using PHP's built-in web server, with gzip compression, URL rewrites, directory listings, and structured request logging.

## Requirements

- PHP 8.4+
- POSIX extensions (`posix_kill`, `posix_getpid`) — standard on Linux/macOS

## Installation

```bash
composer require coquibot/coqui-toolkit-webserver
```

When installed alongside Coqui, the toolkit is **auto-discovered** via Composer's `extra.php-agents.toolkits` -- no manual registration needed.

## Tools Provided

### `webserver`

Manage web server instances that serve workspace files over HTTP.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | enum | Yes | `start`, `stop`, `restart`, `status`, `list`, `stop_all` |
| `docroot` | string | For `start` | Directory to serve, relative to workspace (e.g. `"public"`, `"site/build"`) |
| `port` | integer | No | Port to listen on (auto-detected from 8000 if omitted) |
| `host` | string | No | Bind address (default: `127.0.0.1`) |
| `name` | string | No | Server instance name (auto-generated if omitted) |
| `gzip` | boolean | No | Enable gzip compression (default: `true`) |
| `directory_listing` | boolean | No | Enable directory listings (default: `false`) |
| `php_enabled` | boolean | No | Allow PHP file execution (default: `true`) |
| `rewrites` | object | No | URL rewrite rules as regex pattern to replacement map |

### `webserver_log`

Query request logs for debugging and monitoring.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | enum | Yes | `tail`, `search`, `stats`, `clear` |
| `name` | string | No | Server instance name (default server if omitted) |
| `limit` | integer | No | Max entries to return (default: 20 for tail, 50 for search) |
| `path` | string | No | Filter by request path (partial match, for `search`) |
| `method` | enum | No | Filter by HTTP method (`GET`, `POST`, etc., for `search`) |
| `status` | integer | No | Filter by status code (for `search`) |

## Agent Workflow

1. Create content files in a workspace directory
2. `webserver` action `start` with `docroot` pointing to that directory
3. Access the returned URL in a browser or with `browser` tools
4. `webserver_log` action `tail` to check incoming requests
5. `webserver_log` action `stats` to see traffic patterns
6. `webserver` action `stop` when done

## Features

### Gzip Compression

Enabled by default. Compresses text-based responses (HTML, CSS, JS, JSON, SVG, XML) when the client accepts gzip encoding. Only compresses files larger than 1KB.

### URL Rewrites

Pass regex-based rewrite rules to transform URLs before file resolution. Common patterns:

```json
// SPA fallback — serve index.html for all non-API routes
{"^(?!/api).*$": "/index.html"}

// Route API requests through a PHP script
{"^/api/(.*)$": "/api.php?route=$1"}
```

Only the first matching rule is applied per request.

### Directory Listings

Disabled by default. When enabled, the server generates a clean HTML directory listing when a directory is requested and no index file exists. Hidden files (starting with `.`) are excluded.

### PHP Execution

Enabled by default. The server executes `.php` files placed in the docroot using PHP's built-in server handler. When disabled, `.php` files return 403 Forbidden.

### Request Logging

Every request is logged to a per-instance SQLite database at `.workspace/webserver/{name}.db`. Logs include timestamp, method, path, query string, status code, response size, duration, user agent, and client IP.

### Multiple Servers

Run multiple servers concurrently by specifying different `name` values. Each server has its own port, docroot, configuration, and log database.

## Security

- **Localhost only**: Servers bind to `127.0.0.1` by default. Use `host: "0.0.0.0"` to allow network access (use with caution).
- **Workspace sandboxing**: Only files within the workspace directory can be served. Path traversal attempts are blocked.
- **Sensitive file blocking**: `.env`, `.git/`, `.workspace/`, `vendor/`, `node_modules/`, `*.db`, `*.sqlite`, `*.log`, `*.pid`, `*.pem`, `*.key`, `composer.json`, `composer.lock`, `package.json` are automatically blocked (403 Forbidden).
- **Process isolation**: Each server runs as a separate PHP process with no access to Coqui internals.
- **File size limit**: Files larger than 50MB return 413 Content Too Large.
- **Security headers**: `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `X-XSS-Protection` are set on all responses.

## Architecture

```
WebServerToolkit (ToolkitInterface)
    |
    +-- WebServerTool (server lifecycle: start/stop/restart/status/list)
    |       |
    |       +-- WebServerRunner (process management: proc_open, PID files, SIGTERM/SIGKILL)
    |
    +-- WebServerLogTool (log queries: tail/search/stats/clear)
            |
            +-- WebServerLogStore (SQLite request log: read/write/aggregate)

Router Script (src/Resources/router.php)
    - Runs inside the separate `php -S` process
    - Handles static files, PHP execution, gzip, rewrites, directory listings
    - Writes to SQLite log database directly
    - No access to Coqui internals
```

## Workspace Files

At runtime, server data is stored in `.workspace/webserver/`:

```
.workspace/webserver/
    {name}.pid              # PID of running server process
    {name}.json             # Server metadata (port, host, docroot, options, started_at)
    {name}-router.php       # Copy of the router script with env-based config
    {name}-rewrites.json    # Rewrite rules (if configured)
    {name}.db               # SQLite request log database
    {name}.log              # Server stdout/stderr output
```

## Standalone Usage

```php
<?php

declare(strict_types=1);

use CoquiBot\Toolkits\Webserver\WebServerToolkit;

require __DIR__ . '/vendor/autoload.php';

$toolkit = WebServerToolkit::fromEnv();

foreach ($toolkit->tools() as $tool) {
    echo $tool->name() . ': ' . $tool->description() . PHP_EOL;
}

// Start a server
$server = $toolkit->tools()[0];
$result = $server->execute([
    'action' => 'start',
    'docroot' => 'public',
    'port' => 8080,
]);
echo $result->content;
```

## Development

```bash
git clone https://github.com/AgentCoqui/coqui-toolkit-webserver.git
cd coqui-toolkit-webserver
composer install
```

### Run tests

```bash
./vendor/bin/pest
```

### Static analysis

```bash
./vendor/bin/phpstan analyse
```

## License

MIT
