<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Events\ActionRequestCreated;
use App\Jobs\ExecuteActionRequest;
use App\Services\Actions\ActionOrchestrator;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Event::fake([ActionRequestCreated::class]);
    Queue::fake();
    $this->seed(ActionTypeConfigSeeder::class);
});

test('dispatch with forceRequiresApproval true forces pending even when config auto-approves', function (): void {
    // resolve_manual_import is seeded; force its config to NOT require approval
    DB::table('action_type_configs')
        ->where('type', 'resolve_manual_import')
        ->update(['requires_approval' => false, 'is_enabled' => true]);

    $actionRequest = resolve(ActionOrchestrator::class)->dispatch(
        type: 'resolve_manual_import',
        sourceService: 'ai',
        targetService: 'sonarr',
        payload: ['service' => 'sonarr', 'download_id' => 'abc'],
        forceRequiresApproval: true,
    );

    expect($actionRequest)->not->toBeNull()
        ->and($actionRequest->requires_approval)->toBeTrue()
        ->and($actionRequest->status)->toBe(ActionRequestStatus::Pending);

    Queue::assertNotPushed(ExecuteActionRequest::class);
});

test('dispatch with forceRequiresApproval null keeps config behaviour', function (): void {
    DB::table('action_type_configs')
        ->where('type', 'resolve_manual_import')
        ->update(['requires_approval' => false, 'is_enabled' => true]);

    $actionRequest = resolve(ActionOrchestrator::class)->dispatch(
        type: 'resolve_manual_import',
        sourceService: 'ai',
        targetService: 'sonarr',
        payload: ['service' => 'sonarr', 'download_id' => 'abc'],
    );

    expect($actionRequest->requires_approval)->toBeFalse();
});
