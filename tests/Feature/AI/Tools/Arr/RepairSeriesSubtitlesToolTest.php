<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Arr\RepairSeriesSubtitlesTool;
use App\Enums\AiMode;
use App\Enums\SubtitleAuditBatchStatus;
use App\Enums\SubtitleAuditEpisodeOutcome;
use App\Jobs\WatchSubtitleAuditBatch;
use App\Models\ActionTypeConfig;
use App\Models\ServiceConnection;
use App\Models\SubtitleAuditBatch;
use App\Models\User;
use App\Services\MediaReplacement\SubtitleAuditBatchSettler;
use App\Settings\AiSettings;
use App\Settings\MediaReplacementSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Laravel\Ai\Tools\Request;
use Mockery\MockInterface;

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

function fakeRepairToolSonarr(string $subtitles = 'Japanese', string $seriesPath = '/tv/Show'): void
{
    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response([
            'id' => 42, 'title' => 'Show', 'path' => $seriesPath, 'monitored' => true, 'tags' => [], 'seriesType' => 'standard',
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
    // The searches run on the sync queue; the sync driver would run the
    // delayed watchdog at once too, long before its three minutes are up.
    Queue::fake([WatchSubtitleAuditBatch::class]);
    $this->actingAs($this->user);

    $result = runRepairTool();

    expect($result['subtitle_audit_batch']['type'])->toBe('subtitle_audit_batch')
        ->and($result['subtitle_audit_batch']['episodes'])->toHaveCount(1)
        ->and($result['subtitle_audit_batch']['episodes'][0]['outcome'])->toBe(SubtitleAuditEpisodeOutcome::Queued->value)
        ->and($result['subtitle_audit_batch']['pending_action_request_ids'])->toHaveCount(1)
        ->and($result)->not->toHaveKey('handed_off');

    $subtitleAuditBatch = SubtitleAuditBatch::query()->sole();

    expect($subtitleAuditBatch->card_shown_at)->not->toBeNull()
        ->and($subtitleAuditBatch->handed_off_at)->toBeNull();
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
        ->and($result['message'])->toBe('The release searches continue in the background. Tell the user the admins will be notified when the batch is ready to approve on the Actions page.')
        ->and($result['subtitle_audit_batch']['type'])->toBe('subtitle_audit_batch')
        ->and($result['subtitle_audit_batch']['id'])->toBe($subtitleAuditBatch->id)
        ->and($result['subtitle_audit_batch']['status'])->toBe('searching')
        ->and($result['subtitle_audit_batch']['episodes'][0]['outcome'])->toBe(SubtitleAuditEpisodeOutcome::Searching->value)
        ->and($result['subtitle_audit_batch']['pending_action_request_ids'])->toBe([])
        ->and($subtitleAuditBatch->handed_off_at)->not->toBeNull()
        ->and($subtitleAuditBatch->card_shown_at)->toBeNull();
});

test('the repair tool shows the card when the batch turns ready while it hands off', function (): void {
    fakeRepairToolSonarr();
    Queue::fake();
    Sleep::fake();
    $this->actingAs($this->user);

    // The last search settles between the wait's final check and the hand-off.
    $this->mock(SubtitleAuditBatchSettler::class, function (MockInterface $mock): void {
        $mock->makePartial();
        $mock->shouldReceive('handOff')->once()->andReturnUsing(function (SubtitleAuditBatch $subtitleAuditBatch): bool {
            $subtitleAuditBatch->episodes()->update(['outcome' => SubtitleAuditEpisodeOutcome::ManualPick]);
            $subtitleAuditBatch->update(['status' => SubtitleAuditBatchStatus::Ready, 'completed_at' => now()]);

            return false;
        });
    });

    $result = runRepairTool();
    $subtitleAuditBatch = SubtitleAuditBatch::query()->sole();

    expect($result)->not->toHaveKey('handed_off')
        ->and($result['subtitle_audit_batch']['status'])->toBe('ready')
        ->and($subtitleAuditBatch->card_shown_at)->not->toBeNull()
        ->and($subtitleAuditBatch->handed_off_at)->toBeNull();
});

test('the repair tool reports a hand-off when the watchdog adopted the batch first', function (): void {
    fakeRepairToolSonarr();
    Queue::fake();
    Sleep::fake();
    $this->actingAs($this->user);

    $this->mock(SubtitleAuditBatchSettler::class, function (MockInterface $mock): void {
        $mock->makePartial();
        $mock->shouldReceive('handOff')->once()->andReturnUsing(function (SubtitleAuditBatch $subtitleAuditBatch): bool {
            $subtitleAuditBatch->update(['status' => SubtitleAuditBatchStatus::Ready, 'completed_at' => now(), 'handed_off_at' => now()]);

            return false;
        });
    });

    $result = runRepairTool();
    $subtitleAuditBatch = SubtitleAuditBatch::query()->sole();

    expect($result['handed_off'])->toBeTrue()
        ->and($result['subtitle_audit_batch']['status'])->toBe('ready')
        ->and($subtitleAuditBatch->card_shown_at)->toBeNull();
});

test('the in-chat wait fits inside what is left of the request', function (int $ceiling, ?float $elapsedSeconds, int $expectedSleeps): void {
    fakeRepairToolSonarr();
    Queue::fake();
    Sleep::fake();
    $this->actingAs($this->user);
    config()->set('octane.max_execution_time', $ceiling);

    if ($elapsedSeconds === null) {
        request()->server->remove('REQUEST_TIME_FLOAT');
    } else {
        request()->server->set('REQUEST_TIME_FLOAT', microtime(true) - $elapsedSeconds);
    }

    expect(runRepairTool()['handed_off'])->toBeTrue();
    Sleep::assertSleptTimes($expectedSleeps);
})->with([
    'a fresh request waits the full minute' => [150, 1.0, 60],
    'a late request waits what is left before the 30 second margin' => [150, 99.5, 20],
    'a request past the margin does not wait' => [150, 130.0, 0],
    'no ceiling waits the full minute' => [0, 140.0, 60],
    'an unknown request start waits the full minute' => [150, null, 60],
]);

test('the repair tool explains an unconfigured root folder like the audit tool', function (): void {
    fakeRepairToolSonarr(seriesPath: '/elsewhere/Show');
    $this->actingAs($this->user);

    $result = runRepairTool();

    expect($result['error'])->toBe('unconfigured_root_scope')
        ->and($result['message'])->toBe('The series root folder has no anime/tv scope configured. Tell the user to set it in the Sonarr connection settings.')
        ->and(SubtitleAuditBatch::query()->count())->toBe(0);
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
