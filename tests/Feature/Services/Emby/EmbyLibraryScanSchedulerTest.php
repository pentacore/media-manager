<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Jobs\ExecuteActionRequest;
use App\Jobs\ExecuteDebouncedLibraryScan;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Models\WebhookEvent;
use App\Services\Actions\ActionDescriber;
use App\Services\Actions\ManualActionOutcome;
use App\Services\Emby\EmbyLibraryScanScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
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

    $actionRequest = ActionRequest::query()->where('type', 'emby_library_scan')->sole();

    expect($actionRequest->status)->toBe(ActionRequestStatus::Approved)
        ->and($actionRequest->payload['emby_connection_id'])->toBe($emby->id)
        ->and($actionRequest->payload['coalesced_events'])->toBe(3)
        ->and($actionRequest->payload['triggers'])->toBe(['sonarr_download', 'sonarr_download', 'sonarr_download'])
        ->and(CarbonImmutable::parse($actionRequest->payload['scan_after'])->greaterThan(now()->addSeconds(EmbyLibraryScanScheduler::DEBOUNCE_SECONDS - 12)))->toBeTrue();

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

    $actionRequest = ActionRequest::query()->where('type', 'emby_library_scan')->sole();
    expect($actionRequest->status)->toBe(ActionRequestStatus::Pending)
        ->and($actionRequest->payload['coalesced_events'])->toBe(3);
    Queue::assertNotPushed(ExecuteDebouncedLibraryScan::class);
});

test('without an active emby connection each import falls back to a plain scan request', function (): void {
    scanSchedulerConfig();

    scheduleImportScan($this->sonarr);

    $actionRequest = ActionRequest::query()->where('type', 'emby_library_scan')->sole();
    expect($actionRequest->payload)->not->toHaveKey('emby_connection_id');
    Queue::assertPushed(ExecuteActionRequest::class, 1);
    Queue::assertNotPushed(ExecuteDebouncedLibraryScan::class);
});

test('the trigger list is capped', function (): void {
    scanSchedulerConfig();
    ServiceConnection::factory()->emby()->create();

    foreach (range(1, EmbyLibraryScanScheduler::MAX_RECORDED_TRIGGERS + 5) as $episode) {
        scheduleImportScan($this->sonarr);
    }

    $actionRequest = ActionRequest::query()->where('type', 'emby_library_scan')->sole();
    expect($actionRequest->payload['triggers'])->toHaveCount(EmbyLibraryScanScheduler::MAX_RECORDED_TRIGGERS)
        ->and($actionRequest->payload['coalesced_events'])->toBe(EmbyLibraryScanScheduler::MAX_RECORDED_TRIGGERS + 5);
});

test('every trigger folded into a waiting scan is logged on that request', function (): void {
    scanSchedulerConfig();
    ServiceConnection::factory()->emby()->create();

    $actionRequest = scheduleImportScan($this->sonarr);
    scheduleImportScan($this->sonarr, 'sonarr_upgrade');
    scheduleImportScan($this->sonarr, 'radarr_download');

    $rows = ActivityLog::query()
        ->where('subject_id', $actionRequest->id)
        ->where('action', 'action_request.coalesced')
        ->orderBy('id')
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->metadata)->toMatchArray(['type' => 'emby_library_scan', 'trigger' => 'sonarr_upgrade', 'coalesced_events' => 2])
        ->and($rows[1]->metadata)->toMatchArray(['trigger' => 'radarr_download', 'coalesced_events' => 3])
        ->and($rows[1]->description)->toBe(sprintf('Action #%d absorbed another trigger (radarr_download); 3 trigger(s) so far', $actionRequest->id));
});

test('a scan filed fresh writes no coalesced row', function (): void {
    scanSchedulerConfig();
    ServiceConnection::factory()->emby()->create();

    scheduleImportScan($this->sonarr);

    expect(ActivityLog::query()->where('action', 'action_request.coalesced')->exists())->toBeFalse();
});

test('a manual refresh with nothing waiting files its scan inside the scan lock', function (): void {
    scanSchedulerConfig();
    $emby = ServiceConnection::factory()->emby()->create();
    $holder = new stdClass;
    $holder->queries = [];
    DB::listen(function (QueryExecuted $queryExecuted) use ($holder): void {
        $holder->queries[] = ['sql' => $queryExecuted->sql, 'level' => $queryExecuted->connection->transactionLevel()];
    });

    $outcome = resolve(EmbyLibraryScanScheduler::class)->foldOrDispatchManual($emby->id, 'Requested by a test.');

    $queries = collect($holder->queries);
    $lockAt = $queries->search(fn (array $query): bool => str_contains($query['sql'], 'pg_advisory_xact_lock'));
    $insertAt = $queries->search(fn (array $query): bool => str_starts_with($query['sql'], 'insert into "action_requests"'));

    expect($outcome)->toBeInstanceOf(ManualActionOutcome::class)
        ->and($outcome->state)->toBe(ManualActionOutcome::STARTED)
        ->and($outcome->actionRequest?->payload)->toMatchArray(['trigger' => 'manual', 'emby_connection_id' => $emby->id, 'coalesced_events' => 1, 'triggers' => ['manual']])
        ->and($outcome->actionRequest?->origin)->toBe('manual')
        ->and($lockAt)->toBeInt()
        ->and($insertAt)->toBeInt()
        ->and($lockAt)->toBeLessThan($insertAt)
        ->and($holder->queries[$insertAt]['level'])->toBe($holder->queries[$lockAt]['level']);

    Queue::assertPushed(ExecuteActionRequest::class, 1);
});

test('a manual refresh joins a waiting scan instead of filing a second one', function (): void {
    scanSchedulerConfig();
    $emby = ServiceConnection::factory()->emby()->create();
    $waiting = scheduleImportScan($this->sonarr);

    $folded = resolve(EmbyLibraryScanScheduler::class)->foldOrDispatchManual($emby->id, 'Requested by a test.');

    expect($folded)->toBeInstanceOf(ActionRequest::class)
        ->and($folded->id)->toBe($waiting?->id)
        ->and($folded->payload['triggers'])->toBe(['sonarr_download', 'manual'])
        ->and(ActionRequest::query()->where('type', 'emby_library_scan')->count())->toBe(1);
});

test('a manual refresh with its rule disabled files nothing', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'emby_library_scan', 'requires_approval' => false, 'is_enabled' => false]);
    $emby = ServiceConnection::factory()->emby()->create();

    $outcome = resolve(EmbyLibraryScanScheduler::class)->foldOrDispatchManual($emby->id, 'Requested by a test.');

    expect($outcome)->toBeInstanceOf(ManualActionOutcome::class)
        ->and($outcome->state)->toBe(ManualActionOutcome::DISABLED)
        ->and(ActionRequest::query()->count())->toBe(0);
});
