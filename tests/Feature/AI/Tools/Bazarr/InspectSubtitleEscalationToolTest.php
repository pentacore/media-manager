<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\SubtitleAdvisor\SubtitleAdvisorRunContext;
use App\Ai\Tools\Bazarr\InspectSubtitleEscalationTool;
use App\Enums\SubtitleCaseStatus;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Services\Bazarr\SubtitleCaseFingerprint;
use App\Settings\MediaReplacementSettings;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->bazarr = ServiceConnection::factory()->bazarr()->create();
    $this->radarr = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr-advisor.test',
        'api_key' => 'secret',
    ]);
    resolve(MediaReplacementSettings::class)->setConfiguration([
        'automatic_selection_enabled' => false,
        'global_languages' => ['English'],
        'scoped_languages' => ['anime' => null, 'tv' => null, 'movie' => null],
        'guidance' => [
            'anime' => ['notes' => '', 'rules' => []],
            'tv' => ['notes' => '', 'rules' => []],
            'movie' => ['notes' => '', 'rules' => []],
        ],
    ]);
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
        'required_languages' => [['code' => 'eng']],
    ]);
    fakeInspectSubtitleEscalationApis();
});

afterEach(function (): void {
    app()->forgetInstance(SubtitleAdvisorRunContext::class);
});

test('it is read-only and returns the bound case projection', function (): void {
    app()->instance(
        SubtitleAdvisorRunContext::class,
        new SubtitleAdvisorRunContext($this->case->id, 1),
    );

    $result = json_decode(new InspectSubtitleEscalationTool()->handle(new Request([
        'case_id' => $this->case->id,
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect(new InspectSubtitleEscalationTool()->risk())->toBe(Risk::Read)
        ->and($result)->toHaveKey('case_id', $this->case->id)
        ->and($result)->toHaveKey('service_connection_id', $this->radarr->id);
});

test('it refuses a request without a bound Advisor context', function (): void {
    $result = json_decode(new InspectSubtitleEscalationTool()->handle(new Request([
        'case_id' => $this->case->id,
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($result)->toHaveKey('error', 'tool_failed');
});

test('it refuses a case other than the one bound to the run', function (): void {
    app()->instance(SubtitleAdvisorRunContext::class, new SubtitleAdvisorRunContext(999, 1));

    $result = json_decode(new InspectSubtitleEscalationTool()->handle(new Request([
        'case_id' => $this->case->id,
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($result)->toHaveKey('error', 'tool_failed');
});

function fakeInspectSubtitleEscalationApis(): void
{
    Http::fake([
        'radarr-advisor.test/api/v3/movie/201' => Http::response([
            'id' => 201,
            'title' => 'Advisor Movie',
            'movieFileId' => 501,
            'monitored' => true,
        ]),
        'radarr-advisor.test/api/v3/moviefile/501' => Http::response([
            'id' => 501,
            'movieId' => 201,
            'sceneName' => 'Movie.2026.WEB',
            'size' => 1_000,
            'dateAdded' => '2026-07-01T00:00:00Z',
            'mediaInfo' => ['subtitles' => 'Japanese'],
        ]),
        'radarr-advisor.test/api/v3/history*' => Http::response(['records' => []]),
        'radarr-advisor.test/api/v3/release*' => Http::response([]),
    ]);
}
