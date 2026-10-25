<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Arr\RepairSeriesSubtitlesTool;
use App\Enums\AiMode;
use App\Enums\SubtitleAuditEpisodeOutcome;
use App\Models\ActionTypeConfig;
use App\Models\ServiceConnection;
use App\Models\SubtitleAuditBatch;
use App\Models\User;
use App\Settings\AiSettings;
use App\Settings\MediaReplacementSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();

    resolve(AiSettings::class)->setMode(AiMode::Executive);

    ActionTypeConfig::factory()->create([
        'type' => 'replace_media_file', 'requires_approval' => false, 'is_enabled' => true,
    ]);

    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
        'settings' => [
            'sonarr_root_folders' => [['root_folder_id' => 1, 'path' => '/tv', 'scope' => 'tv']],
        ],
    ]);

    resolve(MediaReplacementSettings::class)->setConfiguration([
        'automatic_selection_enabled' => true,
        'automatic_selection_threshold' => 90,
        'global_languages' => ['English'],
        'scoped_languages' => ['anime' => null, 'tv' => null, 'movie' => null],
        'season_pack_policy' => 'allowed',
        'subtitle_check' => ['enabled' => true, 'max_attempts_per_target' => 1, 'cooldown_hours' => 24],
        'guidance' => [
            'anime' => ['notes' => '', 'rules' => []],
            'tv' => [
                'notes' => '',
                'rules' => [[
                    'name' => 'CR', 'enabled' => true, 'strength' => 'guarantee', 'languages' => ['English'],
                    'conditions' => [['field' => 'title', 'value' => 'CR']],
                ]],
            ],
            'movie' => ['notes' => '', 'rules' => []],
        ],
    ]);

    $this->user = User::factory()->create(['name' => 'Ada']);
});

function fakeRepairToolSonarr(string $subtitles = 'Japanese'): void
{
    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response([
            'id' => 42, 'title' => 'Show', 'path' => '/tv/Show', 'monitored' => true, 'tags' => [], 'seriesType' => 'standard',
        ]),
        'sonarr.local:8989/api/v3/episodefile/501' => Http::response([
            'id' => 501, 'seriesId' => 42, 'quality' => ['quality' => ['name' => 'WEBDL-1080p']],
            'size' => 100, 'sceneName' => 'Show.S01E01.OLD', 'releaseGroup' => 'OLD',
            'mediaInfo' => ['subtitles' => $subtitles],
        ]),
        'sonarr.local:8989/api/v3/episodefile*' => Http::response([
            ['id' => 501, 'seriesId' => 42, 'mediaInfo' => ['subtitles' => $subtitles]],
        ]),
        'sonarr.local:8989/api/v3/episode*' => Http::response([[
            'id' => 101, 'seriesId' => 42, 'seasonNumber' => 1, 'episodeNumber' => 1,
            'episodeFileId' => 501, 'monitored' => true, 'hasFile' => true, 'title' => 'Ep 1',
        ]]),
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => [[
            'id' => 9001, 'eventType' => 'downloadFolderImported', 'episodeId' => 101, 'episodeFileId' => 501,
        ]]]),
        'sonarr.local:8989/api/v3/release*' => Http::response([[
            'guid' => 'g1', 'indexerId' => 10, 'title' => 'Show.S01E01.CR',
            'episodeIds' => [101], 'downloadAllowed' => true, 'rejections' => [], 'fullSeason' => false,
            'customFormatScore' => 0, 'qualityWeight' => 100, 'seeders' => 5, 'ageMinutes' => 60,
            'downloadUrl' => 'http://sonarr.local/download/g1',
        ]]),
    ]);
}

/**
 * @return array<string, mixed>
 */
function runRepairTool(): array
{
    return json_decode(new RepairSeriesSubtitlesTool()->handle(new Request([
        'series_id' => 42, 'season_number' => null,
    ])), true, flags: JSON_THROW_ON_ERROR);
}

test('the repair tool is a safe write tool', function (): void {
    expect(new RepairSeriesSubtitlesTool()->risk())->toBe(Risk::SafeWrite)
        ->and(RepairSeriesSubtitlesTool::IN_CHAT_WAIT_SECONDS)->toBe(60);
});

test('the repair tool returns a batch card when the searches finish in time', function (): void {
    fakeRepairToolSonarr();
    $this->actingAs($this->user);

    $result = runRepairTool();

    expect($result['subtitle_audit_batch']['type'])->toBe('subtitle_audit_batch')
        ->and($result['subtitle_audit_batch']['episodes'])->toHaveCount(1)
        ->and($result['subtitle_audit_batch']['episodes'][0]['outcome'])->toBe(SubtitleAuditEpisodeOutcome::Queued->value)
        ->and($result['subtitle_audit_batch']['pending_action_request_ids'])->toHaveCount(1);
});

test('the repair tool hands off when the batch is not ready by the deadline', function (): void {
    fakeRepairToolSonarr();
    Queue::fake();
    Sleep::fake();
    $this->actingAs($this->user);

    $result = runRepairTool();
    $subtitleAuditBatch = SubtitleAuditBatch::query()->sole();

    expect($result['handed_off'])->toBeTrue()
        ->and($result['subtitle_audit_batch_id'])->toBe($subtitleAuditBatch->id)
        ->and($result['searching'])->toBe(1)
        ->and($subtitleAuditBatch->handed_off_at)->not->toBeNull();
});

test('the repair tool is refused in advisory mode', function (): void {
    resolve(AiSettings::class)->setMode(AiMode::Advisory);
    fakeRepairToolSonarr();
    $this->actingAs($this->user);

    $result = runRepairTool();

    expect($result['error'])->toBe('advisory_mode_blocks_destructive')
        ->and(SubtitleAuditBatch::query()->count())->toBe(0);
});

test('the repair tool needs a signed in user', function (): void {
    fakeRepairToolSonarr();

    expect(runRepairTool())->toBe(['error' => 'no_user'])
        ->and(SubtitleAuditBatch::query()->count())->toBe(0);
});

test('the repair tool reports a refused repair', function (): void {
    fakeRepairToolSonarr('English/Japanese');
    $this->actingAs($this->user);

    $result = runRepairTool();

    expect($result['repaired'])->toBeFalse()
        ->and($result['reason'])->toBe('nothing_missing')
        ->and($result['message'])->toBeString()
        ->and(SubtitleAuditBatch::query()->count())->toBe(0);
});
