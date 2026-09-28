<?php

declare(strict_types=1);

use App\Ai\SubtitleAdvisor\SubtitleAdvisorRunContext;
use App\Enums\ActionRequestStatus;
use App\Enums\AiMode;
use App\Enums\SubtitleCaseAttemptOutcome;
use App\Enums\SubtitleCaseAttemptType;
use App\Enums\SubtitleCaseStatus;
use App\Enums\UserRole;
use App\Jobs\ExecuteActionRequest;
use App\Jobs\Middleware\LimitSubtitleAdvisorConcurrency;
use App\Jobs\RunSubtitleAdvisor;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\AiUsageRecord;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Models\SubtitleCaseAttempt;
use App\Models\User;
use App\Notifications\SubtitleCaseNeedsReview;
use App\Services\AiBudget\AiBudgetExceededException;
use App\Services\AiBudget\AiBudgetGuard;
use App\Services\Bazarr\SubtitleAdvisorDecider;
use App\Services\Bazarr\SubtitleCaseFingerprint;
use App\Settings\AiSettings;
use App\Settings\BazarrAutomationSettings;
use App\Settings\MediaReplacementSettings;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();
    Notification::fake();
    Queue::fake();
    config(['mediamanager.ai.enabled' => true]);
    resolve(AiSettings::class)->setMode(AiMode::Executive);
    resolve(BazarrAutomationSettings::class)->setConfiguration([
        'enabled' => true,
        'advisor_concurrency' => 1,
    ]);
    configureAdvisorJobReplacement();
    $this->seed(ActionTypeConfigSeeder::class);
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->bazarr = ServiceConnection::factory()->bazarr()->create();
    $this->radarr = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr-advisor-job.test',
        'api_key' => 'secret',
    ]);
    $this->case = advisorJobCase($this->bazarr, $this->radarr);
    fakeAdvisorJobApis();
});

afterEach(function (): void {
    app()->forgetInstance(SubtitleAdvisorRunContext::class);
});

test('the Advisor job is unique and waits for concurrency without retrying exceptions', function (): void {
    $job = new RunSubtitleAdvisor(42);
    $reflection = new ReflectionClass($job);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('subtitle-advisor:42')
        ->and($reflection->getAttributes(Tries::class)[0]->newInstance()->tries)->toBe(0)
        ->and($reflection->getAttributes(MaxExceptions::class)[0]->newInstance()->maxExceptions)->toBe(1)
        ->and($reflection->getAttributes(FailOnTimeout::class))->toHaveCount(1)
        ->and($reflection->getAttributes(Timeout::class)[0]->newInstance()->timeout)->toBe(180)
        ->and($reflection->getAttributes(UniqueFor::class)[0]->newInstance()->uniqueFor)->toBe(1080)
        ->and($job->middleware())->toHaveCount(1)
        ->and($job->middleware()[0])->toBeInstanceOf(LimitSubtitleAdvisorConcurrency::class)
        ->and($job->retryUntil()->getTimestamp())
        ->toBeGreaterThan(now()->addMinutes(10)->getTimestamp());
});

test('concurrency middleware releases before starting when every slot is occupied', function (): void {
    $runSubtitleAdvisor = new RunSubtitleAdvisor($this->case->id)->withFakeQueueInteractions();
    $ran = false;

    Cache::funnel('bazarr-advisor')
        ->limit(1)
        ->releaseAfter(240)
        ->block(0)
        ->then(function () use ($runSubtitleAdvisor, &$ran): void {
            new LimitSubtitleAdvisorConcurrency()->handle(
                $runSubtitleAdvisor,
                function () use (&$ran): void {
                    $ran = true;
                },
            );
        });

    expect($ran)->toBeFalse();
    $runSubtitleAdvisor->assertReleased(delay: 10);
});

test('a failure before an Advisor attempt leaves the eligible case retryable', function (): void {
    new RunSubtitleAdvisor($this->case->id)->failed(new RuntimeException('worker stopped before start'));

    expect($this->case->fresh()->status)->toBe(SubtitleCaseStatus::ReplacementEligible)
        ->and(SubtitleCaseAttempt::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

test('disabled AI or automation skips without changing the case', function (string $disabled): void {
    if ($disabled === 'ai') {
        config(['mediamanager.ai.enabled' => false]);
    } else {
        resolve(BazarrAutomationSettings::class)->setConfiguration(['enabled' => false]);
    }

    $this->mock(SubtitleAdvisorDecider::class)->shouldNotReceive('decide');

    runAdvisorJob(new RunSubtitleAdvisor($this->case->id));

    expect($this->case->fresh()->status)->toBe(SubtitleCaseStatus::ReplacementEligible)
        ->and(SubtitleCaseAttempt::query()->count())->toBe(0);
})->with(['ai', 'automation']);

test('a case outside replacement eligible is ignored', function (): void {
    $this->case->update(['status' => SubtitleCaseStatus::NeedsReview]);
    $this->mock(SubtitleAdvisorDecider::class)->shouldNotReceive('decide');

    runAdvisorJob(new RunSubtitleAdvisor($this->case->id));

    expect(SubtitleCaseAttempt::query()->count())->toBe(0);
});

test('budget exhaustion records one failed attempt and routes the case to review', function (): void {
    $this->mock(AiBudgetGuard::class)
        ->shouldReceive('enforce')
        ->once()
        ->andThrow(new AiBudgetExceededException(10.0, 5.0));
    $this->mock(SubtitleAdvisorDecider::class)->shouldNotReceive('decide');

    runAdvisorJob(new RunSubtitleAdvisor($this->case->id));

    $subtitleCaseAttempt = SubtitleCaseAttempt::query()->sole();

    expect($this->case->fresh()->status)->toBe(SubtitleCaseStatus::NeedsReview)
        ->and($subtitleCaseAttempt->type)->toBe(SubtitleCaseAttemptType::Advisor)
        ->and($subtitleCaseAttempt->outcome)->toBe(SubtitleCaseAttemptOutcome::Failed)
        ->and($subtitleCaseAttempt->error_category)->toBe('budget_exceeded');
    Notification::assertSentTo($this->admin, SubtitleCaseNeedsReview::class);
});

test('a pending automatic replacement is linked and transitions the case', function (): void {
    runAdvisorJob(new RunSubtitleAdvisor($this->case->id));

    $actionRequest = ActionRequest::query()->sole();
    $subtitleCaseAttempt = SubtitleCaseAttempt::query()->sole();

    expect($actionRequest->status->value)->toBe('pending')
        ->and($this->case->fresh()->status)->toBe(SubtitleCaseStatus::ReplacementRequested)
        ->and($this->case->fresh()->replacement_action_request_id)->toBe($actionRequest->id)
        ->and($subtitleCaseAttempt->action_request_id)->toBe($actionRequest->id)
        ->and($subtitleCaseAttempt->outcome)->toBe(SubtitleCaseAttemptOutcome::Succeeded)
        ->and(app()->bound(SubtitleAdvisorRunContext::class))->toBeFalse();
});

test('an auto-approved replacement is linked without creating a second action', function (): void {
    ActionTypeConfig::query()
        ->where('type', 'replace_media_file')
        ->update(['requires_approval' => false]);

    runAdvisorJob(new RunSubtitleAdvisor($this->case->id));

    expect(ActionRequest::query()->count())->toBe(1)
        ->and(ActionRequest::query()->sole()->status->value)->toBe('approved')
        ->and($this->case->fresh()->status)->toBe(SubtitleCaseStatus::ReplacementRequested);
    Queue::assertPushed(ExecuteActionRequest::class, 1);
});

test('a replacement queued before the decision failed is still linked', function (): void {
    $this->mock(SubtitleAdvisorDecider::class)
        ->shouldReceive('decide')
        ->once()
        ->andReturnUsing(function (SubtitleCase $subtitleCase): never {
            $actionRequest = ActionRequest::factory()->create([
                'type' => 'replace_media_file',
                'source_service' => 'subtitle_advisor',
                'target_service' => 'radarr',
                'status' => ActionRequestStatus::Approved,
                'requires_approval' => false,
                'payload' => ['subtitle_case_id' => $subtitleCase->id],
            ]);
            resolve(SubtitleAdvisorRunContext::class)->recordQueued($actionRequest->id);

            throw new RuntimeException('failed after queueing');
        });

    runAdvisorJob(new RunSubtitleAdvisor($this->case->id));

    $actionRequest = ActionRequest::query()->sole();
    $subtitleCaseAttempt = SubtitleCaseAttempt::query()->sole();

    expect($this->case->fresh()->status)->toBe(SubtitleCaseStatus::ReplacementRequested)
        ->and($this->case->fresh()->replacement_action_request_id)->toBe($actionRequest->id)
        ->and($subtitleCaseAttempt->action_request_id)->toBe($actionRequest->id)
        ->and($subtitleCaseAttempt->outcome)->toBe(SubtitleCaseAttemptOutcome::Succeeded);
    Queue::assertPushed(ExecuteActionRequest::class, 1);
    Notification::assertNothingSent();
});

test('no queued action becomes durable human review with a bounded summary', function (): void {
    $this->mock(SubtitleAdvisorDecider::class)
        ->shouldReceive('decide')
        ->once()
        ->andReturn(str_repeat('N', 5_000));

    runAdvisorJob(new RunSubtitleAdvisor($this->case->id));

    $subtitleCaseAttempt = SubtitleCaseAttempt::query()->sole();

    expect($this->case->fresh()->status)->toBe(SubtitleCaseStatus::NeedsReview)
        ->and(ActionRequest::query()->count())->toBe(0)
        ->and($subtitleCaseAttempt->outcome)->toBe(SubtitleCaseAttemptOutcome::NeedsReview)
        ->and(mb_strlen((string) $subtitleCaseAttempt->summary['summary']))->toBeLessThanOrEqual(4_000)
        ->and(app()->bound(SubtitleAdvisorRunContext::class))->toBeFalse();
    Notification::assertSentTo($this->admin, SubtitleCaseNeedsReview::class);
});

test('a decider exception fails once, clears scoped state, and notifies review', function (): void {
    $this->mock(SubtitleAdvisorDecider::class)
        ->shouldReceive('decide')
        ->once()
        ->andThrow(new RuntimeException('inspection exploded'));

    runAdvisorJob(new RunSubtitleAdvisor($this->case->id));

    $subtitleCaseAttempt = SubtitleCaseAttempt::query()->sole();

    expect($this->case->fresh()->status)->toBe(SubtitleCaseStatus::NeedsReview)
        ->and($subtitleCaseAttempt->outcome)->toBe(SubtitleCaseAttemptOutcome::Failed)
        ->and($subtitleCaseAttempt->error_category)->toBe('agent_failure')
        ->and(app()->bound(SubtitleAdvisorRunContext::class))->toBeFalse();
    Notification::assertSentTo($this->admin, SubtitleCaseNeedsReview::class);
});

test('the worker failed callback terminalizes a started run without retrying', function (): void {
    $this->case->update(['status' => SubtitleCaseStatus::AdvisorRunning]);
    SubtitleCaseAttempt::factory()->for($this->case)->create([
        'type' => SubtitleCaseAttemptType::Advisor,
        'outcome' => SubtitleCaseAttemptOutcome::Started,
        'completed_at' => null,
    ]);

    new RunSubtitleAdvisor($this->case->id)->failed(new RuntimeException('worker stopped'));

    expect($this->case->fresh()->status)->toBe(SubtitleCaseStatus::NeedsReview)
        ->and(SubtitleCaseAttempt::query()->sole()->fresh()->outcome)
        ->toBe(SubtitleCaseAttemptOutcome::Failed);
    Notification::assertSentTo($this->admin, SubtitleCaseNeedsReview::class);
});

test('the worker failed callback recovers a durably recorded replacement action', function (): void {
    $this->case->update(['status' => SubtitleCaseStatus::AdvisorRunning]);
    $actionRequest = ActionRequest::factory()->create([
        'type' => 'replace_media_file',
        'source_service' => 'subtitle_advisor',
        'target_service' => 'radarr',
        'status' => ActionRequestStatus::Approved,
        'requires_approval' => false,
        'payload' => ['subtitle_case_id' => $this->case->id],
    ]);
    $attempt = SubtitleCaseAttempt::factory()->for($this->case)->create([
        'action_request_id' => $actionRequest->id,
        'type' => SubtitleCaseAttemptType::Advisor,
        'outcome' => SubtitleCaseAttemptOutcome::Started,
        'summary' => ['result' => 'started'],
        'completed_at' => null,
    ]);

    new RunSubtitleAdvisor($this->case->id)->failed(new RuntimeException('worker stopped after queueing'));

    expect($this->case->fresh()->status)->toBe(SubtitleCaseStatus::ReplacementRequested)
        ->and($this->case->fresh()->replacement_action_request_id)->toBe($actionRequest->id)
        ->and($attempt->fresh()->outcome)->toBe(SubtitleCaseAttemptOutcome::Succeeded);
    Queue::assertPushed(ExecuteActionRequest::class, 1);
    Notification::assertNothingSent();
});

test('a redelivered job recovers a durably recorded replacement action', function (
    SubtitleCaseStatus $subtitleCaseStatus,
): void {
    $actionRequest = ActionRequest::factory()->create([
        'type' => 'replace_media_file',
        'source_service' => 'subtitle_advisor',
        'target_service' => 'radarr',
        'status' => ActionRequestStatus::Approved,
        'requires_approval' => false,
        'payload' => ['subtitle_case_id' => $this->case->id],
    ]);
    $this->case->forceFill([
        'status' => $subtitleCaseStatus,
        'replacement_action_request_id' => $subtitleCaseStatus === SubtitleCaseStatus::ReplacementRequested
            ? $actionRequest->id
            : null,
    ])->save();
    $attempt = SubtitleCaseAttempt::factory()->for($this->case)->create([
        'action_request_id' => $actionRequest->id,
        'type' => SubtitleCaseAttemptType::Advisor,
        'outcome' => SubtitleCaseAttemptOutcome::Started,
        'summary' => ['result' => 'started'],
        'completed_at' => null,
    ]);
    $this->mock(SubtitleAdvisorDecider::class)->shouldNotReceive('decide');

    runAdvisorJob(new RunSubtitleAdvisor($this->case->id));

    expect($this->case->fresh()->status)->toBe(SubtitleCaseStatus::ReplacementRequested)
        ->and($this->case->fresh()->replacement_action_request_id)->toBe($actionRequest->id)
        ->and($attempt->fresh()->outcome)->toBe(SubtitleCaseAttemptOutcome::Succeeded);
    Queue::assertPushed(ExecuteActionRequest::class, 1);
    Notification::assertNothingSent();
})->with([
    SubtitleCaseStatus::AdvisorRunning,
    SubtitleCaseStatus::ReplacementRequested,
]);

test('a redelivered started run without an action becomes review instead of deciding again', function (): void {
    $this->case->update(['status' => SubtitleCaseStatus::AdvisorRunning]);
    $attempt = SubtitleCaseAttempt::factory()->for($this->case)->create([
        'type' => SubtitleCaseAttemptType::Advisor,
        'outcome' => SubtitleCaseAttemptOutcome::Started,
        'summary' => ['result' => 'started'],
        'completed_at' => null,
    ]);
    $this->mock(SubtitleAdvisorDecider::class)->shouldNotReceive('decide');

    runAdvisorJob(new RunSubtitleAdvisor($this->case->id));

    expect($this->case->fresh()->status)->toBe(SubtitleCaseStatus::NeedsReview)
        ->and($attempt->fresh()->outcome)->toBe(SubtitleCaseAttemptOutcome::Failed)
        ->and($attempt->fresh()->error_category)->toBe('worker_interrupted');
    Notification::assertSentTo($this->admin, SubtitleCaseNeedsReview::class);
});

test('redelivery recovery runs before feature gates', function (string $disabled): void {
    $actionRequest = ActionRequest::factory()->create([
        'type' => 'replace_media_file',
        'source_service' => 'subtitle_advisor',
        'target_service' => 'radarr',
        'status' => ActionRequestStatus::Approved,
        'requires_approval' => false,
        'payload' => ['subtitle_case_id' => $this->case->id],
    ]);
    $this->case->forceFill([
        'status' => SubtitleCaseStatus::ReplacementRequested,
        'replacement_action_request_id' => $actionRequest->id,
    ])->save();
    $attempt = SubtitleCaseAttempt::factory()->for($this->case)->create([
        'action_request_id' => $actionRequest->id,
        'type' => SubtitleCaseAttemptType::Advisor,
        'outcome' => SubtitleCaseAttemptOutcome::Started,
        'summary' => ['result' => 'started'],
        'completed_at' => null,
    ]);

    if ($disabled === 'ai') {
        config(['mediamanager.ai.enabled' => false]);
    } else {
        resolve(BazarrAutomationSettings::class)->setConfiguration(['enabled' => false]);
    }

    $this->mock(SubtitleAdvisorDecider::class)->shouldNotReceive('decide');

    runAdvisorJob(new RunSubtitleAdvisor($this->case->id));

    expect($attempt->fresh()->outcome)->toBe(SubtitleCaseAttemptOutcome::Succeeded);
    Queue::assertPushed(ExecuteActionRequest::class, 1);
    Notification::assertNothingSent();
})->with(['ai', 'automation']);

test('two jobs for one case produce at most one run and one action request', function (): void {
    $first = new RunSubtitleAdvisor($this->case->id);
    $second = new RunSubtitleAdvisor($this->case->id);

    runAdvisorJob($first);
    runAdvisorJob($second);

    expect(SubtitleCaseAttempt::query()->count())->toBe(1)
        ->and(ActionRequest::query()->count())->toBe(1);
});

test('the automatic candidate is queued in code with a templated summary and no model call', function (): void {
    runAdvisorJob(new RunSubtitleAdvisor($this->case->id));

    $subtitleCaseAttempt = SubtitleCaseAttempt::query()->sole();

    expect($this->case->fresh()->status)->toBe(SubtitleCaseStatus::ReplacementRequested)
        ->and($subtitleCaseAttempt->outcome)->toBe(SubtitleCaseAttemptOutcome::Succeeded)
        ->and($subtitleCaseAttempt->summary['summary'])
        ->toStartWith('Queued the unique automatic replacement candidate "Advisor.Movie.2026.CR" for ')
        ->toContain('(required subtitles: ')
        ->toEndWith('The replacement is requested, not yet verified.')
        ->and(ActionRequest::query()->sole()->payload['subtitle_case_id'])->toBe($this->case->id)
        ->and(AiUsageRecord::query()->count())->toBe(0);
});

test('without an automatic candidate the case goes to review with a templated summary', function (): void {
    configureAdvisorJobReplacement(automaticSelection: false);

    runAdvisorJob(new RunSubtitleAdvisor($this->case->id));

    $subtitleCaseAttempt = SubtitleCaseAttempt::query()->sole();

    expect($this->case->fresh()->status)->toBe(SubtitleCaseStatus::NeedsReview)
        ->and($subtitleCaseAttempt->outcome)->toBe(SubtitleCaseAttemptOutcome::NeedsReview)
        ->and($subtitleCaseAttempt->error_category)->toBe('no_automatic_candidate')
        ->and($subtitleCaseAttempt->summary['summary'])->toStartWith('No unique automatic replacement candidate for ')
        ->and(ActionRequest::query()->count())->toBe(0)
        ->and(AiUsageRecord::query()->count())->toBe(0);
    Notification::assertSentTo($this->admin, SubtitleCaseNeedsReview::class);
});

test('advisory mode leaves the automatic candidate for human review', function (): void {
    resolve(AiSettings::class)->setMode(AiMode::Advisory);

    runAdvisorJob(new RunSubtitleAdvisor($this->case->id));

    $subtitleCaseAttempt = SubtitleCaseAttempt::query()->sole();

    expect($this->case->fresh()->status)->toBe(SubtitleCaseStatus::NeedsReview)
        ->and($subtitleCaseAttempt->error_category)->toBe('no_automatic_candidate')
        ->and($subtitleCaseAttempt->summary['summary'])->toContain('(advisory_mode_blocks_destructive)')
        ->and(ActionRequest::query()->count())->toBe(0);
});

test('a case whose file changed goes to review instead of queueing', function (): void {
    $this->case->update(['file_fingerprint' => str_repeat('0', 64)]);

    runAdvisorJob(new RunSubtitleAdvisor($this->case->id));

    $subtitleCaseAttempt = SubtitleCaseAttempt::query()->sole();

    expect($this->case->fresh()->status)->toBe(SubtitleCaseStatus::NeedsReview)
        ->and($subtitleCaseAttempt->error_category)->toBe('no_automatic_candidate')
        ->and($subtitleCaseAttempt->summary['summary'])->toContain('The installed file changed after the subtitle case was observed.')
        ->and(ActionRequest::query()->count())->toBe(0);
});

test('a tool-level rejection surfaces its specific reason instead of a generic not-queued message', function (): void {
    $this->case->update(['requirements_fingerprint' => str_repeat('0', 64)]);

    runAdvisorJob(new RunSubtitleAdvisor($this->case->id));

    $subtitleCaseAttempt = SubtitleCaseAttempt::query()->sole();

    expect($this->case->fresh()->status)->toBe(SubtitleCaseStatus::NeedsReview)
        ->and($subtitleCaseAttempt->error_category)->toBe('no_automatic_candidate')
        ->and($subtitleCaseAttempt->summary['summary'])->toContain('The subtitle requirements changed after this case was observed.')
        ->and(ActionRequest::query()->count())->toBe(0);
});

test('an unexpected inspection exception is not swallowed into a routine review', function (): void {
    // Http::preventStrayRequests() (set in beforeEach) throws StrayRequestException
    // — a RuntimeException, not one of the domain/transport types the decider
    // catches — for any URL fakeAdvisorJobApis() didn't stub. Pointing the case
    // at an uncovered movie id reaches that without mocking a final class.
    $this->case->update(['target_ids' => ['radarr_id' => 9_999, 'movie_file_id' => 9_999]]);

    runAdvisorJob(new RunSubtitleAdvisor($this->case->id));

    $subtitleCaseAttempt = SubtitleCaseAttempt::query()->sole();

    expect($this->case->fresh()->status)->toBe(SubtitleCaseStatus::NeedsReview)
        ->and($subtitleCaseAttempt->outcome)->toBe(SubtitleCaseAttemptOutcome::Failed)
        ->and($subtitleCaseAttempt->error_category)->toBe('agent_failure');
    Notification::assertSentTo($this->admin, SubtitleCaseNeedsReview::class);
});

function runAdvisorJob(RunSubtitleAdvisor $runSubtitleAdvisor): void
{
    app()->call($runSubtitleAdvisor->handle(...));
}

function advisorJobCase(ServiceConnection $bazarr, ServiceConnection $radarr): SubtitleCase
{
    return SubtitleCase::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'service_connection_id' => $radarr->id,
        'media_type' => 'movie',
        'scope' => 'movie',
        'status' => SubtitleCaseStatus::ReplacementEligible,
        'target_ids' => ['radarr_id' => 201, 'movie_file_id' => 501],
        'file_fingerprint' => resolve(SubtitleCaseFingerprint::class)->file([
            'service' => 'radarr',
            'service_connection_id' => $radarr->id,
            'file_ids' => [501],
            'media_ids' => [201],
            'size' => 1_000,
            'date_added' => '2026-07-01T00:00:00Z',
            'scene_name' => 'Movie.2026.WEB',
        ]),
        'required_languages' => [['code' => 'eng']],
        'requirements_fingerprint' => resolve(SubtitleCaseFingerprint::class)
            ->requirements('movie', ['eng']),
    ]);
}

/**
 * @return array<string, mixed>
 */
function advisorJobRelease(): array
{
    return [
        'guid' => 'advisor-job-guid',
        'indexerId' => 10,
        'movieId' => 201,
        'title' => 'Advisor.Movie.2026.CR',
        'releaseGroup' => 'Trusted',
        'downloadAllowed' => true,
        'rejections' => [],
        'fullSeason' => false,
        'customFormatScore' => 100,
        'qualityWeight' => 100,
        'seeders' => 20,
        'ageMinutes' => 5,
        'size' => 2_000,
    ];
}

function fakeAdvisorJobApis(): void
{
    Http::fake([
        'radarr-advisor-job.test/api/v3/movie/201' => Http::response([
            'id' => 201,
            'title' => 'Advisor Movie',
            'movieFileId' => 501,
            'monitored' => true,
        ]),
        'radarr-advisor-job.test/api/v3/moviefile/501' => Http::response([
            'id' => 501,
            'movieId' => 201,
            'sceneName' => 'Movie.2026.WEB',
            'size' => 1_000,
            'dateAdded' => '2026-07-01T00:00:00Z',
            'mediaInfo' => ['subtitles' => 'Japanese'],
        ]),
        'radarr-advisor-job.test/api/v3/history*' => Http::response(['records' => []]),
        'radarr-advisor-job.test/api/v3/release*' => Http::response([advisorJobRelease()]),
    ]);
}

function configureAdvisorJobReplacement(bool $automaticSelection = true): void
{
    resolve(MediaReplacementSettings::class)->setConfiguration([
        'automatic_selection_enabled' => $automaticSelection,
        'automatic_selection_threshold' => 90,
        'global_languages' => ['English'],
        'scoped_languages' => ['anime' => null, 'tv' => null, 'movie' => null],
        'season_pack_policy' => 'approval_required',
        'guidance' => [
            'anime' => ['notes' => '', 'rules' => []],
            'tv' => ['notes' => '', 'rules' => []],
            'movie' => [
                'notes' => '',
                'rules' => [[
                    'name' => 'Trusted English',
                    'enabled' => true,
                    'strength' => 'guarantee',
                    'languages' => ['English'],
                    'conditions' => [['field' => 'title', 'value' => 'CR']],
                ]],
            ],
        ],
    ]);
}
