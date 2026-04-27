<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Radarr\DeleteMovieTool;
use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use Laravel\Ai\Tools\Request;

test('queues a delete_movie ActionRequest with the right payload', function (): void {
    ActionTypeConfig::factory()->create([
        'type' => 'delete_movie',
        'is_enabled' => true,
        'requires_approval' => true,
    ]);

    $result = json_decode((string) (new DeleteMovieTool)->handle(new Request([
        'movie_id' => 42,
        'delete_files' => true,
    ])), true);

    expect($result['queued'])->toBeTrue();
    expect($result['status'])->toBe(ActionRequestStatus::Pending->value);
    expect($result['requires_approval'])->toBeTrue();

    $ar = ActionRequest::firstWhere('type', 'delete_movie');
    expect($ar->target_service)->toBe('radarr');
    expect($ar->payload)->toEqual(['radarr_movie_id' => 42, 'delete_files' => true]);
});

test('reports no_action_type_config when delete_movie rule is missing', function (): void {
    $result = json_decode((string) (new DeleteMovieTool)->handle(new Request([
        'movie_id' => 42,
        'delete_files' => true,
    ])), true);

    expect($result['queued'])->toBeFalse();
    expect($result['reason'])->toBe('no_action_type_config');
});

test('risk is Destructive', function (): void {
    expect((new DeleteMovieTool)->risk())->toBe(Risk::Destructive);
});
