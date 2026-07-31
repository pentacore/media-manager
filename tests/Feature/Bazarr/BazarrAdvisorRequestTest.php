<?php

declare(strict_types=1);

use App\Enums\SubtitleCaseAttemptOutcome;
use App\Enums\SubtitleCaseAttemptType;
use App\Enums\SubtitleCaseStatus;
use App\Jobs\RunSubtitleAdvisor;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Models\SubtitleCaseAttempt;
use App\Models\User;
use App\Settings\BazarrAutomationSettings;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    config(['mediamanager.ai.enabled' => true]);
    resolve(BazarrAutomationSettings::class)->setConfiguration(['enabled' => true]);
});

test('a manual investigation is refused while the Advisor cannot run', function (array $configuration): void {
    config(['mediamanager.ai.enabled' => $configuration['ai']]);
    resolve(BazarrAutomationSettings::class)->setConfiguration(['enabled' => $configuration['automation']]);

    $bazarr = ServiceConnection::factory()->bazarr()->create();
    $subtitleCase = SubtitleCase::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'status' => SubtitleCaseStatus::NeedsReview,
    ]);

    $this->actingAs(User::factory()->member()->create())
        ->post(route('bazarr.advisor.store', [
            'subtitleCase' => $subtitleCase,
            'connection' => $bazarr->id,
        ]), ['confirm_retry' => true])
        ->assertRedirect();

    // The worker would return immediately, so the case must stay parked instead of
    // being reopened against a queued job that does nothing.
    expect($subtitleCase->fresh()->status)->toBe(SubtitleCaseStatus::NeedsReview)
        ->and(SubtitleCaseAttempt::query()->count())->toBe(0);
    Queue::assertNotPushed(RunSubtitleAdvisor::class);
})->with([
    'ai disabled' => [['ai' => false, 'automation' => true]],
    'automation disabled' => [['ai' => true, 'automation' => false]],
]);

test('viewer cannot request a Media Advisor investigation', function (): void {
    $bazarr = ServiceConnection::factory()->bazarr()->create();
    $subtitleCase = SubtitleCase::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'status' => SubtitleCaseStatus::ReplacementEligible,
    ]);

    $this->actingAs(User::factory()->create())
        ->post(route('bazarr.advisor.store', [
            'subtitleCase' => $subtitleCase,
            'connection' => $bazarr->id,
        ]))
        ->assertForbidden();

    Queue::assertNotPushed(RunSubtitleAdvisor::class);
});

test('member requests an eligible case investigation', function (): void {
    $bazarr = ServiceConnection::factory()->bazarr()->create();
    $subtitleCase = SubtitleCase::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'status' => SubtitleCaseStatus::ReplacementEligible,
    ]);

    $this->actingAs(User::factory()->member()->create())
        ->post(route('bazarr.advisor.store', [
            'subtitleCase' => $subtitleCase,
            'connection' => $bazarr->id,
        ]))
        ->assertRedirect();

    Queue::assertPushed(
        RunSubtitleAdvisor::class,
        fn (RunSubtitleAdvisor $runSubtitleAdvisor): bool => $runSubtitleAdvisor->subtitleCaseId === $subtitleCase->id
            && $runSubtitleAdvisor->uniqueId() === 'subtitle-advisor:'.$subtitleCase->id,
    );
    expect($subtitleCase->fresh()->status)->toBe(SubtitleCaseStatus::ReplacementEligible);
});

test('administrator explicitly retries a review case and records the manual action', function (): void {
    $bazarr = ServiceConnection::factory()->bazarr()->create();
    $administrator = User::factory()->admin()->create();
    $subtitleCase = SubtitleCase::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'status' => SubtitleCaseStatus::NeedsReview,
        'failure_reason' => 'No unique automatic candidate was found.',
    ]);

    $this->actingAs($administrator)
        ->post(route('bazarr.advisor.store', [
            'subtitleCase' => $subtitleCase,
            'connection' => $bazarr->id,
        ]), ['confirm_retry' => true])
        ->assertRedirect();

    $subtitleCaseAttempt = SubtitleCaseAttempt::query()->sole();

    expect($subtitleCase->fresh()->status)->toBe(SubtitleCaseStatus::ReplacementEligible)
        ->and($subtitleCaseAttempt->type)->toBe(SubtitleCaseAttemptType::Reconciliation)
        ->and($subtitleCaseAttempt->outcome)->toBe(SubtitleCaseAttemptOutcome::Succeeded)
        ->and($subtitleCaseAttempt->summary)->toBe([
            'result' => 'manual_retry_requested',
            'requested_by_user_id' => $administrator->id,
        ])
        ->and($subtitleCaseAttempt->completed_at)->not->toBeNull();

    Queue::assertPushed(RunSubtitleAdvisor::class, 1);
});

test('review case remains closed without explicit retry confirmation', function (): void {
    $bazarr = ServiceConnection::factory()->bazarr()->create();
    $subtitleCase = SubtitleCase::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'status' => SubtitleCaseStatus::NeedsReview,
    ]);

    $this->actingAs(User::factory()->member()->create())
        ->post(route('bazarr.advisor.store', [
            'subtitleCase' => $subtitleCase,
            'connection' => $bazarr->id,
        ]))
        ->assertSessionHasErrors('confirm_retry');

    expect($subtitleCase->fresh()->status)->toBe(SubtitleCaseStatus::NeedsReview)
        ->and(SubtitleCaseAttempt::query()->count())->toBe(0);
    Queue::assertNotPushed(RunSubtitleAdvisor::class);
});

test('duplicate investigation requests dispatch one unique job', function (): void {
    $bazarr = ServiceConnection::factory()->bazarr()->create();
    $member = User::factory()->member()->create();
    $subtitleCase = SubtitleCase::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'status' => SubtitleCaseStatus::ReplacementEligible,
    ]);
    $route = route('bazarr.advisor.store', [
        'subtitleCase' => $subtitleCase,
        'connection' => $bazarr->id,
    ]);

    $this->actingAs($member)->post($route)->assertRedirect();
    $this->actingAs($member)->post($route)->assertRedirect();

    Queue::assertPushed(RunSubtitleAdvisor::class, 1);
});

test('cases cannot be investigated through another Bazarr connection', function (): void {
    $caseBazarr = ServiceConnection::factory()->bazarr()->create();
    $foreignBazarr = ServiceConnection::factory()->bazarr()->create();
    $subtitleCase = SubtitleCase::factory()->create([
        'bazarr_connection_id' => $caseBazarr->id,
        'status' => SubtitleCaseStatus::ReplacementEligible,
    ]);

    $this->actingAs(User::factory()->member()->create())
        ->post(route('bazarr.advisor.store', [
            'subtitleCase' => $subtitleCase,
            'connection' => $foreignBazarr->id,
        ]))
        ->assertNotFound();

    $this->actingAs(User::factory()->member()->create())
        ->post(route('bazarr.advisor.store', ['subtitleCase' => 999_999]))
        ->assertNotFound();

    Queue::assertNotPushed(RunSubtitleAdvisor::class);
});

test('terminal cases cannot be reopened for Advisor investigation', function (): void {
    $bazarr = ServiceConnection::factory()->bazarr()->create();
    $subtitleCase = SubtitleCase::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'status' => SubtitleCaseStatus::Resolved,
    ]);

    $this->actingAs(User::factory()->member()->create())
        ->post(route('bazarr.advisor.store', [
            'subtitleCase' => $subtitleCase,
            'connection' => $bazarr->id,
        ]))
        ->assertConflict();

    expect($subtitleCase->fresh()->status)->toBe(SubtitleCaseStatus::Resolved);
    Queue::assertNotPushed(RunSubtitleAdvisor::class);
});
