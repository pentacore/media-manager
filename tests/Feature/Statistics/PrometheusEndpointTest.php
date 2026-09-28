<?php

declare(strict_types=1);

use App\Enums\HealthStatus;
use App\Enums\QueueLane;
use App\Enums\WebhookHandlingStatus;
use App\Jobs\RecordQueueHeartbeat;
use App\Models\ServiceConnection;
use App\Models\StatRollup;
use App\Models\WebhookEvent;
use App\Support\OpsHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('mediamanager.metrics.token', 'test-token');
});

it('rejects requests without the token', function (): void {
    $this->get('/metrics')->assertForbidden();
});

it('rejects requests with an incorrect token', function (): void {
    $this->withHeader('Authorization', 'Bearer wrong-token')
        ->get('/metrics')
        ->assertForbidden();
});

it('denies all access when no token is configured', function (): void {
    config()->set('mediamanager.metrics.token');

    $this->withHeader('Authorization', 'Bearer test-token')
        ->get('/metrics')
        ->assertForbidden();
});

it('serves prometheus metrics with a valid bearer token', function (): void {
    ServiceConnection::factory()->create(['is_active' => true, 'health_status' => HealthStatus::Healthy]);

    $this->withHeader('Authorization', 'Bearer test-token')
        ->get('/metrics')
        ->assertOk()
        ->assertSee('mediamanager_service_up');
});

it('rejects an array token parameter with a 403 instead of erroring', function (): void {
    $this->get('/metrics?token[]=test-token')->assertForbidden();
});

it('accepts the token via query string', function (): void {
    ServiceConnection::factory()->create(['is_active' => true, 'health_status' => HealthStatus::Healthy]);

    $this->get('/metrics?token=test-token')
        ->assertOk()
        ->assertSee('mediamanager_service_up');
});

it('exports the rollup-backed activity gauges', function (): void {
    // The "today" gauges resolve to the hour period (a sub-day window), so the
    // gauge sums the current day's hour buckets — matching what the aggregator
    // writes at runtime.
    StatRollup::factory()->hour()->create([
        'metric' => 'webhooks.received',
        'period' => 'hour',
        'bucket' => CarbonImmutable::now('UTC')->startOfHour(),
        'dimensions' => ['service' => 'sonarr'],
        'count' => 12,
    ]);

    $this->get('/metrics?token=test-token')
        ->assertOk()
        ->assertSee('mediamanager_webhooks_received_today{service="sonarr"} 12', escape: false)
        ->assertSee('mediamanager_pending_actions');
});

it('exports the newest hour-bucket sample as the mean of the window', function (): void {
    // Two accumulated samples in the same hour bucket: sum 300, count 2 -> mean 150.
    StatRollup::factory()->create([
        'metric' => 'service.disk_free_bytes',
        'period' => 'hour',
        'bucket' => CarbonImmutable::now('UTC')->startOfHour(),
        'dimensions' => ['connection' => '7', 'path' => '/data'],
        'count' => 2,
        'sum' => 300.0,
    ]);

    $this->get('/metrics?token=test-token')
        ->assertOk()
        ->assertSee('mediamanager_disk_free_bytes{connection="7",path="/data"} 150', escape: false);
});

it('drops latest-sample series whose newest bucket is stale', function (): void {
    // A dead or deleted connection must go absent from /metrics rather than
    // exporting its frozen last value for the whole hour retention.
    StatRollup::factory()->create([
        'metric' => 'queue.depth',
        'period' => 'hour',
        'bucket' => CarbonImmutable::now('UTC')->subHours(3)->startOfHour(),
        'dimensions' => ['connection' => '9', 'service' => 'sabnzbd'],
        'count' => 1,
        'sum' => 40.0,
    ]);

    $this->get('/metrics?token=test-token')
        ->assertOk()
        ->assertDontSee('mediamanager_queue_depth{service="sabnzbd"}', escape: false);
});

test('it exports failed jobs and backlog for every queue lane', function (): void {
    Queue::fake();
    dispatch(new RecordQueueHeartbeat(QueueLane::Ai));
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'webhooks',
        'payload' => '{}', 'exception' => 'RuntimeException: boom', 'failed_at' => now(),
    ]);

    $this->get('/metrics?token=test-token')
        ->assertOk()
        ->assertSee('mediamanager_failed_jobs{queue="webhooks"} 1', escape: false)
        ->assertSee('mediamanager_failed_jobs{queue="ai"} 0', escape: false)
        ->assertSee('mediamanager_failed_jobs{queue="maintenance"} 0', escape: false)
        ->assertSee('mediamanager_job_queue_size{queue="ai",state="pending"} 1', escape: false)
        ->assertSee('mediamanager_job_queue_size{queue="actions",state="pending"} 0', escape: false)
        ->assertSee('mediamanager_job_queue_size{queue="maintenance",state="pending"} 0', escape: false);
});

test('it exports heartbeat ages and omits components that never reported', function (): void {
    $this->freezeTime();
    OpsHeartbeat::record(OpsHeartbeat::SCHEDULER);
    $this->travel(42)->seconds();

    $this->get('/metrics?token=test-token')
        ->assertOk()
        ->assertSee('mediamanager_heartbeat_age_seconds{component="scheduler"} 42', escape: false)
        ->assertDontSee('mediamanager_heartbeat_age_seconds{component="queue:ai"}', escape: false);
});

test('it exports the age of the oldest webhook still waiting to be processed', function (): void {
    $this->freezeTime();
    $waiting = WebhookEvent::factory()->create();
    $inFlight = WebhookEvent::factory()->create(['handling_status' => WebhookHandlingStatus::Processing]);
    $handled = WebhookEvent::factory()->create(['processed_at' => now(), 'handling_status' => WebhookHandlingStatus::Handled]);
    $failed = WebhookEvent::factory()->create(['handling_status' => WebhookHandlingStatus::Failed]);
    WebhookEvent::query()->whereKey($waiting->id)->update(['created_at' => now()->subSeconds(120)]);
    WebhookEvent::query()->whereKey($inFlight->id)->update(['created_at' => now()->subSeconds(300)]);
    WebhookEvent::query()->whereKey([$handled->id, $failed->id])->update(['created_at' => now()->subHour()]);

    $this->get('/metrics?token=test-token')
        ->assertOk()
        ->assertSee('mediamanager_webhook_oldest_pending_age_seconds 300', escape: false);
});

test('the webhook lag gauge reads zero when nothing is waiting', function (): void {
    $this->get('/metrics?token=test-token')
        ->assertOk()
        ->assertSee('mediamanager_webhook_oldest_pending_age_seconds 0', escape: false);
});

test('the failed-jobs gauge folds an unrecognized queue name into "other" instead of minting a new label', function (): void {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'legacy-import-queue',
        'payload' => '{}', 'exception' => 'RuntimeException: boom', 'failed_at' => now(),
    ]);

    $this->get('/metrics?token=test-token')
        ->assertOk()
        ->assertSee('mediamanager_failed_jobs{queue="other"} 1', escape: false)
        ->assertDontSee('mediamanager_failed_jobs{queue="legacy-import-queue"}', escape: false);
});

test('the queue-backlog gauge failing does not take down the rest of the scrape', function (): void {
    // tests/Pest.php already runs Queue::fake() for every Feature test, so
    // reconfiguring queue.default wouldn't reach a real connection; swap in
    // a mock that throws on connection() instead, the same failure mode as
    // Redis being unreachable.
    Queue::shouldReceive('connection')->andThrow(new RuntimeException('redis unreachable'));

    $this->get('/metrics?token=test-token')
        ->assertOk()
        ->assertSee('mediamanager_ai_cost_usd_today', escape: false)
        ->assertDontSee('mediamanager_job_queue_size{queue=', escape: false);
});

test('the heartbeat gauge failing does not take down the rest of the scrape', function (): void {
    // Cache::get() throws when the default store isn't configured — the
    // same failure mode as Valkey being unreachable.
    config()->set('cache.default', 'unconfigured-store');

    $this->get('/metrics?token=test-token')
        ->assertOk()
        ->assertSee('mediamanager_ai_cost_usd_today', escape: false)
        ->assertDontSee('mediamanager_heartbeat_age_seconds{component=', escape: false);
});

test('the failed-jobs gauge itself failing does not take down the rest of the scrape', function (): void {
    // Points the gauge at a table that doesn't exist, producing a genuine
    // query failure scoped to this one gauge.
    config()->set('queue.failed.table', 'nonexistent_failed_jobs_table');

    $this->get('/metrics?token=test-token')
        ->assertOk()
        ->assertSee('mediamanager_ai_cost_usd_today', escape: false)
        ->assertDontSee('mediamanager_failed_jobs{queue=', escape: false);
});

test('the webhook-lag gauge failing does not take down the rest of the scrape', function (): void {
    // Renamed inside the per-test transaction, so the real table comes back
    // for every other test once this one rolls back.
    Schema::rename('webhook_events', 'webhook_events_renamed_for_test');

    $this->get('/metrics?token=test-token')
        ->assertOk()
        ->assertSee('mediamanager_ai_cost_usd_today', escape: false)
        ->assertDontSee('mediamanager_webhook_oldest_pending_age_seconds', escape: false);
});

test('a pre-existing gauge failing does not take down the rest of the scrape', function (): void {
    // A previously-unguarded gauge (mediamanager_service_up), first in
    // registration order; same rename-inside-the-transaction trick as the
    // webhook-lag isolation test. Postgres aborts the rest of the test's
    // wrapping transaction once this query fails, so every later DB-backed
    // gauge in this same request would also read as absent — that's a
    // RefreshDatabase/Postgres test artifact, not a production bug (outside
    // tests nothing wraps the request in one transaction). Assert against
    // mediamanager_job_queue_size instead: it reads the faked Queue
    // connection, not the database, so it isn't touched by the poisoned
    // transaction and proves the scrape as a whole still completes.
    ServiceConnection::factory()->create(['is_active' => true, 'health_status' => HealthStatus::Healthy, 'name' => 'sonarr']);
    Schema::rename('service_connections', 'service_connections_renamed_for_test');

    $this->get('/metrics?token=test-token')
        ->assertOk()
        ->assertSee('mediamanager_job_queue_size{queue="ai",state="pending"} 0', escape: false)
        ->assertDontSee('mediamanager_service_up{service="sonarr"}', escape: false);
});

test('the pending-webhook partial index exists and covers the lag query predicate', function (): void {
    $indexDefinition = (string) DB::table('pg_indexes')
        ->where('indexname', 'webhook_events_pending_created_at_index')
        ->value('indexdef');

    expect($indexDefinition)
        ->toContain('webhook_events')
        ->toContain('created_at')
        ->toContain('processed_at IS NULL');
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'pgsql',
    'The index definition is read from pg_indexes.',
);
