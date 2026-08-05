<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\SubtitleAdvisor\SubtitleAdvisorRunContext;
use App\Ai\Tools\Bazarr\QueueAutomaticReplacementTool;
use App\Enums\AiMode;
use App\Enums\SubtitleCaseAttemptOutcome;
use App\Enums\SubtitleCaseAttemptType;
use App\Enums\SubtitleCaseStatus;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Models\SubtitleCaseAttempt;
use App\Services\Bazarr\SubtitleCaseFingerprint;
use App\Services\MediaReplacement\ReleaseFingerprint;
use App\Settings\AiSettings;
use App\Settings\MediaReplacementSettings;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Queue::fake();
    resolve(AiSettings::class)->setMode(AiMode::Executive);
    $this->seed(ActionTypeConfigSeeder::class);

    $this->bazarr = ServiceConnection::factory()->bazarr()->create();
    $this->radarr = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr-queue-advisor.test',
        'api_key' => 'secret',
    ]);
    configureAutomaticAdvisorReplacement(true);

    $requiredLanguages = [['code' => 'eng']];
    $this->case = SubtitleCase::factory()->create([
        'bazarr_connection_id' => $this->bazarr->id,
        'service_connection_id' => $this->radarr->id,
        'media_type' => 'movie',
        'scope' => 'movie',
        'status' => SubtitleCaseStatus::AdvisorRunning,
        'target_ids' => ['radarr_id' => 201, 'movie_file_id' => 501],
        'file_fingerprint' => resolve(SubtitleCaseFingerprint::class)->file([
            'service' => 'radarr',
            'service_connection_id' => $this->radarr->id,
            'file_ids' => [501],
            'media_ids' => [201],
            'size' => 1_000,
            'date_added' => '2026-07-01T00:00:00Z',
            'scene_name' => 'Movie.2026.WEB',
        ]),
        'required_languages' => $requiredLanguages,
        'requirements_fingerprint' => resolve(SubtitleCaseFingerprint::class)
            ->requirements('movie', ['eng']),
    ]);
    $this->attempt = SubtitleCaseAttempt::factory()->for($this->case)->create([
        'type' => SubtitleCaseAttemptType::Advisor,
        'outcome' => SubtitleCaseAttemptOutcome::Started,
        'summary' => ['result' => 'started'],
        'completed_at' => null,
    ]);
    $this->context = new SubtitleAdvisorRunContext($this->case->id, 1);
    app()->instance(SubtitleAdvisorRunContext::class, $this->context);
    fakeQueueAutomaticReplacementApis();
});

afterEach(function (): void {
    app()->forgetInstance(SubtitleAdvisorRunContext::class);
});

test('it queues the unique automatic candidate and records the action in the run context', function (): void {
    $result = runAutomaticReplacementTool($this->case);

    expect(new QueueAutomaticReplacementTool()->risk())->toBe(Risk::Destructive)
        ->and($result['queued'])->toBeTrue()
        ->and($result['requires_approval'])->toBeTrue()
        ->and($this->context->actionRequestId())->toBe($result['action_request_id'])
        ->and($this->attempt->fresh()->action_request_id)->toBe($result['action_request_id'])
        ->and($this->attempt->fresh()->candidate_count)->toBe(1)
        ->and($this->attempt->fresh()->eligible_candidate_count)->toBe(1)
        ->and($this->case->fresh()->status)->toBe(SubtitleCaseStatus::ReplacementRequested)
        ->and($this->case->fresh()->replacement_action_request_id)->toBe($result['action_request_id']);

    $payload = ActionRequest::query()->findOrFail($result['action_request_id'])->payload;

    expect($payload)
        ->toMatchArray([
            'subtitle_case_id' => $this->case->id,
            'service_connection_id' => $this->radarr->id,
            'selection_mode' => 'automatic',
            'candidate_fingerprint' => advisorReleaseFingerprint(),
            'required_languages' => ['eng'],
        ]);

    // The advisor, the arr tool and the automatic subtitle check all dispatch
    // `replace_media_file`, and the executor reads the payload by key. This pins the
    // exact set and order the shared ReplacementRequestBuilder emits, so a payload
    // hand-rolled here again — or a key quietly added or reordered — fails loudly
    // rather than reaching the executor in a shape only one caller produces.
    // toMatchArray above is order-insensitive and subset-only, so it cannot do this.
    expect(array_keys($payload))->toBe([
        'title',
        'detail',
        'service',
        'service_connection_id',
        'scope',
        'target',
        'candidate_fingerprint',
        'candidate',
        'required_languages',
        'confidence',
        'matched_rules',
        'selection_mode',
        'agent_rationale',
        'original_history_id',
        'subtitle_case_id',
    ]);
});

test('it allows Action Rules to auto-approve the replacement request', function (): void {
    ActionTypeConfig::query()
        ->where('type', 'replace_media_file')
        ->update(['requires_approval' => false]);

    $result = runAutomaticReplacementTool($this->case);

    expect($result['queued'])->toBeTrue()
        ->and($result['requires_approval'])->toBeFalse()
        ->and($result['status'])->toBe('approved');
    Queue::assertNotPushed(ExecuteActionRequest::class);
});

test('it refuses missing or mismatched run context', function (): void {
    app()->forgetInstance(SubtitleAdvisorRunContext::class);
    $missing = runAutomaticReplacementTool($this->case);

    app()->instance(SubtitleAdvisorRunContext::class, new SubtitleAdvisorRunContext(999, 1));
    $mismatched = runAutomaticReplacementTool($this->case);

    expect($missing)->toHaveKey('error', 'tool_failed')
        ->and($mismatched)->toHaveKey('error', 'tool_failed')
        ->and(ActionRequest::query()->count())->toBe(0);
});

test('it refuses a case in the wrong status', function (): void {
    $this->case->update(['status' => SubtitleCaseStatus::NeedsReview]);

    expect(runAutomaticReplacementTool($this->case))->toHaveKey('error', 'tool_failed')
        ->and(ActionRequest::query()->count())->toBe(0);
});

test('it refuses when there is no unique automatic candidate', function (): void {
    configureAutomaticAdvisorReplacement(false);

    expect(runAutomaticReplacementTool($this->case))->toHaveKey('error', 'tool_failed')
        ->and(ActionRequest::query()->count())->toBe(0);
});

test('it refuses a fingerprint other than the recomputed automatic candidate', function (): void {
    $result = runAutomaticReplacementTool($this->case, 'stale-fingerprint');

    expect($result)->toHaveKey('error', 'tool_failed')
        ->and(ActionRequest::query()->count())->toBe(0);
});

test('it refuses a changed installed file or changed requirements', function (): void {
    $validFileFingerprint = $this->case->file_fingerprint;
    $this->case->update([
        'file_fingerprint' => resolve(SubtitleCaseFingerprint::class)->file([
            'service' => 'radarr',
            'service_connection_id' => $this->radarr->id,
            'file_ids' => [501],
            'media_ids' => [201],
            'size' => 2_000,
            'date_added' => '2026-07-01T00:00:00Z',
            'scene_name' => 'Movie.2026.WEB',
        ]),
    ]);
    $changedFile = runAutomaticReplacementTool($this->case);

    $this->case->update([
        'file_fingerprint' => $validFileFingerprint,
        'requirements_fingerprint' => str_repeat('a', 64),
    ]);
    $changedRequirements = runAutomaticReplacementTool($this->case);

    expect($changedFile)->toHaveKey('error', 'tool_failed')
        ->and($changedRequirements)->toHaveKey('error', 'tool_failed')
        ->and(ActionRequest::query()->count())->toBe(0);
});

test('it respects disabled Action Rules and Advisory mode', function (): void {
    ActionTypeConfig::query()
        ->where('type', 'replace_media_file')
        ->update(['is_enabled' => false]);
    $disabled = runAutomaticReplacementTool($this->case);

    ActionTypeConfig::query()
        ->where('type', 'replace_media_file')
        ->update(['is_enabled' => true]);
    resolve(AiSettings::class)->setMode(AiMode::Advisory);
    $advisory = runAutomaticReplacementTool($this->case);

    expect($disabled)->toMatchArray(['queued' => false, 'reason' => 'no_action_type_config'])
        ->and($advisory)->toHaveKey('error', 'advisory_mode_blocks_destructive')
        ->and(ActionRequest::query()->count())->toBe(0);
});

/**
 * @return array<string, mixed>
 */
function runAutomaticReplacementTool(SubtitleCase $subtitleCase, ?string $fingerprint = null): array
{
    return json_decode(new QueueAutomaticReplacementTool()->handle(new Request([
        'case_id' => $subtitleCase->id,
        'candidate_fingerprint' => $fingerprint ?? advisorReleaseFingerprint(),
        'reason' => 'Bazarr could not find the required English subtitles.',
    ])), true, flags: JSON_THROW_ON_ERROR);
}

function advisorReleaseFingerprint(): string
{
    return resolve(ReleaseFingerprint::class)->make('radarr', advisorRelease());
}

/**
 * @return array<string, mixed>
 */
function advisorRelease(): array
{
    return [
        'guid' => 'advisor-guid',
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

function fakeQueueAutomaticReplacementApis(int $size = 1_000): void
{
    Http::fake([
        'radarr-queue-advisor.test/api/v3/movie/201' => Http::response([
            'id' => 201,
            'title' => 'Advisor Movie',
            'movieFileId' => 501,
            'monitored' => true,
        ]),
        'radarr-queue-advisor.test/api/v3/moviefile/501' => Http::response([
            'id' => 501,
            'movieId' => 201,
            'sceneName' => 'Movie.2026.WEB',
            'size' => $size,
            'dateAdded' => '2026-07-01T00:00:00Z',
            'mediaInfo' => ['subtitles' => 'Japanese'],
        ]),
        'radarr-queue-advisor.test/api/v3/history*' => Http::response(['records' => []]),
        'radarr-queue-advisor.test/api/v3/release*' => Http::response([advisorRelease()]),
    ]);
}

function configureAutomaticAdvisorReplacement(bool $automatic): void
{
    resolve(MediaReplacementSettings::class)->setConfiguration([
        'automatic_selection_enabled' => $automatic,
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
