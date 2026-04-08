<?php

declare(strict_types=1);

use CarmeloSantana\CoquiToolkitWebserver\WebServerToolkit;

test('toolkit implements ToolkitInterface', function () {
    $toolkit = new WebServerToolkit(workspacePath: sys_get_temp_dir());

    expect($toolkit)->toBeInstanceOf(\CarmeloSantana\PHPAgents\Contract\ToolkitInterface::class);
});

test('tools returns both webserver tools', function () {
    $toolkit = new WebServerToolkit(workspacePath: sys_get_temp_dir());
    $tools = $toolkit->tools();

    expect($tools)->toHaveCount(2);

    $names = array_map(fn($tool) => $tool->name(), $tools);
    expect($names)->toBe(['webserver', 'webserver_log']);
});

test('each tool implements ToolInterface', function () {
    $toolkit = new WebServerToolkit(workspacePath: sys_get_temp_dir());
    $tools = $toolkit->tools();

    foreach ($tools as $tool) {
        expect($tool)->toBeInstanceOf(\CarmeloSantana\PHPAgents\Contract\ToolInterface::class);
    }
});

test('guidelines returns non-empty string with XML tag', function () {
    $toolkit = new WebServerToolkit(workspacePath: sys_get_temp_dir());

    expect($toolkit->guidelines())
        ->toBeString()
        ->not->toBeEmpty()
        ->toContain('WEBSERVER-TOOLKIT-GUIDELINES');
});

test('fromEnv creates instance', function () {
    $toolkit = WebServerToolkit::fromEnv();

    expect($toolkit)->toBeInstanceOf(WebServerToolkit::class);
});
