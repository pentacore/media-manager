<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Events\ActionRequestStatusChanged;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use App\Services\Actions\ActionExecutor;
use App\Services\Sonarr\SonarrActions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Event::fake([ActionRequestStatusChanged::class]);
});

test('a new request gets a status version above every earlier one, and the model carries it', function (): void {
    $first = ActionRequest::factory()->create();
    $second = ActionRequest::factory()->create();

    expect($first->status_version)->toBeGreaterThan(0)
        ->and($second->status_version)->toBeGreaterThan($first->status_version)
        ->and($second->fresh()->status_version)->toBe($second->status_version);
});

test('a saved status change takes a new version, and a save that leaves the status alone keeps it', function (): void {
    $actionRequest = ActionRequest::factory()->create(['status' => ActionRequestStatus::Executing]);
    $created = $actionRequest->status_version;

    $actionRequest->update(['result' => ['retry_scheduled' => true, 'attempt' => 1]]);

    expect($actionRequest->fresh()->status_version)->toBe($created);

    $actionRequest->update(['status' => ActionRequestStatus::Failed, 'result' => ['success' => false]]);

    expect($actionRequest->status_version)->toBeGreaterThan($created)
        ->and($actionRequest->fresh()->status_version)->toBe($actionRequest->status_version);
});

test('a later status change on any request is numbered above every earlier version', function (): void {
    $older = ActionRequest::factory()->create();
    $newer = ActionRequest::factory()->create();

    $older->update(['status' => ActionRequestStatus::Rejected]);

    expect($older->fresh()->status_version)->toBeGreaterThan($newer->status_version);
});

test('claiming a request for execution takes a new version', function (): void {
    $actionRequest = ActionRequest::factory()->create(['status' => ActionRequestStatus::Approved, 'type' => 'delete_series']);
    $created = $actionRequest->status_version;
    $seen = new class
    {
        public ?int $version = null;
    };
    $mock = Mockery::mock(ActionExecutor::class);
    // claimForExecution() refreshes the model after its update, so the
    // executor sees the claimed row.
    $mock->shouldReceive('execute')->once()->andReturnUsing(function (ActionRequest $claimed) use ($seen): array {
        $seen->version = $claimed->status_version;

        return [];
    });
    $this->app->bind(SonarrActions::class, fn (): ActionExecutor => $mock);

    new ExecuteActionRequest($actionRequest)->handle();

    expect($seen->version)->toBeGreaterThan($created)
        ->and($actionRequest->fresh()->status_version)->toBeGreaterThan($seen->version);
});

test('a claim that waited behind another writer is numbered above that writer, not before it', function (): void {
    $actionRequest = ActionRequest::factory()->create(['status' => ActionRequestStatus::Approved, 'type' => 'delete_series']);
    $competing = new class
    {
        public bool $ran = false;

        public ?int $version = null;

        public ?int $claimedVersion = null;
    };
    // Stand in for a retry that held the row lock and committed while the
    // claim's UPDATE waited: just before the claim's UPDATE runs, another
    // writer saves the row through the observer (Failed, then Approved again).
    DB::beforeExecuting(function (string $query) use ($actionRequest, $competing): void {
        if ($competing->ran || ! str_starts_with($query, 'update "action_requests" set "status"')) {
            return;
        }

        $competing->ran = true;
        $retried = ActionRequest::query()->findOrFail($actionRequest->id);
        $retried->update(['status' => ActionRequestStatus::Failed]);
        $retried->update(['status' => ActionRequestStatus::Approved]);

        $competing->version = $retried->status_version;
    });
    $mock = Mockery::mock(ActionExecutor::class);
    $mock->shouldReceive('execute')->once()->andReturnUsing(function (ActionRequest $claimed) use ($competing): array {
        $competing->claimedVersion = $claimed->status_version;

        return [];
    });
    $this->app->bind(SonarrActions::class, fn (): ActionExecutor => $mock);

    new ExecuteActionRequest($actionRequest)->handle();

    expect($competing->ran)->toBeTrue()
        ->and($competing->claimedVersion)->toBeGreaterThan($competing->version);
});

test('an insert that bypasses Eloquent is numbered by the column default, above every earlier version', function (): void {
    $earlier = ActionRequest::factory()->create();
    $attributes = ActionRequest::factory()->make()->getAttributes();
    unset($attributes['status_version']);

    $id = DB::table('action_requests')->insertGetId([...$attributes, 'created_at' => now(), 'updated_at' => now()]);

    expect(DB::table('action_requests')->where('id', $id)->value('status_version'))->toBeGreaterThan($earlier->status_version);
});

test('failing a stuck run and a never-started request takes a new version', function (): void {
    Queue::fake();
    $stuck = ActionRequest::factory()->create(['status' => ActionRequestStatus::Executing]);
    $neverStarted = ActionRequest::factory()->autoExecute()->create(['type' => 'delete_series']);
    ActionRequest::query()->whereKey($stuck->id)->update(['updated_at' => now()->subHours(5)]);
    ActionRequest::query()->whereKey($neverStarted->id)->update(['updated_at' => now()->subHours(25)]);

    $this->artisan('actions:reconcile-stuck')->assertSuccessful();

    expect($stuck->fresh()->status)->toBe(ActionRequestStatus::Failed)
        ->and($stuck->fresh()->status_version)->toBeGreaterThan($stuck->status_version)
        ->and($neverStarted->fresh()->status)->toBe(ActionRequestStatus::Failed)
        ->and($neverStarted->fresh()->status_version)->toBeGreaterThan($neverStarted->status_version);
});

test('the migration numbers existing rows by when they last changed, and new rows continue above them', function (): void {
    $later = ActionRequest::factory()->create(['updated_at' => now()->subMinute()]);
    $earlier = ActionRequest::factory()->create(['updated_at' => now()->subHour()]);
    $migration = require database_path('migrations/2026_10_13_100000_add_status_version_to_action_requests_table.php');

    // Postgres runs DDL inside the test's transaction, so this rolls back.
    $migration->down();
    $migration->up();

    $versions = ActionRequest::query()->pluck('status_version', 'id');
    $new = ActionRequest::factory()->create();

    expect($versions[$earlier->id])->toBe(1)
        ->and($versions[$later->id])->toBe(2)
        ->and($new->status_version)->toBe(3);
});
