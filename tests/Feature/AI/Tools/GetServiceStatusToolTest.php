<?php

declare(strict_types=1);

use App\Ai\Tools\GetServiceStatusTool;
use App\Enums\HealthStatus;
use App\Models\ServiceConnection;
use Laravel\Ai\Tools\Request;

test('returns all configured service connections', function (): void {
    ServiceConnection::factory()->sonarr()->create([
        'health_status' => HealthStatus::Healthy,
        'version' => '4.0.0',
        'latest_version' => '4.0.1',
    ]);
    ServiceConnection::factory()->emby()->create([
        'health_status' => HealthStatus::Unhealthy,
        'version' => '4.8.0',
    ]);

    $result = json_decode((string) (new GetServiceStatusTool)->handle(new Request([])), true);

    expect($result['services'])->toHaveCount(2);
    $sonarr = collect($result['services'])->firstWhere('type', 'sonarr');
    expect($sonarr['health_status'])->toBe('healthy');
    expect($sonarr['update_available'])->toBeTrue();

    $emby = collect($result['services'])->firstWhere('type', 'emby');
    expect($emby['health_status'])->toBe('unhealthy');
    expect($emby['update_available'])->toBeFalse();
});

test('returns empty services array when none configured', function (): void {
    $result = json_decode((string) (new GetServiceStatusTool)->handle(new Request([])), true);
    expect($result['services'])->toBe([]);
});

test('null health_status defaults to unknown', function (): void {
    ServiceConnection::factory()->seerr()->create(['health_status' => null]);

    $result = json_decode((string) (new GetServiceStatusTool)->handle(new Request([])), true);

    expect($result['services'][0]['health_status'])->toBe('unknown');
});
