<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Radarr\MonitorMovieTool;
use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use Laravel\Ai\Tools\Request;

test('queues a monitor_movie ActionRequest', function (): void {
    ActionTypeConfig::factory()->create([
        'type' => 'monitor_movie',
        'is_enabled' => true,
        'requires_approval' => false,
    ]);

    $result = json_decode((string) (new MonitorMovieTool)->handle(new Request([
        'movie_id' => 42,
        'monitored' => false,
    ])), true);

    expect($result['queued'])->toBeTrue();
    expect($result['status'])->toBe(ActionRequestStatus::Approved->value);

    $ar = ActionRequest::firstWhere('type', 'monitor_movie');
    expect($ar->target_service)->toBe('radarr');
    expect($ar->payload)->toEqual(['movie_id' => 42, 'monitored' => false]);
});

test('risk is Destructive', function (): void {
    expect((new MonitorMovieTool)->risk())->toBe(Risk::Destructive);
});
