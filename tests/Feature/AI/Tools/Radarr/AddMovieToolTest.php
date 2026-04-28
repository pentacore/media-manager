<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Radarr\AddMovieTool;
use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use Laravel\Ai\Tools\Request;

test('queues an add_movie ActionRequest with the right payload', function (): void {
    ActionTypeConfig::factory()->create([
        'type' => 'add_movie',
        'is_enabled' => true,
        'requires_approval' => true,
    ]);

    $result = json_decode((new AddMovieTool)->handle(new Request([
        'tmdb_id' => 999001,
        'quality_profile_id' => 1,
        'root_folder_path' => '/movies',
        'monitored' => true,
    ])), true);

    expect($result['queued'])->toBeTrue();
    expect($result['status'])->toBe(ActionRequestStatus::Pending->value);

    $ar = ActionRequest::firstWhere('type', 'add_movie');
    expect($ar->target_service)->toBe('radarr');
    expect($ar->payload['tmdb_id'])->toBe(999001);
    expect($ar->payload['monitored'])->toBeTrue();
});

test('risk is Destructive', function (): void {
    expect((new AddMovieTool)->risk())->toBe(Risk::Destructive);
});
