<?php

declare(strict_types=1);

use CarmeloSantana\CoquiToolkitWebserver\Runtime\WebServerRunner;

test('defaultName generates deterministic hash', function () {
    $runner = new WebServerRunner(workspacePath: '/tmp/test-workspace');

    $name = $runner->defaultName();

    expect($name)->toStartWith('coqui-');
    expect(strlen($name))->toBe(14); // 'coqui-' + 8 chars
});

test('defaultName is consistent for same workspace', function () {
    $runner1 = new WebServerRunner(workspacePath: '/tmp/test-workspace');
    $runner2 = new WebServerRunner(workspacePath: '/tmp/test-workspace');

    expect($runner1->defaultName())->toBe($runner2->defaultName());
});

test('defaultName differs for different workspaces', function () {
    $runner1 = new WebServerRunner(workspacePath: '/tmp/workspace-a');
    $runner2 = new WebServerRunner(workspacePath: '/tmp/workspace-b');

    expect($runner1->defaultName())->not->toBe($runner2->defaultName());
});

test('custom default name overrides hash', function () {
    $runner = new WebServerRunner(
        workspacePath: '/tmp/test-workspace',
        defaultName: 'my-server',
    );

    expect($runner->defaultName())->toBe('my-server');
});

test('serverDir is within workspace', function () {
    $runner = new WebServerRunner(workspacePath: '/tmp/test-workspace');

    expect($runner->serverDir())->toBe('/tmp/test-workspace/webserver');
});

test('serverDir strips trailing slash', function () {
    $runner = new WebServerRunner(workspacePath: '/tmp/test-workspace/');

    expect($runner->serverDir())->toBe('/tmp/test-workspace/webserver');
});

test('logDbPath uses server name', function () {
    $runner = new WebServerRunner(workspacePath: '/tmp/test-workspace');

    expect($runner->logDbPath('my-server'))->toBe('/tmp/test-workspace/webserver/my-server.db');
});

test('logDbPath uses default name when empty', function () {
    $runner = new WebServerRunner(workspacePath: '/tmp/test-workspace');

    $defaultName = $runner->defaultName();
    expect($runner->logDbPath())->toBe("/tmp/test-workspace/webserver/{$defaultName}.db");
});

test('resolveDocroot blocks path traversal', function () {
    $tmpDir = sys_get_temp_dir() . '/webserver-test-' . uniqid();
    mkdir($tmpDir, 0755, true);

    $runner = new WebServerRunner(workspacePath: $tmpDir);

    // Attempt to escape via ..
    expect($runner->resolveDocroot('../../../etc'))->toBeNull();

    // Absolute paths get stripped to relative — /etc/passwd becomes etc/passwd
    // Parent dir doesn't exist inside workspace, so it's rejected
    expect($runner->resolveDocroot('/etc/passwd'))->toBeNull();

    rmdir($tmpDir);
});

test('resolveDocroot allows valid subdirectory', function () {
    $tmpDir = sys_get_temp_dir() . '/webserver-test-' . uniqid();
    $subDir = $tmpDir . '/public';
    mkdir($subDir, 0755, true);

    $runner = new WebServerRunner(workspacePath: $tmpDir);

    $resolved = $runner->resolveDocroot('public');
    expect($resolved)->toBe(realpath($subDir));

    rmdir($subDir);
    rmdir($tmpDir);
});

test('resolveDocroot allows non-existent subdirectory', function () {
    $tmpDir = sys_get_temp_dir() . '/webserver-test-' . uniqid();
    mkdir($tmpDir, 0755, true);

    $runner = new WebServerRunner(workspacePath: $tmpDir);

    $resolved = $runner->resolveDocroot('new-dir');
    expect($resolved)->toBe($tmpDir . '/new-dir');

    rmdir($tmpDir);
});

test('isPortAvailable returns true for unused port', function () {
    $runner = new WebServerRunner(workspacePath: '/tmp/test-workspace');

    // Use a high port that's very unlikely to be in use
    expect($runner->isPortAvailable('127.0.0.1', 59123))->toBeTrue();
});

test('findAvailablePort returns a port number', function () {
    $runner = new WebServerRunner(workspacePath: '/tmp/test-workspace');

    $port = $runner->findAvailablePort(59100);
    expect($port)->toBeInt();
    expect($port)->toBeGreaterThanOrEqual(59100);
});

test('status returns not running for unknown server', function () {
    $runner = new WebServerRunner(workspacePath: sys_get_temp_dir() . '/webserver-test-' . uniqid());

    $status = $runner->status('nonexistent');

    expect($status['running'])->toBeFalse();
    expect($status['name'])->toBe('nonexistent');
});

test('listServers returns empty array when no servers', function () {
    $runner = new WebServerRunner(workspacePath: sys_get_temp_dir() . '/webserver-test-' . uniqid());

    expect($runner->listServers())->toBe([]);
});

test('stop returns failure for unknown server', function () {
    $runner = new WebServerRunner(workspacePath: sys_get_temp_dir() . '/webserver-test-' . uniqid());

    $result = $runner->stop('nonexistent');

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toContain('No server found');
});

test('start requires docroot', function () {
    $runner = new WebServerRunner(workspacePath: sys_get_temp_dir());

    $result = $runner->start(['docroot' => '']);

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toContain('Docroot is required');
});

test('start rejects path traversal in docroot', function () {
    $runner = new WebServerRunner(workspacePath: sys_get_temp_dir());

    $result = $runner->start(['docroot' => '../../../etc']);

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toContain('Invalid docroot');
});
