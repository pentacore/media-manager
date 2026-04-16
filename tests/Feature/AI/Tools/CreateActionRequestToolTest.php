<?php

declare(strict_types=1);

use App\Ai\Tools\CreateActionRequestTool;
use App\Enums\ActionRequestStatus;
use App\Events\ActionRequestCreated;
use App\Models\ActionTypeConfig;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Event::fake([ActionRequestCreated::class]);
});

test('creates an ActionRequest via the orchestrator', function (): void {
    ActionTypeConfig::factory()->create([
        'type' => 'delete_series',
        'requires_approval' => true,
        'is_enabled' => true,
    ]);

    $createActionRequestTool = resolve(CreateActionRequestTool::class);
    $response = json_decode((string) $createActionRequestTool->handle(new Request([
        'type' => 'delete_series',
        'target_service' => 'sonarr',
        'payload' => ['sonarr_series_id' => 42, 'delete_files' => true],
    ])), true);

    expect($response['created'])->toBeTrue();
    expect($response['requires_approval'])->toBeTrue();
    expect($response['status'])->toBe(ActionRequestStatus::Pending->value);

    Event::assertDispatched(ActionRequestCreated::class);
});

test('returns created=false when action type is not configured', function (): void {
    $createActionRequestTool = resolve(CreateActionRequestTool::class);
    $response = json_decode((string) $createActionRequestTool->handle(new Request([
        'type' => 'not_a_real_type',
        'target_service' => 'sonarr',
        'payload' => [],
    ])), true);

    expect($response['created'])->toBeFalse();
    expect($response['reason'])->toContain('ActionTypeConfig');
});

test('rejects missing required fields', function (): void {
    $createActionRequestTool = resolve(CreateActionRequestTool::class);
    $response = json_decode((string) $createActionRequestTool->handle(new Request([])), true);

    expect($response)->toHaveKey('error');
});

test('defaults source_service to ai', function (): void {
    ActionTypeConfig::factory()->create([
        'type' => 'emby_library_scan',
        'requires_approval' => false,
        'is_enabled' => true,
    ]);

    Queue::fake();

    $createActionRequestTool = resolve(CreateActionRequestTool::class);
    $createActionRequestTool->handle(new Request([
        'type' => 'emby_library_scan',
        'target_service' => 'emby',
        'payload' => [],
    ]));

    Event::assertDispatched(fn (ActionRequestCreated $actionRequestCreated): bool => $actionRequestCreated->actionRequest->source_service === 'ai');
});
