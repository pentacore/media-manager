<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Sonarr\DeleteSeriesTool;
use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use Laravel\Ai\Tools\Request;

test('queues a delete_series ActionRequest with the right payload', function (): void {
    ActionTypeConfig::factory()->create([
        'type' => 'delete_series',
        'is_enabled' => true,
        'requires_approval' => true,
    ]);

    $result = json_decode((string) (new DeleteSeriesTool)->handle(new Request([
        'series_id' => 42,
        'delete_files' => true,
    ])), true);

    expect($result['queued'])->toBeTrue();
    expect($result['status'])->toBe(ActionRequestStatus::Pending->value);
    expect($result['requires_approval'])->toBeTrue();

    $ar = ActionRequest::firstWhere('type', 'delete_series');
    expect($ar->target_service)->toBe('sonarr');
    expect($ar->payload)->toEqual(['sonarr_series_id' => 42, 'delete_files' => true]);
});

test('reports no_action_type_config when delete_series rule is missing', function (): void {
    $result = json_decode((string) (new DeleteSeriesTool)->handle(new Request([
        'series_id' => 42,
        'delete_files' => true,
    ])), true);

    expect($result['queued'])->toBeFalse();
    expect($result['reason'])->toBe('no_action_type_config');
});

test('risk is Destructive', function (): void {
    expect((new DeleteSeriesTool)->risk())->toBe(Risk::Destructive);
});
