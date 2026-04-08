<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitWebserver;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\CoquiToolkitWebserver\Runtime\WebServerRunner;

/**
 * Web server toolkit for Coqui.
 *
 * Provides tools to start, stop, and manage PHP built-in web servers that serve
 * workspace files over HTTP. Each server runs as a separate process with its own
 * configuration, request logging, and lifecycle management.
 *
 * Auto-discovered by Coqui's ToolkitDiscovery when installed via Composer.
 * No API keys or external dependencies required.
 */
final class WebServerToolkit implements ToolkitInterface
{
    private readonly WebServerRunner $runner;

    public function __construct(
        string $workspacePath,
        ?WebServerRunner $runner = null,
    ) {
        $this->runner = $runner ?? new WebServerRunner($workspacePath);
    }

    /**
     * Factory method for ToolkitDiscovery — reads workspace path from environment.
     */
    public static function fromEnv(): self
    {
        $workspacePath = getenv('COQUI_WORKSPACE_PATH');
        if ($workspacePath === false || $workspacePath === '') {
            $workspacePath = getcwd() . '/.workspace';
        }

        return new self(workspacePath: $workspacePath);
    }

    public function tools(): array
    {
        return [
            new WebServerTool($this->runner),
            new WebServerLogTool($this->runner),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
            <WEBSERVER-TOOLKIT-GUIDELINES>
            ## Web Server for Workspace Files

            You can serve workspace files over HTTP using two tools:

            ### Workflow
            1. **Create content**: Write HTML/CSS/JS files to a workspace directory
            2. **Start server**: `webserver` action `start` with `docroot` set to that directory
            3. **Access**: Open the URL returned by the start action in a browser
            4. **Monitor**: `webserver_log` to check incoming requests and debug issues
            5. **Stop**: `webserver` action `stop` when done

            ### `webserver` Tool — Server Lifecycle
            - **start**: Serve a workspace directory. Specify `docroot` (required, relative to workspace).
              Optional: `port`, `host`, `gzip`, `directory_listing`, `php_enabled`, `rewrites`.
            - **stop**: Stop a running server by `name` (default server if not specified).
            - **restart**: Restart with the same config (useful after changing files or options).
            - **status**: Get server details — URL, port, docroot, uptime, features.
            - **list**: Show all managed servers.
            - **stop_all**: Stop every running server.

            ### `webserver_log` Tool — Request Monitoring
            - **tail**: Last N requests (default 20). Good for checking recent traffic.
            - **search**: Filter by `path`, `method`, or `status` code.
            - **stats**: Aggregate stats — total requests, top paths, status distribution, avg response time.
            - **clear**: Delete all log entries.

            ### Key Features
            - **Gzip**: Enabled by default for text-based content (HTML, CSS, JS, JSON, SVG).
            - **PHP execution**: Enabled by default. The server can execute .php files in the docroot.
            - **Directory listing**: Disabled by default. Enable with `directory_listing: true`.
            - **URL rewrites**: Pass a `rewrites` object with regex patterns. Useful for SPA fallback:
              `{"^(?!/api).*$": "/index.html"}`.
            - **Request logging**: Every request is logged to SQLite for querying via `webserver_log`.
            - **Multiple servers**: Use different `name` values to run several servers concurrently.

            ### Security
            - Servers bind to `127.0.0.1` by default (localhost only).
            - Only workspace files are accessible — path traversal is blocked.
            - Sensitive files (.env, .git, .db, vendor/, node_modules/) are automatically blocked.
            - Each server runs as a separate process with no access to Coqui internals.

            ### When to Use
            - Previewing generated HTML/CSS/JS sites
            - Testing PHP scripts in the workspace
            - Serving API mock endpoints
            - Sharing files locally via HTTP
            - Creating simple web interfaces for bot functionality

            ### When NOT to Use
            - Production deployments (this is a development server)
            - Serving files outside the workspace
            - High-concurrency workloads
            </WEBSERVER-TOOLKIT-GUIDELINES>
            GUIDELINES;
    }
}
