<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Events\ActionRequestStatusChanged;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use App\Services\Actions\ActionExecutor;
use App\Services\Radarr\RadarrActions;
use App\Services\Sonarr\SonarrActions;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Event::fake([ActionRequestStatusChanged::class]);
});

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
