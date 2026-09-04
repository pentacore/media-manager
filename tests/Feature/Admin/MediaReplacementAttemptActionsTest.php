<?php

declare(strict_types=1);

use App\Enums\MediaReplacementStatus;
use App\Events\MediaReplacementAttemptChanged;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\MediaReplacement\MediaReplacementExecutionLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();
    Event::fake([MediaReplacementAttemptChanged::class]);
    $this->admin = User::factory()->admin()->create();
    $this->connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
    ]);
});

test('acknowledge stamps the attempt and redirects back with a success toast', function (): void {
    $attempt = MediaReplacementAttempt::factory()->needsAttention()->create();

    $this->actingAs($this->admin)
        ->from(route('admin.media-replacement.attempts.show', $attempt))
        ->post(route('admin.media-replacement.attempts.acknowledge', $attempt))
        ->assertRedirect(route('admin.media-replacement.attempts.show', $attempt))
        ->assertSessionHas('inertia.flash_data.toast.type', 'success');

    expect($attempt->fresh()->acknowledged_by)->toBe($this->admin->id);
    Event::assertDispatched(MediaReplacementAttemptChanged::class);
});

test('acknowledge is refused with 409 when the attempt does not need attention', function (): void {
    $attempt = MediaReplacementAttempt::factory()->verified()->create();

    $this->actingAs($this->admin)
        ->post(route('admin.media-replacement.attempts.acknowledge', $attempt))
        ->assertConflict();
});

test('restore monitoring calls the arr and reports success', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/episode/monitor' => Http::response([], 202)]);
    $attempt = MediaReplacementAttempt::factory()->verified()->monitoringSuspended()->create(['service_connection_id' => $this->connection->id]);

    $this->actingAs($this->admin)
        ->post(route('admin.media-replacement.attempts.restore-monitoring', $attempt))
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast.type', 'success');

    expect($attempt->fresh()->monitoring_suspended)->toBeFalse();
});

test('restore monitoring surfaces an arr failure as an error toast', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/episode/monitor' => Http::response([], 500)]);
    $attempt = MediaReplacementAttempt::factory()->verified()->monitoringSuspended()->create(['service_connection_id' => $this->connection->id]);

    $this->actingAs($this->admin)
        ->post(route('admin.media-replacement.attempts.restore-monitoring', $attempt))
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');

    expect($attempt->fresh()->monitoring_suspended)->toBeTrue();
});

test('restore monitoring is refused with 409 when nothing is suspended', function (): void {
    $attempt = MediaReplacementAttempt::factory()->verified()->create();

    $this->actingAs($this->admin)
        ->post(route('admin.media-replacement.attempts.restore-monitoring', $attempt))
        ->assertConflict();
});

test('cancel fails an in-flight attempt and reports success', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/queue/7*' => Http::response([], 200),
        'sonarr.local:8989/api/v3/queue?*' => Http::response(['totalRecords' => 1, 'records' => [['id' => 7, 'downloadId' => 'ABC123']]]),
    ]);
    $attempt = MediaReplacementAttempt::factory()->downloading()->create(['service_connection_id' => $this->connection->id, 'monitoring_suspended' => null]);

    $this->actingAs($this->admin)
        ->post(route('admin.media-replacement.attempts.cancel', $attempt))
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast.type', 'success');

    $fresh = $attempt->fresh();
    expect($fresh->status)->toBe(MediaReplacementStatus::Failed)
        ->and($fresh->failure_reason)->toBe('cancelled_by_operator');
});

test('cancel reports an error toast while the executor holds the lock', function (): void {
    $attempt = MediaReplacementAttempt::factory()->downloading()->create(['service_connection_id' => $this->connection->id]);
    Cache::lock(MediaReplacementExecutionLock::key($attempt->action_request_id), MediaReplacementExecutionLock::TTL_SECONDS)->get();

    $this->actingAs($this->admin)
        ->post(route('admin.media-replacement.attempts.cancel', $attempt))
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');

    expect($attempt->fresh()->status)->toBe(MediaReplacementStatus::Downloading);
});

test('cancel is refused with 409 for a settled attempt', function (): void {
    $attempt = MediaReplacementAttempt::factory()->failed()->create();

    $this->actingAs($this->admin)
        ->post(route('admin.media-replacement.attempts.cancel', $attempt))
        ->assertConflict();
});

test('operator actions are admin only', function (string $routeName): void {
    $attempt = MediaReplacementAttempt::factory()->needsAttention()->monitoringSuspended()->create();

    $this->actingAs(User::factory()->member()->create())
        ->post(route($routeName, $attempt))
        ->assertForbidden();
})->with([
    'admin.media-replacement.attempts.acknowledge',
    'admin.media-replacement.attempts.restore-monitoring',
    'admin.media-replacement.attempts.cancel',
]);
