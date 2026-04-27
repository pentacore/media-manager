<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Sonarr\MonitorSeriesTool;
use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use Laravel\Ai\Tools\Request;

test('queues a monitor_series ActionRequest', function (): void {
    ActionTypeConfig::factory()->create([
        'type' => 'monitor_series',
        'is_enabled' => true,
        'requires_approval' => false,
    ]);

    $result = json_decode((string) (new MonitorSeriesTool)->handle(new Request([
        'series_id' => 42,
        'monitored' => false,
    ])), true);

    expect($result['queued'])->toBeTrue();
    expect($result['status'])->toBe(ActionRequestStatus::Approved->value);

    $ar = ActionRequest::firstWhere('type', 'monitor_series');
    expect($ar->target_service)->toBe('sonarr');
    expect($ar->payload)->toEqual(['series_id' => 42, 'monitored' => false]);
});

test('risk is Destructive', function (): void {
    expect((new MonitorSeriesTool)->risk())->toBe(Risk::Destructive);
});
