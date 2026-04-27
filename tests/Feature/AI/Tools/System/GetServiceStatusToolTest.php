<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\System\GetServiceStatusTool;
use App\Models\ServiceConnection;
use Laravel\Ai\Tools\Request;

test('returns the service status array as JSON', function (): void {
    ServiceConnection::factory()->sonarr()->create(['name' => 'Demo Sonarr', 'is_active' => true]);
    ServiceConnection::factory()->radarr()->create(['name' => 'Demo Radarr', 'is_active' => false]);

    $tool = new GetServiceStatusTool;

    $result = json_decode((string) $tool->handle(new Request([])), true);

    expect($result['services'])->toHaveCount(2);
    expect(collect($result['services'])->pluck('name')->all())->toContain('Demo Sonarr', 'Demo Radarr');
});

test('risk is Read', function (): void {
    expect((new GetServiceStatusTool)->risk())->toBe(Risk::Read);
});
