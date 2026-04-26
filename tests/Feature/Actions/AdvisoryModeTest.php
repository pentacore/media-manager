<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Enums\AiMode;
use App\Events\ActionRequestCreated;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionTypeConfig;
use App\Services\Actions\ActionOrchestrator;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Event::fake([ActionRequestCreated::class]);
    Queue::fake();
    Cache::flush();
});

test('advisory mode forces Pending even when ActionTypeConfig auto-approves', function (): void {
    resolve(AiSettings::class)->setMode(AiMode::Advisory);

    ActionTypeConfig::factory()->create([
        'type' => 'emby_library_scan',
        'requires_approval' => false,
        'is_enabled' => true,
    ]);

    $request = resolve(ActionOrchestrator::class)->dispatch(
        type: 'emby_library_scan',
        sourceService: 'sonarr',
        targetService: 'emby',
        payload: [],
    );

    expect($request->status)->toBe(ActionRequestStatus::Pending);
    expect($request->requires_approval)->toBeTrue();
    Queue::assertNotPushed(ExecuteActionRequest::class);
});

test('advisory mode still emits ActionRequestCreated for UI notification', function (): void {
    resolve(AiSettings::class)->setMode(AiMode::Advisory);

    ActionTypeConfig::factory()->create([
        'type' => 'delete_series',
        'requires_approval' => false,
        'is_enabled' => true,
    ]);

    resolve(ActionOrchestrator::class)->dispatch(
        type: 'delete_series',
        sourceService: 'emby',
        targetService: 'sonarr',
        payload: ['sonarr_series_id' => 1],
    );

    Event::assertDispatched(ActionRequestCreated::class);
});

test('executive mode preserves auto-approve behavior', function (): void {
    resolve(AiSettings::class)->setMode(AiMode::Executive);

    ActionTypeConfig::factory()->create([
        'type' => 'emby_library_scan',
        'requires_approval' => false,
        'is_enabled' => true,
    ]);

    $request = resolve(ActionOrchestrator::class)->dispatch(
        type: 'emby_library_scan',
        sourceService: 'sonarr',
        targetService: 'emby',
        payload: [],
    );

    expect($request->status)->toBe(ActionRequestStatus::Approved);
    Queue::assertPushed(ExecuteActionRequest::class);
});
