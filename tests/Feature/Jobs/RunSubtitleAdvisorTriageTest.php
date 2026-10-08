<?php

declare(strict_types=1);

use App\Ai\SubtitleAdvisor\SubtitleAdvisorRunContext;
use App\Enums\AiMode;
use App\Enums\ClassificationGate;
use App\Enums\ClassificationVerdict;
use App\Enums\SubtitleCaseAttemptOutcome;
use App\Enums\SubtitleCaseStatus;
use App\Enums\UserRole;
use App\Jobs\RunSubtitleAdvisor;
use App\Models\ClassificationOutcome;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Models\SubtitleCaseAttempt;
use App\Models\User;
use App\Notifications\SubtitleCaseNeedsReview;
use App\Services\Bazarr\SubtitleAdvisorDecider;
use App\Settings\AiSettings;
use App\Settings\BazarrAutomationSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Lottery;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\BooleanAnswer;

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();
    Notification::fake();
    Queue::fake();
    config(['mediamanager.ai.enabled' => true]);
    resolve(AiSettings::class)->setMode(AiMode::Executive);
    resolve(AiSettings::class)->setSubtitleTriageEnabled(true);
    resolve(AiSettings::class)->setSubtitleTriageThreshold(0.3);
    resolve(BazarrAutomationSettings::class)->setConfiguration([
        'enabled' => true,
        'advisor_concurrency' => 1,
    ]);
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->case = SubtitleCase::factory()->create([
        'bazarr_connection_id' => ServiceConnection::factory()->bazarr(),
        'service_connection_id' => ServiceConnection::factory()->radarr(),
        'media_type' => 'movie',
        'scope' => 'movie',
        'status' => SubtitleCaseStatus::ReplacementEligible,
        'required_languages' => [['code' => 'eng']],
    ]);
    Lottery::alwaysLose();
});

afterEach(function (): void {
    app()->forgetInstance(SubtitleAdvisorRunContext::class);
    Lottery::determineResultsNormally();
});

function runTriageAdvisorJob(SubtitleCase $subtitleCase): void
{
    $runSubtitleAdvisor = new RunSubtitleAdvisor($subtitleCase->id);

    app()->call($runSubtitleAdvisor->handle(...));
}

test('a case triaged below the threshold goes to review without running the Advisor', function (): void {
    Classification::fake([['decision' => new BooleanAnswer(0.1)]]);
    $this->mock(SubtitleAdvisorDecider::class)->shouldNotReceive('decide');

    runTriageAdvisorJob($this->case);

    $subtitleCaseAttempt = SubtitleCaseAttempt::query()->latest('id')->firstOrFail();

    expect($subtitleCaseAttempt->error_category)->toBe('triaged_out')
        ->and($subtitleCaseAttempt->outcome)->toBe(SubtitleCaseAttemptOutcome::NeedsReview)
        ->and($subtitleCaseAttempt->summary['triage_probability'])->toBe(0.1)
        ->and($subtitleCaseAttempt->summary['result'])->toBe('needs_review')
        ->and($subtitleCaseAttempt->summary['summary'])->toContain('10%')
        ->and($this->case->fresh()->status)->toBe(SubtitleCaseStatus::NeedsReview);
    Notification::assertSentTo($this->admin, SubtitleCaseNeedsReview::class);
});

test('a case triaged above the threshold runs the Advisor', function (): void {
    Classification::fake([['decision' => new BooleanAnswer(0.8)]]);
    $this->mock(SubtitleAdvisorDecider::class)
        ->shouldReceive('decide')
        ->once()
        ->andReturn('No unique automatic candidate was found.');

    runTriageAdvisorJob($this->case);

    expect(SubtitleCaseAttempt::query()->latest('id')->firstOrFail()->error_category)
        ->toBe('no_automatic_candidate');
});

test('a classifier failure fails open and runs the Advisor', function (): void {
    Classification::fake(fn () => throw new RuntimeException('down'));
    $this->mock(SubtitleAdvisorDecider::class)
        ->shouldReceive('decide')
        ->once()
        ->andReturn('No unique automatic candidate was found.');

    runTriageAdvisorJob($this->case);
});

test('disabled triage never classifies', function (): void {
    resolve(AiSettings::class)->setSubtitleTriageEnabled(false);
    Classification::fake([['decision' => new BooleanAnswer(0.0)]]);
    $this->mock(SubtitleAdvisorDecider::class)
        ->shouldReceive('decide')
        ->once()
        ->andReturn('No unique automatic candidate was found.');

    runTriageAdvisorJob($this->case);

    Classification::assertNothingClassified();
});

test('a case triaged above the threshold records a passed outcome', function (): void {
    Classification::fake([['decision' => new BooleanAnswer(0.8)]]);
    $this->mock(SubtitleAdvisorDecider::class)
        ->shouldReceive('decide')
        ->once()
        ->andReturn('No unique automatic candidate was found.');

    runTriageAdvisorJob($this->case);

    expect(ClassificationOutcome::sole())
        ->gate->toBe(ClassificationGate::SubtitleTriage)
        ->verdict->toBe(ClassificationVerdict::Passed)
        ->threshold->toBe(0.3)
        ->subject_key->toBe(ClassificationOutcome::subjectKey('subtitle_case', $this->case->id));
});

test('a passed case the advisor sends to review resolves the triage outcome as negative', function (): void {
    Classification::fake([['decision' => new BooleanAnswer(0.8)]]);
    $this->mock(SubtitleAdvisorDecider::class)
        ->shouldReceive('decide')
        ->once()
        ->andReturn('No unique automatic candidate was found.');

    runTriageAdvisorJob($this->case);

    expect(ClassificationOutcome::sole())->outcome_positive->toBeFalse()->outcome_detail->toBe('needs_review');
});

test('a sampled below-threshold case runs the advisor as an audit run', function (): void {
    Lottery::alwaysWin();
    Classification::fake([['decision' => new BooleanAnswer(0.05)]]);
    $this->mock(SubtitleAdvisorDecider::class)
        ->shouldReceive('decide')
        ->once()
        ->andReturn('No unique automatic candidate was found.');

    runTriageAdvisorJob($this->case);

    expect(ClassificationOutcome::sole()->verdict)->toBe(ClassificationVerdict::AuditRun)
        ->and(SubtitleCaseAttempt::query()->latest('id')->firstOrFail()->error_category)->not->toBe('triaged_out');
});

test('an unsampled below-threshold case is still triaged out and recorded as skipped', function (): void {
    Lottery::alwaysLose();
    Classification::fake([['decision' => new BooleanAnswer(0.05)]]);
    $this->mock(SubtitleAdvisorDecider::class)->shouldNotReceive('decide');

    runTriageAdvisorJob($this->case);

    expect(ClassificationOutcome::sole())->verdict->toBe(ClassificationVerdict::Skipped)->outcome_at->toBeNull()
        ->and(SubtitleCaseAttempt::query()->latest('id')->firstOrFail()->error_category)->toBe('triaged_out');
});
