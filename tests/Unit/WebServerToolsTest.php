<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\CoquiToolkitWebserver\WebServerTool;
use CarmeloSantana\CoquiToolkitWebserver\WebServerLogTool;
use CarmeloSantana\CoquiToolkitWebserver\Runtime\WebServerRunner;

test('WebServerTool has correct name and description', function () {
    $runner = new WebServerRunner(workspacePath: sys_get_temp_dir());
    $tool = new WebServerTool($runner);

    expect($tool->name())->toBe('webserver');
    expect($tool->description())->toBeString()->not->toBeEmpty();
});

test('WebServerLogTool has correct name', function () {
    $runner = new WebServerRunner(workspacePath: sys_get_temp_dir());
    $tool = new WebServerLogTool($runner);

    expect($tool->name())->toBe('webserver_log');
    expect($tool->description())->toBeString()->not->toBeEmpty();
});

test('all tools produce valid function schemas', function () {
    $runner = new WebServerRunner(workspacePath: sys_get_temp_dir());

    $tools = [
        new WebServerTool($runner),
        new WebServerLogTool($runner),
    ];

    foreach ($tools as $tool) {
        $schema = $tool->toFunctionSchema();

        expect($schema)->toHaveKey('type');
        expect($schema['type'])->toBe('function');
        expect($schema)->toHaveKey('function');
        expect($schema['function'])->toHaveKey('name');
        expect($schema['function'])->toHaveKey('description');
        expect($schema['function'])->toHaveKey('parameters');
        expect($schema['function']['parameters'])->toHaveKey('properties');
        expect($schema['function']['parameters']['properties'])->toHaveKey('action');
    }
});

test('WebServerTool schema includes all actions', function () {
    $runner = new WebServerRunner(workspacePath: sys_get_temp_dir());
    $tool = new WebServerTool($runner);
    $schema = $tool->toFunctionSchema();

    $actions = $schema['function']['parameters']['properties']['action']['enum'];
    expect($actions)->toContain('start', 'stop', 'restart', 'status', 'list', 'stop_all');
});

test('WebServerLogTool schema includes all actions', function () {
    $runner = new WebServerRunner(workspacePath: sys_get_temp_dir());
    $tool = new WebServerLogTool($runner);
    $schema = $tool->toFunctionSchema();

    $actions = $schema['function']['parameters']['properties']['action']['enum'];
    expect($actions)->toContain('tail', 'search', 'stats', 'clear');
});

test('WebServerTool returns error for unknown action', function () {
    $runner = new WebServerRunner(workspacePath: sys_get_temp_dir());
    $tool = new WebServerTool($runner);

    $result = $tool->execute(['action' => 'nonexistent_action']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

test('WebServerLogTool returns error for unknown action', function () {
    $runner = new WebServerRunner(workspacePath: sys_get_temp_dir());
    $tool = new WebServerLogTool($runner);

    $result = $tool->execute(['action' => 'nonexistent_action']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

test('WebServerTool start requires docroot', function () {
    $runner = new WebServerRunner(workspacePath: sys_get_temp_dir());
    $tool = new WebServerTool($runner);

    $result = $tool->execute(['action' => 'start']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('docroot');
});

test('WebServerTool list returns success when no servers', function () {
    $runner = new WebServerRunner(workspacePath: sys_get_temp_dir() . '/webserver-test-' . uniqid());
    $tool = new WebServerTool($runner);

    $result = $tool->execute(['action' => 'list']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('No managed servers');
});

test('WebServerLogTool tail returns success when no log database', function () {
    $runner = new WebServerRunner(workspacePath: sys_get_temp_dir() . '/webserver-test-' . uniqid());
    $tool = new WebServerLogTool($runner);

    $result = $tool->execute(['action' => 'tail']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('No log database');
});
