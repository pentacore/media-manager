<?php

declare(strict_types=1);

use App\Enums\MediaReplacementStatus;
use App\Jobs\SweepCompetingGrabs;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Services\MediaReplacement\CompetingGrabSweeper;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    CarbonImmutable::setTestNow();
    Http::preventStrayRequests();
    Notification::fake();

    $this->connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
    ]);

    // CompetingGrabSweeper is final readonly, so it cannot be mocked. The job
    // runs the real collaborator instead and the arr endpoints it would call
    // are faked: whether the job swept is then observable as real traffic,
    // which the sweeper's blanket catch cannot swallow the way it would a
    // stray-request or mock-expectation failure.
    $this->competingGrabSweeper = resolve(CompetingGrabSweeper::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * An in-flight attempt whose Grab webhook has landed, so the sweep is armed and
 * a sweep is guaranteed to hit the queue endpoint.
 *
 * @param  array<string, mixed>  $overrides
 */
function sweepJobAttempt(int $connectionId, array $overrides = []): MediaReplacementAttempt
{
    return MediaReplacementAttempt::factory()->create(array_replace([
        'service_connection_id' => $connectionId,
        'status' => MediaReplacementStatus::Downloading,
        'scope' => 'anime',
        'target' => ['service' => 'sonarr', 'scope' => 'anime', 'series_id' => 42, 'episode_ids' => [101]],
        'candidate' => ['title' => 'Trusted.Anime.S01E01.CR'],
        'download_id' => 'DL-OURS',
    ], $overrides));
}

/**
 * A queue holding one competing grab on the attempt's target, so a sweep that
 * really runs leaves a DELETE behind.
 */
function fakeSweepJobQueue(): void
{
    // The removal pattern comes first: Laravel returns the first matching stub,
    // and the broader 'queue*' would otherwise answer the DELETE too.
    Http::fake([
        'sonarr.local:8989/api/v3/queue/*' => Http::response([], 200),
        'sonarr.local:8989/api/v3/queue*' => Http::response(['records' => [
            ['id' => 900, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => 'DL-OTHER', 'title' => 'Random.Anime.S01E01.OTHER'],
        ]]),
    ]);
}

function assertSweptTheQueue(): void
{
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/queue/900'));
}

/**
 * The scheduled delay for a pass, as a wall-clock instant, or null when the job
 * was queued without one. Time is frozen by the callers that assert on this.
 */
function sweepJobDelayTimestamp(SweepCompetingGrabs $sweepCompetingGrabs): ?int
{
    return $sweepCompetingGrabs->delay instanceof DateTimeInterface
        ? $sweepCompetingGrabs->delay->getTimestamp()
        : null;
}

test('the pass delays are the values the spec pins', function (): void {
    expect(SweepCompetingGrabs::PASS_DELAY_SECONDS)->toBe([60, 180, 600]);
});

test('it sweeps and schedules the next pass while the attempt is still in flight', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-30 12:00:00', 'UTC'));
    Queue::fake();
    fakeSweepJobQueue();

    $mediaReplacementAttempt = sweepJobAttempt($this->connection->id);

    new SweepCompetingGrabs($mediaReplacementAttempt->id, 0)->handle($this->competingGrabSweeper);

    assertSweptTheQueue();

    Queue::assertPushed(SweepCompetingGrabs::class, fn (SweepCompetingGrabs $job): bool => $job->attemptId === $mediaReplacementAttempt->id
        && $job->pass === 1
        && sweepJobDelayTimestamp($job) === now()->addSeconds(180)->getTimestamp());
});

test('it stops after the final pass', function (): void {
    Queue::fake();
    fakeSweepJobQueue();

    $mediaReplacementAttempt = sweepJobAttempt($this->connection->id);

    $finalPass = count(SweepCompetingGrabs::PASS_DELAY_SECONDS) - 1;

    new SweepCompetingGrabs($mediaReplacementAttempt->id, $finalPass)->handle($this->competingGrabSweeper);

    // The final pass still sweeps; it simply queues no successor.
    assertSweptTheQueue();

    Queue::assertNotPushed(SweepCompetingGrabs::class);
});

test('it does nothing for a terminal attempt', function (): void {
    Queue::fake();
    fakeSweepJobQueue();

    $mediaReplacementAttempt = sweepJobAttempt($this->connection->id, [
        'status' => MediaReplacementStatus::Verified,
        'completed_at' => now(),
    ]);

    new SweepCompetingGrabs($mediaReplacementAttempt->id, 0)->handle($this->competingGrabSweeper);

    // The attempt is armed, so any sweep would have read the queue.
    Http::assertNothingSent();
    Queue::assertNotPushed(SweepCompetingGrabs::class);
});

test('it does nothing when the attempt has been pruned', function (): void {
    Queue::fake();
    fakeSweepJobQueue();

    new SweepCompetingGrabs(999_999, 0)->handle($this->competingGrabSweeper);

    Http::assertNothingSent();
    Queue::assertNotPushed(SweepCompetingGrabs::class);
});

test('it does nothing when the attempt lost its service connection', function (): void {
    Queue::fake();
    fakeSweepJobQueue();

    $mediaReplacementAttempt = sweepJobAttempt($this->connection->id, ['service_connection_id' => null]);

    new SweepCompetingGrabs($mediaReplacementAttempt->id, 0)->handle($this->competingGrabSweeper);

    Http::assertNothingSent();
    Queue::assertNotPushed(SweepCompetingGrabs::class);
});

test('queueFor dispatches the first pass with its delay', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-30 12:00:00', 'UTC'));
    Queue::fake();

    SweepCompetingGrabs::queueFor(17);

    Queue::assertPushed(SweepCompetingGrabs::class, fn (SweepCompetingGrabs $job): bool => $job->attemptId === 17
        && $job->pass === 0
        && sweepJobDelayTimestamp($job) === now()->addSeconds(60)->getTimestamp());
});

test('queueFor ignores a pass beyond the configured delays', function (): void {
    Queue::fake();

    SweepCompetingGrabs::queueFor(17, count(SweepCompetingGrabs::PASS_DELAY_SECONDS));

    Queue::assertNotPushed(SweepCompetingGrabs::class);
});
