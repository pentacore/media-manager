<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Radarr\SetMovieQualityProfileTool;
use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use Laravel\Ai\Tools\Request;

test('queues a set_movie_quality_profile ActionRequest', function (): void {
    ActionTypeConfig::factory()->create([
        'type' => 'set_movie_quality_profile',
        'is_enabled' => true,
        'requires_approval' => false,
    ]);

    $result = json_decode((string) (new SetMovieQualityProfileTool)->handle(new Request([
        'movie_id' => 42,
        'quality_profile_id' => 7,
    ])), true);

    expect($result['queued'])->toBeTrue();
    expect($result['status'])->toBe(ActionRequestStatus::Approved->value);

    $ar = ActionRequest::firstWhere('type', 'set_movie_quality_profile');
    expect($ar->payload)->toEqual(['movie_id' => 42, 'quality_profile_id' => 7]);
});

test('risk is Destructive', function (): void {
    expect((new SetMovieQualityProfileTool)->risk())->toBe(Risk::Destructive);
});
