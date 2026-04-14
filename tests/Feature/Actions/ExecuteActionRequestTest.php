<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Events\ActionRequestStatusChanged;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use App\Services\Actions\ActionExecutor;
use App\Services\Radarr\RadarrActions;
use App\Services\Sonarr\SonarrActions;
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

test('marks as Failed when executor throws', function (): void {
    $request = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Approved,
        'type' => 'delete_movie',
    ]);

    $mock = Mockery::mock(ActionExecutor::class);
    $mock->shouldReceive('execute')->once()->andThrow(new RuntimeException('boom'));
    $this->app->bind(RadarrActions::class, fn (): ActionExecutor => $mock);

    new ExecuteActionRequest($request)->handle();

    $fresh = $request->fresh();
    expect($fresh->status)->toBe(ActionRequestStatus::Failed);
    expect($fresh->result)->toMatchArray(['success' => false, 'reason' => 'execution_failed', 'message' => 'boom']);
});
