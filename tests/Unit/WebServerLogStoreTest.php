<?php

declare(strict_types=1);

use CarmeloSantana\CoquiToolkitWebserver\Storage\WebServerLogStore;

test('creates table on connect', function () {
    $dbPath = sys_get_temp_dir() . '/webserver-log-test-' . uniqid() . '.db';

    $store = new WebServerLogStore($dbPath);

    // Trigger connection by calling tail
    $entries = $store->tail();
    expect($entries)->toBe([]);

    // Verify table exists
    $db = new PDO("sqlite:{$dbPath}");
    $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='request_log'");
    expect($result->fetchColumn())->toBe('request_log');

    unlink($dbPath);
});

test('log and tail round-trip', function () {
    $dbPath = sys_get_temp_dir() . '/webserver-log-test-' . uniqid() . '.db';
    $store = new WebServerLogStore($dbPath);

    $store->log('GET', '/index.html', '', 200, 1024, 5.2, 'TestAgent', '127.0.0.1');
    $store->log('POST', '/api/data', 'key=value', 201, 256, 12.5, 'TestAgent', '127.0.0.1');

    $entries = $store->tail(10);
    expect($entries)->toHaveCount(2);

    // Most recent first
    expect($entries[0]['method'])->toBe('POST');
    expect($entries[0]['path'])->toBe('/api/data');
    expect($entries[0]['status'])->toBe(201);

    expect($entries[1]['method'])->toBe('GET');
    expect($entries[1]['path'])->toBe('/index.html');

    unlink($dbPath);
});

test('tail respects limit', function () {
    $dbPath = sys_get_temp_dir() . '/webserver-log-test-' . uniqid() . '.db';
    $store = new WebServerLogStore($dbPath);

    for ($i = 0; $i < 10; $i++) {
        $store->log('GET', "/page{$i}", '', 200, 100, 1.0, '', '');
    }

    $entries = $store->tail(3);
    expect($entries)->toHaveCount(3);

    unlink($dbPath);
});

test('search filters by path', function () {
    $dbPath = sys_get_temp_dir() . '/webserver-log-test-' . uniqid() . '.db';
    $store = new WebServerLogStore($dbPath);

    $store->log('GET', '/api/users', '', 200, 100, 1.0, '', '');
    $store->log('GET', '/api/posts', '', 200, 100, 1.0, '', '');
    $store->log('GET', '/index.html', '', 200, 100, 1.0, '', '');

    $results = $store->search(['path' => '/api']);
    expect($results)->toHaveCount(2);

    unlink($dbPath);
});

test('search filters by method', function () {
    $dbPath = sys_get_temp_dir() . '/webserver-log-test-' . uniqid() . '.db';
    $store = new WebServerLogStore($dbPath);

    $store->log('GET', '/page', '', 200, 100, 1.0, '', '');
    $store->log('POST', '/form', '', 200, 100, 1.0, '', '');
    $store->log('GET', '/other', '', 200, 100, 1.0, '', '');

    $results = $store->search(['method' => 'POST']);
    expect($results)->toHaveCount(1);
    expect($results[0]['path'])->toBe('/form');

    unlink($dbPath);
});

test('search filters by status code', function () {
    $dbPath = sys_get_temp_dir() . '/webserver-log-test-' . uniqid() . '.db';
    $store = new WebServerLogStore($dbPath);

    $store->log('GET', '/found', '', 200, 100, 1.0, '', '');
    $store->log('GET', '/missing', '', 404, 0, 1.0, '', '');
    $store->log('GET', '/error', '', 500, 0, 1.0, '', '');

    $results = $store->search(['status' => 404]);
    expect($results)->toHaveCount(1);
    expect($results[0]['path'])->toBe('/missing');

    unlink($dbPath);
});

test('stats returns correct aggregates', function () {
    $dbPath = sys_get_temp_dir() . '/webserver-log-test-' . uniqid() . '.db';
    $store = new WebServerLogStore($dbPath);

    $store->log('GET', '/page1', '', 200, 1000, 5.0, '', '');
    $store->log('GET', '/page1', '', 200, 2000, 10.0, '', '');
    $store->log('POST', '/api', '', 201, 500, 15.0, '', '');
    $store->log('GET', '/missing', '', 404, 0, 2.0, '', '');

    $stats = $store->stats();

    expect($stats['total_requests'])->toBe(4);
    expect($stats['methods'])->toHaveKey('GET');
    expect($stats['methods']['GET'])->toBe(3);
    expect($stats['methods']['POST'])->toBe(1);
    expect($stats['status_codes'])->toHaveKey('200');
    expect($stats['status_codes']['200'])->toBe(2);
    expect($stats['status_codes']['404'])->toBe(1);
    expect($stats['total_bytes'])->toBe(3500);
    expect($stats['top_paths'])->not->toBeEmpty();
    expect($stats['top_paths'][0]['path'])->toBe('/page1');
    expect($stats['top_paths'][0]['count'])->toBe(2);

    unlink($dbPath);
});

test('stats returns zeroes for empty database', function () {
    $dbPath = sys_get_temp_dir() . '/webserver-log-test-' . uniqid() . '.db';
    $store = new WebServerLogStore($dbPath);

    $stats = $store->stats();

    expect($stats['total_requests'])->toBe(0);
    expect($stats['methods'])->toBe([]);
    expect($stats['status_codes'])->toBe([]);
    expect($stats['top_paths'])->toBe([]);

    unlink($dbPath);
});

test('clear removes all entries and returns count', function () {
    $dbPath = sys_get_temp_dir() . '/webserver-log-test-' . uniqid() . '.db';
    $store = new WebServerLogStore($dbPath);

    $store->log('GET', '/a', '', 200, 100, 1.0, '', '');
    $store->log('GET', '/b', '', 200, 100, 1.0, '', '');
    $store->log('GET', '/c', '', 200, 100, 1.0, '', '');

    $count = $store->clear();
    expect($count)->toBe(3);

    $entries = $store->tail();
    expect($entries)->toBe([]);

    unlink($dbPath);
});

test('hasEntries returns false for non-existent database', function () {
    $store = new WebServerLogStore('/tmp/nonexistent-' . uniqid() . '.db');

    expect($store->hasEntries())->toBeFalse();
});

test('hasEntries returns false for empty database', function () {
    $dbPath = sys_get_temp_dir() . '/webserver-log-test-' . uniqid() . '.db';
    $store = new WebServerLogStore($dbPath);

    // Create the table by calling tail
    $store->tail();

    expect($store->hasEntries())->toBeFalse();

    unlink($dbPath);
});

test('hasEntries returns true when entries exist', function () {
    $dbPath = sys_get_temp_dir() . '/webserver-log-test-' . uniqid() . '.db';
    $store = new WebServerLogStore($dbPath);

    $store->log('GET', '/', '', 200, 100, 1.0, '', '');

    expect($store->hasEntries())->toBeTrue();

    unlink($dbPath);
});
