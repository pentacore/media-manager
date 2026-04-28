<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Sonarr\AddSeriesTool;
use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use Laravel\Ai\Tools\Request;

test('queues an add_series ActionRequest with the right payload', function (): void {
    ActionTypeConfig::factory()->create([
        'type' => 'add_series',
        'is_enabled' => true,
        'requires_approval' => true,
    ]);

    $result = json_decode((new AddSeriesTool)->handle(new Request([
        'tvdb_id' => 999001,
        'quality_profile_id' => 1,
        'root_folder_path' => '/tv',
        'monitored' => true,
        'season_folder' => true,
    ])), true);

    expect($result['queued'])->toBeTrue();
    expect($result['status'])->toBe(ActionRequestStatus::Pending->value);

    $ar = ActionRequest::firstWhere('type', 'add_series');
    expect($ar->target_service)->toBe('sonarr');
    expect($ar->payload['tvdb_id'])->toBe(999001);
    expect($ar->payload['monitored'])->toBeTrue();
});

test('risk is Destructive', function (): void {
    expect((new AddSeriesTool)->risk())->toBe(Risk::Destructive);
});
