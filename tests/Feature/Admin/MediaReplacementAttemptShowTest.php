<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Models\MediaReplacementAttempt;
use App\Models\User;

beforeEach(function (): void {
    config()->set('inertia.testing.ensure_pages_exist', false);
    $this->admin = User::factory()->admin()->create();
});

test('show exposes the full attempt with timeline, raw json, links and abilities', function (): void {
    $attempt = MediaReplacementAttempt::factory()->needsAttention()->monitoringSuspended()->acknowledged($this->admin)->create([
        'download_id' => 'ABC123',
        'grab_attempted_at' => now()->subHours(9),
        'grab_accepted_at' => now()->subHours(9),
    ]);

    $this->actingAs($this->admin)
        ->get(route('admin.media-replacement.attempts.show', $attempt))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/MediaReplacement/Attempts/Show')
            ->where('attempt.id', $attempt->id)
            ->where('attempt.status', 'needs_attention')
            ->where('attempt.failure_reason', 'download_timeout')
            ->where('attempt.service.type', 'sonarr')
            ->where('attempt.display_name', 'Trusted Anime S01E01')
            ->has('attempt.timeline.started_at')
            ->has('attempt.timeline.grab_accepted_at')
            ->where('attempt.timeline.cleanup_completed_at', null)
            ->where('attempt.download_id', 'ABC123')
            ->where('attempt.target.series_id', 42)
            ->where('attempt.candidate.title', 'Show S01E01 CR')
            ->where('attempt.required_languages', ['eng'])
            ->where('attempt.monitoring.was_monitored', true)
            ->where('attempt.monitoring.monitoring_suspended', true)
            ->where('attempt.acknowledged.by_name', $this->admin->name)
            ->where('attempt.action_request.id', $attempt->action_request_id)
            ->where('attempt.action_request.status', 'pending')
            ->where('attempt.links.media', route('media.series.show', ['id' => 42]))
            ->where('attempt.links.action_request', route('actions.requests.index', ['request' => $attempt->action_request_id]))
            ->where('attempt.links.grab_queue', route('media.library.activity.queue'))
            ->where('attempt.can.retry', false)
            ->where('attempt.can.restore_monitoring', true)
            ->where('attempt.can.acknowledge', false)
            ->where('attempt.can.cancel', false)
        );
});

test('show links a radarr target to its movie page and omits the grab queue without a download id', function (): void {
    $attempt = MediaReplacementAttempt::factory()->failed()->radarr()->create(['download_id' => null]);

    $this->actingAs($this->admin)
        ->get(route('admin.media-replacement.attempts.show', $attempt))
        ->assertInertia(fn ($page) => $page
            ->where('attempt.links.media', route('media.movies.show', ['id' => 10]))
            ->where('attempt.links.grab_queue', null)
            ->where('attempt.acknowledged', null)
        );
});

test('show has no media link when the snapshot lacks an id', function (): void {
    $attempt = MediaReplacementAttempt::factory()->failed()->create(['target' => ['service' => 'sonarr', 'episode_file_ids' => [501]]]);

    $this->actingAs($this->admin)
        ->get(route('admin.media-replacement.attempts.show', $attempt))
        ->assertInertia(fn ($page) => $page->where('attempt.links.media', null)->where('attempt.display_name', null));
});

test('abilities follow the attempt and action request state', function (callable $make, array $expected): void {
    $attempt = $make();

    $this->actingAs($this->admin)
        ->get(route('admin.media-replacement.attempts.show', $attempt))
        ->assertInertia(fn ($page) => $page->where('attempt.can', $expected));
})->with([
    'downloading can only be cancelled' => [
        fn (): MediaReplacementAttempt => MediaReplacementAttempt::factory()->downloading()->create(),
        ['retry' => false, 'restore_monitoring' => false, 'acknowledge' => false, 'cancel' => true],
    ],
    'failed with failed request can be retried' => [
        function (): MediaReplacementAttempt {
            $attempt = MediaReplacementAttempt::factory()->failed()->create();
            $attempt->actionRequest->update(['status' => ActionRequestStatus::Failed]);

            return $attempt;
        },
        ['retry' => true, 'restore_monitoring' => false, 'acknowledge' => false, 'cancel' => false],
    ],
    'failed with completed request cannot be retried' => [
        function (): MediaReplacementAttempt {
            $attempt = MediaReplacementAttempt::factory()->failed()->create();
            $attempt->actionRequest->update(['status' => ActionRequestStatus::Completed]);

            return $attempt;
        },
        ['retry' => false, 'restore_monitoring' => false, 'acknowledge' => false, 'cancel' => false],
    ],
    'open needs_attention can be acknowledged' => [
        fn (): MediaReplacementAttempt => MediaReplacementAttempt::factory()->needsAttention()->create(),
        ['retry' => false, 'restore_monitoring' => false, 'acknowledge' => true, 'cancel' => false],
    ],
    'suspended verified can restore monitoring' => [
        fn (): MediaReplacementAttempt => MediaReplacementAttempt::factory()->verified()->monitoringSuspended()->create(),
        ['retry' => false, 'restore_monitoring' => true, 'acknowledge' => false, 'cancel' => false],
    ],
]);

test('show is admin only and 404s for unknown attempts', function (): void {
    $attempt = MediaReplacementAttempt::factory()->create();

    $this->actingAs(User::factory()->member()->create())
        ->get(route('admin.media-replacement.attempts.show', $attempt))
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->get(route('admin.media-replacement.attempts.show', ['mediaReplacementAttempt' => 999_999]))
        ->assertNotFound();
});
