<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Jobs\ExecuteActionRequest;
use App\Jobs\ExecuteDebouncedLibraryScan;
use App\Models\ActionRequest;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake([ExecuteActionRequest::class]);
});

function debouncedScanRequest(array $overrides = []): ActionRequest
{
    return ActionRequest::factory()->autoExecute()->create([
        'type' => 'emby_library_scan',
        'source_service' => 'sonarr',
        'target_service' => 'emby',
        'payload' => ['trigger' => 'sonarr_download', 'scan_after' => now()->subSecond()->toIso8601String()],
        ...$overrides,
    ]);
}

test('it waits while imports keep arriving', function (): void {
    $actionRequest = debouncedScanRequest(['payload' => ['scan_after' => now()->addSeconds(30)->toIso8601String()]]);

    new ExecuteDebouncedLibraryScan($actionRequest->id)->handle();

    Queue::assertNotPushed(ExecuteActionRequest::class);
});

test('it hands off to execution once the quiet window passed', function (): void {
    $actionRequest = debouncedScanRequest();

    new ExecuteDebouncedLibraryScan($actionRequest->id)->handle();

    Queue::assertPushed(ExecuteActionRequest::class, fn (ExecuteActionRequest $job): bool => $job->actionRequest->id === $actionRequest->id);
});

test('it ignores requests that are no longer approved or no longer exist', function (): void {
    $actionRequest = debouncedScanRequest(['status' => ActionRequestStatus::Completed]);

    new ExecuteDebouncedLibraryScan($actionRequest->id)->handle();
    new ExecuteDebouncedLibraryScan(999_999)->handle();

    Queue::assertNotPushed(ExecuteActionRequest::class);
});
