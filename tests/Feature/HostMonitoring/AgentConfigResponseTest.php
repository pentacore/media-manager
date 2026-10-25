<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\ServerCheck;
use App\Services\HostMonitoring\AgentConfigResponse;

test('empty params encode as a json object', function (): void {
    $check = ServerCheck::factory()->mountPresent()->create(['params' => []]);

    $json = json_encode(resolve(AgentConfigResponse::class)->for($check->server, 0), JSON_THROW_ON_ERROR);

    expect($json)->toContain('"params":{}');
});

test('a server with no enabled checks sends an empty list, not null, when versions differ', function (): void {
    $server = Server::factory()->create();
    ServerCheck::factory()->for($server)->disabled()->create();

    $response = resolve(AgentConfigResponse::class)->for($server->fresh(), 0);

    expect($response['config'])->toBe(['checks' => []]);
});

test('matching versions send no config', function (): void {
    $server = Server::factory()->create();
    ServerCheck::factory()->for($server)->create();
    $server = $server->fresh();

    $response = resolve(AgentConfigResponse::class)->for($server, $server->config_version);

    expect($response['config'])->toBeNull()
        ->and($response['config_version'])->toBe($server->config_version)
        ->and($response['report_interval_seconds'])->toBe(60);
});

test('enabled checks are listed in creation order', function (): void {
    $server = Server::factory()->create();
    $first = ServerCheck::factory()->for($server)->dbPing()->create();
    $second = ServerCheck::factory()->for($server)->pathOnMount()->create();

    $response = resolve(AgentConfigResponse::class)->for($server->fresh(), 0);

    expect(array_column($response['config']['checks'] ?? [], 'id'))->toBe([$first->uuid, $second->uuid])
        ->and(array_column($response['config']['checks'] ?? [], 'type'))->toBe(['db_ping', 'path_on_mount']);
});

test('server_time is ISO-8601 UTC-offset', function (): void {
    $server = Server::factory()->create();

    $response = resolve(AgentConfigResponse::class)->for($server, 0);

    expect($response['server_time'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(Z|[+-]\d\d:\d\d)$/');
});
