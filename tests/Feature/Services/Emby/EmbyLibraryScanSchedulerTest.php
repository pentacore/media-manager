<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Jobs\ExecuteActionRequest;
use App\Jobs\ExecuteDebouncedLibraryScan;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\Actions\ActionDescriber;
use App\Services\Emby\EmbyLibraryScanScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    $this->sonarr = ServiceConnection::factory()->sonarr()->create();
});

function scanSchedulerConfig(bool $requiresApproval = false): void
{
    ActionTypeConfig::factory()->create([
        'type' => 'emby_library_scan',
        'requires_approval' => $requiresApproval,
        'is_enabled' => true,
    ]);
}

function scheduleImportScan(ServiceConnection $sonarr, string $trigger = 'sonarr_download'): ?ActionRequest
{
    $scanPayload = ['trigger' => $trigger, 'series_title' => 'Show'];

    return resolve(EmbyLibraryScanScheduler::class)->schedule(
        sourceService: 'sonarr',
        scanPayload: $scanPayload,
        description: resolve(ActionDescriber::class)->describe('emby_library_scan', $scanPayload)->because('Sonarr imported "Show".'),
        webhookEvent: WebhookEvent::factory()->create(['service_connection_id' => $sonarr->id]),
    );
}

test('a burst of imports yields one scan request that runs after the burst', function (): void {
    scanSchedulerConfig();
    $emby = ServiceConnection::factory()->emby()->create();

    foreach (range(1, 3) as $episode) {
        scheduleImportScan($this->sonarr);
        $this->travel(10)->seconds();
    }

    $scan = ActionRequest::query()->where('type', 'emby_library_scan')->sole();

    expect($scan->status)->toBe(ActionRequestStatus::Approved)
        ->and($scan->payload['emby_connection_id'])->toBe($emby->id)
        ->and($scan->payload['coalesced_events'])->toBe(3)
        ->and($scan->payload['triggers'])->toBe(['sonarr_download', 'sonarr_download', 'sonarr_download'])
        ->and(CarbonImmutable::parse($scan->payload['scan_after'])->greaterThan(now()->addSeconds(EmbyLibraryScanScheduler::DEBOUNCE_SECONDS - 12)))->toBeTrue();

    Queue::assertNotPushed(ExecuteActionRequest::class);
    Queue::assertPushed(ExecuteDebouncedLibraryScan::class, 3);
});

test('an import after the scan started gets its own scan request', function (): void {
    scanSchedulerConfig();
    ServiceConnection::factory()->emby()->create();

    $first = scheduleImportScan($this->sonarr);
    $first->update(['status' => ActionRequestStatus::Executing]);
    scheduleImportScan($this->sonarr);

    expect(ActionRequest::query()->where('type', 'emby_library_scan')->count())->toBe(2);
});

test('imports spaced beyond the coalescing window get separate scans', function (): void {
    scanSchedulerConfig();
    ServiceConnection::factory()->emby()->create();

    scheduleImportScan($this->sonarr);
    $this->travel(EmbyLibraryScanScheduler::MAX_COALESCE_MINUTES + 1)->minutes();
    scheduleImportScan($this->sonarr);

    expect(ActionRequest::query()->where('type', 'emby_library_scan')->count())->toBe(2);
});

test('a burst that needs approval coalesces into one pending request', function (): void {
    scanSchedulerConfig(requiresApproval: true);
    ServiceConnection::factory()->emby()->create();

    foreach (range(1, 3) as $episode) {
        scheduleImportScan($this->sonarr);
    }

    $scan = ActionRequest::query()->where('type', 'emby_library_scan')->sole();
    expect($scan->status)->toBe(ActionRequestStatus::Pending)
        ->and($scan->payload['coalesced_events'])->toBe(3);
    Queue::assertNotPushed(ExecuteDebouncedLibraryScan::class);
});

test('without an active emby connection each import falls back to a plain scan request', function (): void {
    scanSchedulerConfig();

    scheduleImportScan($this->sonarr);

    $scan = ActionRequest::query()->where('type', 'emby_library_scan')->sole();
    expect($scan->payload)->not->toHaveKey('emby_connection_id');
    Queue::assertPushed(ExecuteActionRequest::class, 1);
    Queue::assertNotPushed(ExecuteDebouncedLibraryScan::class);
});

test('the trigger list is capped', function (): void {
    scanSchedulerConfig();
    ServiceConnection::factory()->emby()->create();

    foreach (range(1, EmbyLibraryScanScheduler::MAX_RECORDED_TRIGGERS + 5) as $episode) {
        scheduleImportScan($this->sonarr);
    }

    $scan = ActionRequest::query()->where('type', 'emby_library_scan')->sole();
    expect($scan->payload['triggers'])->toHaveCount(EmbyLibraryScanScheduler::MAX_RECORDED_TRIGGERS)
        ->and($scan->payload['coalesced_events'])->toBe(EmbyLibraryScanScheduler::MAX_RECORDED_TRIGGERS + 5);
});
