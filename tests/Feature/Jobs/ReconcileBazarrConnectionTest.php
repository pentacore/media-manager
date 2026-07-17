<?php

declare(strict_types=1);

use App\Jobs\ReconcileBazarrConnection;
use App\Jobs\ReconcileSubtitleCase;
use App\Models\BazarrServiceLink;
use App\Models\ServiceConnection;
use App\Services\Bazarr\SubtitleInventoryService;
use App\Settings\BazarrAutomationSettings;
use App\Settings\MediaReplacementSettings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('connection reconciliation is unique by connection and rate limited', function (): void {
    $job = new ReconcileBazarrConnection(42);
    $reflection = new ReflectionClass($job);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('42')
        ->and($job->middleware())->toHaveCount(1)
        ->and($job->middleware()[0])->toBeInstanceOf(RateLimited::class)
        ->and($reflection->getAttributes(Timeout::class)[0]->newInstance()->timeout)->toBe(120)
        ->and($reflection->getAttributes(Tries::class)[0]->newInstance()->tries)->toBe(4)
        ->and($reflection->getAttributes(UniqueFor::class)[0]->newInstance()->uniqueFor)->toBe(300)
        ->and($job->backoff())->toBeArray();
});

test('disabled automation performs no upstream reads or case dispatches', function (): void {
    Http::preventStrayRequests();
    $bazarr = ServiceConnection::factory()->bazarr()->create();
    Queue::fake([ReconcileSubtitleCase::class]);

    new ReconcileBazarrConnection($bazarr->id)->handle(
        resolve(SubtitleInventoryService::class),
        resolve(BazarrAutomationSettings::class),
    );

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

test('missing inactive and wrong type connections are ignored', function (array $connectionAttributes): void {
    Http::preventStrayRequests();
    resolve(BazarrAutomationSettings::class)->setConfiguration(['enabled' => true]);
    $connection = ServiceConnection::factory()->create($connectionAttributes);
    Queue::fake([ReconcileSubtitleCase::class]);

    new ReconcileBazarrConnection($connection->id)->handle(
        resolve(SubtitleInventoryService::class),
        resolve(BazarrAutomationSettings::class),
    );

    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with([
    'inactive Bazarr' => [['type' => 'bazarr', 'is_active' => false]],
    'active Sonarr' => [['type' => 'sonarr', 'is_active' => true]],
]);

test('an unmapped active Bazarr connection returns without upstream reads', function (): void {
    $bazarr = ServiceConnection::factory()->bazarr()->create();
    resolve(BazarrAutomationSettings::class)->setConfiguration(['enabled' => true]);
    Http::preventStrayRequests();
    Queue::fake([ReconcileSubtitleCase::class]);

    new ReconcileBazarrConnection($bazarr->id)->handle(
        resolve(SubtitleInventoryService::class),
        resolve(BazarrAutomationSettings::class),
    );

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

test('a reconciliation cycle dispatches no more than the configured case cap', function (): void {
    $bazarr = ServiceConnection::factory()->bazarr()->create([
        'url' => 'http://bazarr.test',
        'api_key' => 'bazarr-secret',
    ]);
    $sonarr = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.test',
        'api_key' => 'sonarr-secret',
        'settings' => [
            'sonarr_root_folders' => [
                ['root_folder_id' => 1, 'path' => '/anime', 'scope' => 'anime'],
            ],
        ],
    ]);
    BazarrServiceLink::factory()->sonarr()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $sonarr->id,
    ]);
    resolve(BazarrAutomationSettings::class)->setConfiguration([
        'enabled' => true,
        'max_cases_per_cycle' => 1,
    ]);
    resolve(MediaReplacementSettings::class)->setConfiguration([
        'global_languages' => ['eng'],
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'sonarr.test/api/v3/series' => Http::response([
            ['id' => 101, 'title' => 'Frieren', 'rootFolderPath' => '/anime', 'seriesType' => 'anime'],
        ]),
        'bazarr.test/api/episodes/wanted*' => Http::response([
            'data' => [
                [
                    'sonarrSeriesId' => 101,
                    'sonarrEpisodeId' => 701,
                    'episodeTitle' => 'Part One',
                    'missing_subtitles' => [['code3' => 'eng']],
                    'subtitles' => [],
                ],
                [
                    'sonarrSeriesId' => 101,
                    'sonarrEpisodeId' => 702,
                    'episodeTitle' => 'Part Two',
                    'missing_subtitles' => [['code3' => 'eng']],
                    'subtitles' => [],
                ],
            ],
            'total' => 2,
        ]),
        'sonarr.test/api/v3/episode?seriesId=101' => Http::response([
            ['id' => 701, 'seriesId' => 101, 'episodeFileId' => 501],
            ['id' => 702, 'seriesId' => 101, 'episodeFileId' => 502],
        ]),
        'sonarr.test/api/v3/episodefile/501' => Http::response([
            'id' => 501,
            'size' => 1000,
            'dateAdded' => '2026-07-17T08:00:00Z',
            'sceneName' => 'Release.One',
        ]),
        'sonarr.test/api/v3/episodefile/502' => Http::response([
            'id' => 502,
            'size' => 2000,
            'dateAdded' => '2026-07-17T09:00:00Z',
            'sceneName' => 'Release.Two',
        ]),
    ]);
    Queue::fake([ReconcileSubtitleCase::class]);

    new ReconcileBazarrConnection($bazarr->id)->handle(
        resolve(SubtitleInventoryService::class),
        resolve(BazarrAutomationSettings::class),
    );

    Queue::assertPushed(ReconcileSubtitleCase::class, 1);
});
