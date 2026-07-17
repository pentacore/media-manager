<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Events\ActionRequestStatusChanged;
use App\Models\ActionRequest;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Event::fake([ActionRequestStatusChanged::class]);
});

test('an action request stuck in executing past the threshold is failed as worker_lost', function (): void {
    $actionRequest = ActionRequest::factory()->create(['status' => ActionRequestStatus::Executing]);
    ActionRequest::query()->whereKey($actionRequest->id)->update(['updated_at' => now()->subHours(5)]);

    $this->artisan('actions:reconcile-stuck')->assertSuccessful();

    $fresh = $actionRequest->fresh();
    expect($fresh->status)->toBe(ActionRequestStatus::Failed)
        ->and($fresh->result['reason'])->toBe('worker_lost');

    Event::assertDispatched(ActionRequestStatusChanged::class);
});

test('a recently-updated executing request is left alone', function (): void {
    $actionRequest = ActionRequest::factory()->create(['status' => ActionRequestStatus::Executing]);

    $this->artisan('actions:reconcile-stuck')->assertSuccessful();

    expect($actionRequest->fresh()->status)->toBe(ActionRequestStatus::Executing);
    Event::assertNotDispatched(ActionRequestStatusChanged::class);
});

test('terminal requests are never touched', function (): void {
    $completed = ActionRequest::factory()->create(['status' => ActionRequestStatus::Completed]);
    ActionRequest::query()->whereKey($completed->id)->update(['updated_at' => now()->subDays(2)]);

    $this->artisan('actions:reconcile-stuck')->assertSuccessful();

    expect($completed->fresh()->status)->toBe(ActionRequestStatus::Completed);
});
