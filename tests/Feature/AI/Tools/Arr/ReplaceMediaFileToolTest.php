<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Arr\ReplaceMediaFileTool;
use App\Enums\AiMode;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\MediaReplacement\ReplacementCandidateFinder;
use App\Settings\AiSettings;
use App\Settings\MediaReplacementSettings;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Cache::flush();
    Queue::fake();
    Http::preventStrayRequests();

    resolve(AiSettings::class)->setMode(AiMode::Executive);

    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
    ]);

    $this->seed(ActionTypeConfigSeeder::class);

    configureReplacementGuidance(automatic: false);
});

/**
 * @param  array<string, mixed>  $releaseOverrides
 */
function fakeReplaceableTarget(array $releaseOverrides = []): void
{
    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response(['id' => 42, 'title' => 'Trusted Anime', 'seriesType' => 'anime']),
        'sonarr.local:8989/api/v3/episode?seriesId=42' => Http::response([
            ['id' => 101, 'seasonNumber' => 1, 'episodeNumber' => 1, 'episodeFileId' => 501],
        ]),
        'sonarr.local:8989/api/v3/episodefile/501' => Http::response([
            'id' => 501, 'sceneName' => 'Trusted.Anime.S01E01.OLD', 'mediaInfo' => ['subtitles' => 'Japanese'],
        ]),
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => [
            ['id' => 999, 'eventType' => 'grabbed', 'episodeId' => 101, 'downloadId' => 'ABC'],
        ]]),
        'sonarr.local:8989/api/v3/release*' => Http::response([array_replace([
            'guid' => 'g1', 'indexerId' => 10, 'title' => 'Trusted.Anime.S01E01.CR',
            'episodeIds' => [101], 'downloadAllowed' => true, 'rejections' => [], 'fullSeason' => false,
            'customFormatScore' => 0, 'qualityWeight' => 100, 'seeders' => 5, 'ageMinutes' => 60,
        ], $releaseOverrides)]),
    ]);
}

function configureReplacementGuidance(bool $automatic): void
{
    resolve(MediaReplacementSettings::class)->setConfiguration([
        'automatic_selection_enabled' => $automatic,
        'automatic_selection_threshold' => 90,
        'global_languages' => ['English'],
        'scoped_languages' => ['anime' => null, 'tv' => null, 'movie' => null],
        'season_pack_policy' => 'approval_required',
        'guidance' => [
            'anime' => [
                'notes' => '',
                'rules' => [[
                    'name' => 'CR', 'enabled' => true, 'strength' => 'guarantee', 'languages' => ['English'],
                    'conditions' => [['field' => 'title', 'value' => 'CR']],
                ]],
            ],
            'tv' => ['notes' => '', 'rules' => []],
            'movie' => ['notes' => '', 'rules' => []],
        ],
    ]);
}

function candidateFingerprint(): string
{
    $result = resolve(ReplacementCandidateFinder::class)->find([
        'service' => 'sonarr', 'scope' => 'anime', 'series_id' => 42, 'episode_ids' => [101], 'installed_release' => 'Trusted.Anime.S01E01.OLD',
    ]);

    return $result['candidates'][0]['fingerprint'];
}

test('the replace tool is destructive', function (): void {
    expect((new ReplaceMediaFileTool)->risk())->toBe(Risk::Destructive);
});

test('manual selection queues an approval-gated replacement action request', function (): void {
    fakeReplaceableTarget();
    $fingerprint = candidateFingerprint();

    $result = json_decode((new ReplaceMediaFileTool)->handle(new Request([
        'service' => 'sonarr', 'item_id' => 42, 'season_number' => 1, 'episode_number' => 1,
        'absolute_episode_number' => null, 'candidate_fingerprint' => $fingerprint,
        'selection_mode' => 'manual', 'required_languages' => ['English'],
        'reason' => 'Current file has no English subtitles.',
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($result['queued'])->toBeTrue()
        ->and($result['requires_approval'])->toBeTrue();

    expect(ActionRequest::first()->payload)->toMatchArray([
        'selection_mode' => 'manual',
        'required_languages' => ['eng'],
        'candidate_fingerprint' => $fingerprint,
        'scope' => 'anime',
        'original_history_id' => 999,
    ]);
});

test('advisory mode blocks the destructive replacement', function (): void {
    resolve(AiSettings::class)->setMode(AiMode::Advisory);
    fakeReplaceableTarget();

    $result = json_decode((new ReplaceMediaFileTool)->handle(new Request([
        'service' => 'sonarr', 'item_id' => 42, 'season_number' => 1, 'episode_number' => 1,
        'absolute_episode_number' => null, 'candidate_fingerprint' => 'anything',
        'selection_mode' => 'manual', 'required_languages' => null, 'reason' => 'x',
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($result['error'])->toBe('advisory_mode_blocks_destructive')
        ->and(ActionRequest::count())->toBe(0);
});

test('automatic selection is rejected when the fingerprint is not the automatic candidate', function (): void {
    configureReplacementGuidance(automatic: true);
    fakeReplaceableTarget();

    $result = json_decode((new ReplaceMediaFileTool)->handle(new Request([
        'service' => 'sonarr', 'item_id' => 42, 'season_number' => 1, 'episode_number' => 1,
        'absolute_episode_number' => null, 'candidate_fingerprint' => 'not-the-automatic-one',
        'selection_mode' => 'automatic', 'required_languages' => ['English'], 'reason' => 'x',
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($result)->toHaveKey('error')
        ->and(ActionRequest::count())->toBe(0);
});

test('automatic selection queues when the fingerprint matches the automatic candidate', function (): void {
    configureReplacementGuidance(automatic: true);
    fakeReplaceableTarget();
    $fingerprint = candidateFingerprint();

    $result = json_decode((new ReplaceMediaFileTool)->handle(new Request([
        'service' => 'sonarr', 'item_id' => 42, 'season_number' => 1, 'episode_number' => 1,
        'absolute_episode_number' => null, 'candidate_fingerprint' => $fingerprint,
        'selection_mode' => 'automatic', 'required_languages' => ['English'], 'reason' => 'x',
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($result['queued'])->toBeTrue();
    expect(ActionRequest::first()->payload['selection_mode'])->toBe('automatic');
});

test('an approval_required season pack forces approval even when the rule auto-executes', function (): void {
    App\Models\ActionTypeConfig::where('type', 'replace_media_file')->update(['requires_approval' => false]);
    fakeReplaceableTarget(['fullSeason' => true]);
    $fingerprint = candidateFingerprint();

    $result = json_decode((new ReplaceMediaFileTool)->handle(new Request([
        'service' => 'sonarr', 'item_id' => 42, 'season_number' => 1, 'episode_number' => 1,
        'absolute_episode_number' => null, 'candidate_fingerprint' => $fingerprint,
        'selection_mode' => 'manual', 'required_languages' => ['English'], 'reason' => 'x',
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($result['queued'])->toBeTrue()
        ->and($result['requires_approval'])->toBeTrue();
});
