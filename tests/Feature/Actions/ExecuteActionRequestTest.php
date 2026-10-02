<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Events\ActionRequestStatusChanged;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Services\Actions\ActionExecutor;
use App\Services\Bazarr\BazarrActions;
use App\Services\Bazarr\BazarrIndeterminateOutcomeException;
use App\Services\MediaReplacement\MediaReplacementActions;
use App\Services\Radarr\RadarrActions;
use App\Services\Seerr\SeerrRequestLock;
use App\Services\Sonarr\SonarrActions;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    Event::fake([ActionRequestStatusChanged::class]);
});

/**
 * The job as the queue hands it over on a given delivery attempt.
 */
function executeActionRequestOnAttempt(ActionRequest $actionRequest, int $attempt): ExecuteActionRequest
{
    $job = new ExecuteActionRequest($actionRequest);
    $mock = Mockery::mock(Job::class);
    $mock->shouldReceive('attempts')->andReturn($attempt);
    $mock->shouldReceive('uuid')->andReturn('job-uuid');
    $mock->shouldReceive('getJobId')->andReturn('job-id');
    $mock->shouldReceive('resolveName')->andReturn(ExecuteActionRequest::class);
    $mock->shouldReceive('hasFailed')->andReturn(false);
    $mock->shouldReceive('isReleased')->andReturn(false);
    $mock->shouldReceive('isDeleted')->andReturn(false);
    $job->setJob($mock);

    return $job;
}

test('skips execution when status is not Approved', function (): void {
    $request = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Pending,
        'type' => 'delete_series',
    ]);

    new ExecuteActionRequest($request)->handle();

    expect($request->fresh()->status)->toBe(ActionRequestStatus::Pending);
    Event::assertNotDispatched(ActionRequestStatusChanged::class);
});

test('marks as Failed when no executor is registered for the type', function (): void {
    $request = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Approved,
        'type' => 'never_registered_type',
    ]);

    new ExecuteActionRequest($request)->handle();

    $fresh = $request->fresh();
    expect($fresh->status)->toBe(ActionRequestStatus::Failed);
    expect($fresh->result)->toMatchArray(['success' => false, 'reason' => 'no_executor']);
});

test('sets status to Executing then Completed on success', function (): void {
    $request = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Approved,
        'type' => 'delete_series',
    ]);

    $mock = Mockery::mock(ActionExecutor::class);
    $mock->shouldReceive('execute')->once()->with(Mockery::on(fn ($arg): bool => $arg instanceof ActionRequest && $arg->id === $request->id))->andReturn(['deleted' => true]);
    $this->app->bind(SonarrActions::class, fn (): ActionExecutor => $mock);

    new ExecuteActionRequest($request)->handle();

    $fresh = $request->fresh();
    expect($fresh->status)->toBe(ActionRequestStatus::Completed);
    expect($fresh->result)->toMatchArray(['success' => true, 'deleted' => true]);

    Event::assertDispatchedTimes(ActionRequestStatusChanged::class, 2); // Executing, Completed
});

test('routes replace_media_file to the media replacement executor', function (): void {
    $request = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Approved,
        'type' => 'replace_media_file',
    ]);

    $mock = Mockery::mock(ActionExecutor::class);
    $mock->shouldReceive('execute')->once()->andReturn(['replacement_initiated' => true]);
    $this->app->bind(MediaReplacementActions::class, fn (): ActionExecutor => $mock);

    new ExecuteActionRequest($request)->handle();

    $fresh = $request->fresh();
    expect($fresh->status)->toBe(ActionRequestStatus::Completed);
    expect($fresh->result)->toMatchArray(['success' => true, 'replacement_initiated' => true]);
});

test('routes every Bazarr action type to the Bazarr executor', function (string $type): void {
    $request = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Approved,
        'type' => $type,
    ]);
    $mock = Mockery::mock(ActionExecutor::class);
    $mock->shouldReceive('execute')->once()->andReturn(['operation' => $type]);
    $this->app->bind(BazarrActions::class, fn (): ActionExecutor => $mock);

    new ExecuteActionRequest($request)->handle();

    expect($request->fresh()->status)->toBe(ActionRequestStatus::Completed);
})->with([
    'bazarr_download_best',
    'bazarr_download_exact',
    'bazarr_upload_subtitle',
    'bazarr_delete_subtitle',
    'bazarr_sync_subtitle',
    'bazarr_translate_subtitle',
    'bazarr_modify_subtitle',
    'bazarr_scan_media',
    'bazarr_run_task',
]);

test('marks indeterminate Bazarr outcomes failed without rethrowing for retry', function (): void {
    $request = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Approved,
        'type' => 'bazarr_download_best',
    ]);
    $mock = Mockery::mock(ActionExecutor::class);
    $mock->shouldReceive('execute')->once()
        ->andThrow(new BazarrIndeterminateOutcomeException('Bazarr may have accepted the write.'));
    $this->app->bind(BazarrActions::class, fn (): ActionExecutor => $mock);

    new ExecuteActionRequest($request)->handle();

    expect($request->fresh()->status)->toBe(ActionRequestStatus::Failed)
        ->and($request->fresh()->result)->toMatchArray([
            'success' => false,
            'reason' => 'needs_reconciliation',
            'message' => 'Bazarr may have accepted the write.',
            'indeterminate' => true,
        ]);
});

test('marks as Failed immediately for permanent (non-transient) exceptions', function (): void {
    $request = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Approved,
        'type' => 'delete_movie',
    ]);

    $mock = Mockery::mock(ActionExecutor::class);
    $mock->shouldReceive('execute')->once()->andThrow(new InvalidArgumentException('bad payload'));
    $this->app->bind(RadarrActions::class, fn (): ActionExecutor => $mock);

    new ExecuteActionRequest($request)->handle();

    $fresh = $request->fresh();
    expect($fresh->status)->toBe(ActionRequestStatus::Failed);
    expect($fresh->result)->toMatchArray([
        'success' => false,
        'reason' => 'execution_failed',
        'message' => 'bad payload',
        'exception' => InvalidArgumentException::class,
    ]);
});

test('rethrows transient ConnectionException when retry budget remains', function (): void {
    $request = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Approved,
        'type' => 'delete_movie',
    ]);

    $mock = Mockery::mock(ActionExecutor::class);
    $mock->shouldReceive('execute')->once()->andThrow(new ConnectionException('connection refused'));
    $this->app->bind(RadarrActions::class, fn (): ActionExecutor => $mock);

    // Fake job on first attempt (attempts() = 1 < tries = 3) — must rethrow so queue retries.
    $job = new ExecuteActionRequest($request);
    $fake = Mockery::mock(Job::class);
    $fake->shouldReceive('attempts')->andReturn(1);
    $fake->shouldReceive('uuid')->andReturn('job-uuid');
    $fake->shouldReceive('getJobId')->andReturn('job-id');
    $fake->shouldReceive('resolveName')->andReturn(ExecuteActionRequest::class);
    $fake->shouldReceive('hasFailed')->andReturn(false);
    $fake->shouldReceive('isReleased')->andReturn(false);
    $fake->shouldReceive('isDeleted')->andReturn(false);
    $job->setJob($fake);

    expect(fn () => $job->handle())->toThrow(ConnectionException::class);

    // On rethrow, status should still be Executing (set before executor call) — not Failed.
    $fresh = $request->fresh();
    expect($fresh->status)->toBe(ActionRequestStatus::Executing);
});

test('marks as Failed with retries_exhausted on final attempt for transient exceptions', function (): void {
    $request = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Approved,
        'type' => 'delete_movie',
    ]);

    $mock = Mockery::mock(ActionExecutor::class);
    $mock->shouldReceive('execute')->once()->andThrow(new ConnectionException('still unreachable'));
    $this->app->bind(RadarrActions::class, fn (): ActionExecutor => $mock);

    // Fake job on final attempt (attempts() = 3 = tries) — must NOT rethrow; persist Failed.
    $job = new ExecuteActionRequest($request);
    $fake = Mockery::mock(Job::class);
    $fake->shouldReceive('attempts')->andReturn(3);
    $fake->shouldReceive('uuid')->andReturn('job-uuid');
    $fake->shouldReceive('getJobId')->andReturn('job-id');
    $fake->shouldReceive('resolveName')->andReturn(ExecuteActionRequest::class);
    $fake->shouldReceive('hasFailed')->andReturn(false);
    $fake->shouldReceive('isReleased')->andReturn(false);
    $fake->shouldReceive('isDeleted')->andReturn(false);
    $job->setJob($fake);

    $job->handle();

    $fresh = $request->fresh();
    expect($fresh->status)->toBe(ActionRequestStatus::Failed);
    expect($fresh->result)->toMatchArray([
        'success' => false,
        'reason' => 'retries_exhausted',
        'message' => 'still unreachable',
        'exception' => ConnectionException::class,
    ]);
});

test('rethrows transient 5xx RequestException when retry budget remains', function (): void {
    $request = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Approved,
        'type' => 'delete_movie',
    ]);

    $response = new Response(new GuzzleHttp\Psr7\Response(503, [], 'service unavailable'));

    $mock = Mockery::mock(ActionExecutor::class);
    $mock->shouldReceive('execute')->once()->andThrow(new RequestException($response));
    $this->app->bind(RadarrActions::class, fn (): ActionExecutor => $mock);

    $job = new ExecuteActionRequest($request);
    $fake = Mockery::mock(Job::class);
    $fake->shouldReceive('attempts')->andReturn(1);
    $fake->shouldReceive('uuid')->andReturn('job-uuid');
    $fake->shouldReceive('getJobId')->andReturn('job-id');
    $fake->shouldReceive('resolveName')->andReturn(ExecuteActionRequest::class);
    $fake->shouldReceive('hasFailed')->andReturn(false);
    $fake->shouldReceive('isReleased')->andReturn(false);
    $fake->shouldReceive('isDeleted')->andReturn(false);
    $job->setJob($fake);

    expect(fn () => $job->handle())->toThrow(RequestException::class);
});

test('failed() hook does not overwrite already-Failed status', function (): void {
    $request = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Failed,
        'result' => ['success' => false, 'reason' => 'execution_failed', 'message' => 'original'],
    ]);

    $job = new ExecuteActionRequest($request);
    $job->failed(new RuntimeException('late signal'));

    $fresh = $request->fresh();
    expect($fresh->status)->toBe(ActionRequestStatus::Failed);
    expect($fresh->result)->toMatchArray(['reason' => 'execution_failed', 'message' => 'original']);
});

test('failed() hook records job_failed when queue exhausts without explicit state', function (): void {
    $request = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Executing,
    ]);

    $job = new ExecuteActionRequest($request);
    $job->failed(new RuntimeException('queue gave up'));

    $fresh = $request->fresh();
    expect($fresh->status)->toBe(ActionRequestStatus::Failed);
    expect($fresh->result)->toMatchArray([
        'success' => false,
        'reason' => 'job_failed',
        'message' => 'queue gave up',
    ]);
});

test('job has timeout and unique-for duration', function (): void {
    $request = ActionRequest::factory()->create(['status' => ActionRequestStatus::Approved, 'type' => 'delete_series']);
    $job = new ExecuteActionRequest($request);
    $reflection = new ReflectionClass($job);

    expect($reflection->getAttributes(Timeout::class)[0]->newInstance()->timeout)->toBe(300)
        ->and($reflection->getAttributes(UniqueFor::class)[0]->newInstance()->uniqueFor)->toBe(3600);
});

test('claiming a request writes the executing entry to the activity log', function (): void {
    $request = ActionRequest::factory()->autoExecute()->create(['type' => 'delete_series']);
    $mock = Mockery::mock(ActionExecutor::class);
    $mock->shouldReceive('execute')->once()->andReturn([]);
    $this->app->bind(SonarrActions::class, fn (): ActionExecutor => $mock);

    new ExecuteActionRequest($request)->handle();

    expect(ActivityLog::query()->where('subject_id', $request->id)->where('action', 'action_request.executing')->count())->toBe(1)
        ->and(ActivityLog::query()->where('subject_id', $request->id)->where('action', 'action_request.completed')->count())->toBe(1);
});

test('a skipped claim writes no executing entry', function (): void {
    $request = ActionRequest::factory()->create(['status' => ActionRequestStatus::Pending]);

    new ExecuteActionRequest($request)->handle();

    expect(ActivityLog::query()->where('subject_id', $request->id)->where('action', 'action_request.executing')->exists())->toBeFalse();
});

test('a grab whose release expired while waiting fails with a search-again message', function (): void {
    Http::preventStrayRequests();
    $connection = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    Http::fake(['sonarr.local:8989/api/v3/release' => Http::response(['message' => "Couldn't find requested release in cache, try searching again"], 404)]);

    $request = ActionRequest::factory()->autoExecute()->create([
        'type' => 'grab_release',
        'payload' => ['service' => 'sonarr', 'series_id' => 7, 'guid' => 'g-1', 'indexer_id' => 3, 'release' => ['title' => 'x'], 'service_connection_id' => $connection->id],
    ]);

    new ExecuteActionRequest($request)->handle();

    $fresh = $request->fresh();
    expect($fresh->status)->toBe(ActionRequestStatus::Failed)
        ->and($fresh->result['reason'])->toBe('execution_failed')
        ->and($fresh->result['message'])->toContain('Run the interactive search again');
});

test('a grab whose response is lost fails once instead of retrying into a second download', function (): void {
    Http::preventStrayRequests();
    $connection = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    Http::fake(['sonarr.local:8989/api/v3/release' => fn (): never => throw new ConnectionException('reset')]);

    $request = ActionRequest::factory()->autoExecute()->create([
        'type' => 'grab_release',
        'payload' => ['service' => 'sonarr', 'series_id' => 7, 'guid' => 'g-1', 'indexer_id' => 3, 'release' => ['title' => 'x'], 'service_connection_id' => $connection->id],
    ]);

    new ExecuteActionRequest($request)->handle();

    expect($request->fresh()->status)->toBe(ActionRequestStatus::Failed)
        ->and($request->fresh()->result['message'])->toContain('did not confirm the grab');
});

test('a deterministic upstream failure stores the reason without its paths or secrets', function (): void {
    $request = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Approved,
        'type' => 'delete_movie',
    ]);

    $response = new Response(new GuzzleHttp\Psr7\Response(404, [], 'Movie folder /media/movies/Dune (2021) not found; see http://radarr.local/api/v3/movie/7?apikey=radarr-secret'));
    $mock = Mockery::mock(ActionExecutor::class);
    $mock->shouldReceive('execute')->once()->andThrow(new RequestException($response));
    $this->app->bind(RadarrActions::class, fn (): ActionExecutor => $mock);

    new ExecuteActionRequest($request)->handle();

    $message = $request->fresh()->result['message'];

    expect($request->fresh()->result['reason'])->toBe('execution_failed')
        ->and($message)->toContain('HTTP request returned status code 404')
        ->and($message)->not->toContain('/media/movies')
        ->and($message)->not->toContain('radarr-secret');
});

test('a permanent failure message naming a path is stored redacted', function (): void {
    $request = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Approved,
        'type' => 'delete_movie',
    ]);

    $mock = Mockery::mock(ActionExecutor::class);
    $mock->shouldReceive('execute')->once()->andThrow(new RuntimeException('Could not unlink /media/movies/Dune.mkv'));
    $this->app->bind(RadarrActions::class, fn (): ActionExecutor => $mock);

    new ExecuteActionRequest($request)->handle();

    expect($request->fresh()->result['message'])->toBe('Could not unlink [redacted path]');
});

test('the failed() hook stores a sanitized message', function (): void {
    $request = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Executing,
        'type' => 'delete_movie',
    ]);

    new ExecuteActionRequest($request)->failed(new ConnectionException('cURL error 7: Failed to connect for http://radarr.local:7878/api/v3/movie?apikey=radarr-secret'));

    expect($request->fresh()->result['reason'])->toBe('job_failed')
        ->and($request->fresh()->result['message'])->toContain('cURL error 7')
        ->and($request->fresh()->result['message'])->not->toContain('radarr-secret');
});

test('a request pinned to a deleted connection fails with the pin message', function (): void {
    ServiceConnection::factory()->seerr()->create();
    $request = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Approved,
        'type' => 'cleanup_seerr_request',
        'payload' => ['seerr_request_id' => 5, 'service_connection_id' => 999_999],
    ]);

    new ExecuteActionRequest($request)->handle();

    expect($request->fresh()->status)->toBe(ActionRequestStatus::Failed)
        ->and($request->fresh()->result)->toMatchArray([
            'reason' => 'execution_failed',
            'message' => 'Service connection 999999 pinned to this action no longer exists; aborting instead of acting on a different instance.',
            'exception' => ModelNotFoundException::class,
        ]);
});

test('a queued Seerr approve that cannot get the request lock fails without retrying', function (): void {
    Sleep::fake(syncWithCarbon: true);
    $seerr = ServiceConnection::factory()->seerr()->create();
    Cache::lock(SeerrRequestLock::key($seerr->id, 77), SeerrRequestLock::TTL_SECONDS)->get();
    Http::fake();

    $request = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Approved,
        'type' => 'approve_seerr_request',
        'payload' => ['seerr_request_id' => 77, 'service_connection_id' => $seerr->id],
    ]);

    new ExecuteActionRequest($request)->handle();

    expect($request->fresh()->status)->toBe(ActionRequestStatus::Failed)
        ->and($request->fresh()->result)->toMatchArray([
            'reason' => 'execution_failed',
            'message' => 'Seerr request 77 is being changed by another MediaManager action.',
        ]);
    Http::assertNothingSent();
});

test('a transient rethrow leaves a retry marker on the executing request', function (): void {
    $request = ActionRequest::factory()->create(['status' => ActionRequestStatus::Approved, 'type' => 'delete_movie']);
    $mock = Mockery::mock(ActionExecutor::class);
    $mock->shouldReceive('execute')->once()->andThrow(new ConnectionException('connection refused'));
    $this->app->bind(RadarrActions::class, fn (): ActionExecutor => $mock);

    expect(fn () => executeActionRequestOnAttempt($request, 1)->handle())->toThrow(ConnectionException::class);

    $fresh = $request->fresh();
    expect($fresh->status)->toBe(ActionRequestStatus::Executing)
        ->and($fresh->result)->toBe(['retry_scheduled' => true, 'attempt' => 1])
        ->and(ActivityLog::query()->where('subject_id', $request->id)->where('action', 'action_request.failed')->exists())->toBeFalse();
});

test('the re-delivery of a deliberate retry clears the marker before it runs the executor again', function (): void {
    $request = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Executing,
        'type' => 'delete_movie',
        'result' => ['retry_scheduled' => true, 'attempt' => 1],
    ]);
    $holder = new stdClass;
    $mock = Mockery::mock(ActionExecutor::class);
    $mock->shouldReceive('execute')->once()->andReturnUsing(function (ActionRequest $actionRequest) use ($holder): array {
        $holder->storedResultWhileExecuting = ActionRequest::query()->whereKey($actionRequest->id)->value('result');

        return ['deleted' => true];
    });
    $this->app->bind(RadarrActions::class, fn (): ActionExecutor => $mock);

    executeActionRequestOnAttempt($request, 2)->handle();

    expect($holder->storedResultWhileExecuting)->toBeNull()
        ->and($request->fresh()->status)->toBe(ActionRequestStatus::Completed)
        ->and($request->fresh()->result)->toBe(['success' => true, 'deleted' => true]);
});

test('a transient failure on the first attempt is retried and completes on the second', function (): void {
    $request = ActionRequest::factory()->create(['status' => ActionRequestStatus::Approved, 'type' => 'delete_movie']);
    $holder = new stdClass;
    $holder->calls = 0;

    $mock = Mockery::mock(ActionExecutor::class);
    $mock->shouldReceive('execute')->twice()->andReturnUsing(function () use ($holder): array {
        $holder->calls++;

        throw_if($holder->calls === 1, ConnectionException::class, 'connection reset');

        return ['deleted' => true];
    });
    $this->app->bind(RadarrActions::class, fn (): ActionExecutor => $mock);

    expect(fn () => executeActionRequestOnAttempt($request, 1)->handle())->toThrow(ConnectionException::class);
    executeActionRequestOnAttempt($request->fresh(), 2)->handle();

    expect($holder->calls)->toBe(2)
        ->and($request->fresh()->status)->toBe(ActionRequestStatus::Completed);
});
