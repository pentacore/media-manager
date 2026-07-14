<?php

declare(strict_types=1);

use App\Enums\MediaReplacementStatus;
use App\Models\ActionRequest;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Services\MediaReplacement\MediaReplacementActions;
use App\Services\MediaReplacement\ReleaseFingerprint;
use App\Settings\MediaReplacementSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();

    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
    ]);

    resolve(MediaReplacementSettings::class)->setConfiguration([
        'automatic_selection_enabled' => false,
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
});

/**
 * @return array<string, mixed>
 */
function sonarrReplacementRelease(): array
{
    return [
        'guid' => 'g1', 'indexerId' => 10, 'title' => 'Trusted.Anime.S01E01.CR',
        'episodeIds' => [101], 'downloadAllowed' => true, 'rejections' => [], 'fullSeason' => false,
        'customFormatScore' => 0, 'qualityWeight' => 100, 'seeders' => 5, 'ageMinutes' => 60,
        'downloadUrl' => 'http://sonarr.local/download/g1',
    ];
}

/**
 * Method-aware fake so a GET and DELETE on the same /episodefile/{id} URL can
 * return different statuses.
 *
 * @param  array{grabOk?: bool, deleteOk?: bool, currentFileId?: int, releases?: list<array<string, mixed>>}  $opts
 */
function fakeExecutor(array $opts = []): void
{
    $grabOk = $opts['grabOk'] ?? true;
    $deleteOk = $opts['deleteOk'] ?? true;
    $currentFileId = $opts['currentFileId'] ?? 501;
    $releases = $opts['releases'] ?? [sonarrReplacementRelease()];

    Http::fake(function (Request $request) use ($grabOk, $deleteOk, $currentFileId, $releases) {
        $method = $request->method();
        $url = $request->url();

        return match (true) {
            $method === 'POST' && str_contains($url, '/api/v3/release') => Http::response([], $grabOk ? 201 : 500),
            $method === 'GET' && str_contains($url, '/api/v3/release') => Http::response($releases),
            $method === 'DELETE' && str_contains($url, '/api/v3/episodefile/') => Http::response([], $deleteOk ? 200 : 500),
            str_contains($url, '/api/v3/series/42') => Http::response(['id' => 42, 'title' => 'Trusted Anime', 'seriesType' => 'anime']),
            str_contains($url, '/api/v3/episode?') => Http::response([
                ['id' => 101, 'seasonNumber' => 1, 'episodeNumber' => 1, 'episodeFileId' => $currentFileId],
            ]),
            str_contains($url, '/api/v3/episodefile/') => Http::response([
                'id' => $currentFileId,
                'sceneName' => $currentFileId === 501 ? 'Trusted.Anime.S01E01.OLD' : 'DIFFERENT',
                'mediaInfo' => ['subtitles' => 'Japanese'],
            ]),
            str_contains($url, '/api/v3/history/failed/') => Http::response([], 200),
            str_contains($url, '/api/v3/history') => Http::response(['records' => [
                ['id' => 999, 'eventType' => 'grabbed', 'episodeId' => 101],
            ]]),
            default => Http::response([], 200),
        };
    });
}

function replaceActionRequest(): ActionRequest
{
    $fingerprint = (new ReleaseFingerprint)->make('sonarr', sonarrReplacementRelease());

    return ActionRequest::factory()->create([
        'type' => 'replace_media_file',
        'source_service' => 'ai',
        'target_service' => 'sonarr',
        'payload' => [
            'service' => 'sonarr',
            'scope' => 'anime',
            'target' => [
                'service' => 'sonarr', 'scope' => 'anime', 'series_id' => 42,
                'season_number' => 1, 'episode_numbers' => [1], 'episode_ids' => [101],
                'episode_file_ids' => [501], 'installed_release' => 'Trusted.Anime.S01E01.OLD',
                'original_history_id' => 999,
            ],
            'candidate_fingerprint' => $fingerprint,
            'candidate' => ['fingerprint' => $fingerprint, 'title' => 'Trusted.Anime.S01E01.CR', 'confidence' => 98],
            'required_languages' => ['eng'],
            'selection_mode' => 'manual',
            'original_history_id' => 999,
        ],
    ]);
}

test('grabs the replacement before deleting the reviewed file', function (): void {
    fakeExecutor();

    $result = resolve(MediaReplacementActions::class)->execute(replaceActionRequest());

    expect($result['replacement_initiated'])->toBeTrue()
        ->and($result['status'])->toBe('downloading')
        ->and($result['deleted_files'])->toBe(1);

    $requests = Http::recorded()->map(fn (array $pair): string => $pair[0]->method().' '.$pair[0]->url())->values();
    $grabIndex = $requests->search(fn (string $value): bool => $value === 'POST http://sonarr.local:8989/api/v3/release');
    $deleteIndex = $requests->search(fn (string $value): bool => $value === 'DELETE http://sonarr.local:8989/api/v3/episodefile/501');

    expect($grabIndex)->not->toBeFalse()
        ->and($deleteIndex)->not->toBeFalse()
        ->and($grabIndex)->toBeLessThan($deleteIndex)
        ->and(MediaReplacementAttempt::first()->status)->toBe(MediaReplacementStatus::Downloading);
});

test('pins execution to the approved connection when multiple are active', function (): void {
    // The beforeEach connection is sonarr.local (id A). Add a second active
    // Sonarr and approve the request against IT.
    $pinned = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr-b.local:8989', 'api_key' => 'b', 'is_active' => true,
    ]);

    fakeExecutor(); // host-agnostic path matching, so both hosts get faked responses

    $fingerprint = (new ReleaseFingerprint)->make('sonarr', sonarrReplacementRelease());
    $actionRequest = ActionRequest::factory()->create([
        'type' => 'replace_media_file', 'source_service' => 'ai', 'target_service' => 'sonarr',
        'payload' => [
            'service' => 'sonarr',
            'service_connection_id' => $pinned->id,
            'scope' => 'anime',
            'target' => [
                'service' => 'sonarr', 'service_connection_id' => $pinned->id, 'scope' => 'anime',
                'series_id' => 42, 'season_number' => 1, 'episode_numbers' => [1], 'episode_ids' => [101],
                'episode_file_ids' => [501], 'installed_release' => 'Trusted.Anime.S01E01.OLD', 'original_history_id' => 999,
            ],
            'candidate_fingerprint' => $fingerprint,
            'candidate' => ['fingerprint' => $fingerprint, 'title' => 'Trusted.Anime.S01E01.CR', 'confidence' => 98],
            'required_languages' => ['eng'], 'selection_mode' => 'manual', 'original_history_id' => 999,
        ],
    ]);

    resolve(MediaReplacementActions::class)->execute($actionRequest);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), 'sonarr-b.local') && str_contains($request->url(), '/api/v3/release'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'sonarr.local:8989'));

    expect(MediaReplacementAttempt::first()->service_connection_id)->toBe($pinned->id);
});

test('aborts without grabbing or deleting when the installed file changed after approval', function (): void {
    fakeExecutor(['currentFileId' => 777]);

    expect(fn (): array => resolve(MediaReplacementActions::class)->execute(replaceActionRequest()))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/api/v3/release'));
});

test('aborts without deleting when the selected release disappeared', function (): void {
    fakeExecutor(['releases' => []]);

    expect(fn (): array => resolve(MediaReplacementActions::class)->execute(replaceActionRequest()))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/api/v3/release'));
});

test('marks the attempt failed and never deletes when the grab is rejected', function (): void {
    fakeExecutor(['grabOk' => false]);

    expect(fn (): array => resolve(MediaReplacementActions::class)->execute(replaceActionRequest()))
        ->toThrow(RuntimeException::class);

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');

    expect(MediaReplacementAttempt::first()->status)->toBe(MediaReplacementStatus::Failed);
});

test('marks the attempt needs_attention when deletion fails after a successful grab', function (): void {
    fakeExecutor(['deleteOk' => false]);

    expect(fn (): array => resolve(MediaReplacementActions::class)->execute(replaceActionRequest()))
        ->toThrow(RuntimeException::class);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/api/v3/release'));

    expect(MediaReplacementAttempt::first()->status)->toBe(MediaReplacementStatus::NeedsAttention);
});
