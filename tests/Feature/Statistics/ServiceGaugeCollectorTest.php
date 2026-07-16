<?php

declare(strict_types=1);

use App\Enums\ServiceType;
use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use App\Models\StatRollup;
use App\Services\Statistics\ServiceGaugeCollector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Cache::flush();
});

it('snapshots library counts into day buckets', function (): void {
    IndexedMovie::factory()->count(3)->create(['has_file' => true, 'monitored' => true]);
    IndexedMovie::factory()->create(['has_file' => false, 'monitored' => false]);
    IndexedSeries::factory()->count(2)->create(['monitored' => true]);

    resolve(ServiceGaugeCollector::class)->snapshotLibrary();

    expect(StatRollup::query()->where('metric', 'library.movies')->sole()->count)->toBe(4)
        ->and(StatRollup::query()->where('metric', 'library.with_file')->sole()->count)->toBe(3)
        ->and(StatRollup::query()->where('metric', 'library.series')->sole()->count)->toBe(2)
        ->and(StatRollup::query()->where('metric', 'library.monitored')->where('dimensions->kind', 'movie')->sole()->count)->toBe(3)
        ->and(StatRollup::query()->where('metric', 'library.monitored')->where('dimensions->kind', 'series')->sole()->count)->toBe(2);
});

it('collect survives a failing connection and still samples the healthy one', function (): void {
    $down = ServiceConnection::factory()->create(['type' => ServiceType::Emby, 'is_active' => true, 'url' => 'http://emby-down.test']);
    $up = ServiceConnection::factory()->create(['type' => ServiceType::Emby, 'is_active' => true, 'url' => 'http://emby-up.test']);

    Http::fake([
        'emby-down.test/*' => Http::response('boom', 500),
        'emby-up.test/*' => Http::response([
            ['Id' => 'a', 'NowPlayingItem' => ['Name' => 'Movie']],
            ['Id' => 'b'],
        ]),
    ]);

    resolve(ServiceGaugeCollector::class)->collect();

    $statRollup = StatRollup::query()->where('metric', 'sessions.active')->where('period', 'hour')->sole();

    expect($statRollup->dimensions)->toBe(['connection' => (string) $up->id])
        ->and($statRollup->sum)->toBe(1.0);
});

it('samples disk space and queue depth for an Arr connection', function (): void {
    $sonarr = ServiceConnection::factory()->sonarr()->create(['is_active' => true, 'url' => 'http://sonarr.test']);

    Http::fake([
        'sonarr.test/api/v3/diskspace*' => Http::response([
            ['path' => '/data', 'label' => 'Data', 'freeSpace' => 100, 'totalSpace' => 500],
        ]),
        'sonarr.test/api/v3/queue*' => Http::response(['totalRecords' => 7, 'records' => []]),
    ]);

    resolve(ServiceGaugeCollector::class)->collect();

    $statRollup = StatRollup::query()->where('metric', 'service.disk_free_bytes')->where('period', 'hour')->sole();
    $total = StatRollup::query()->where('metric', 'service.disk_total_bytes')->where('period', 'hour')->sole();
    $queue = StatRollup::query()->where('metric', 'queue.depth')->where('period', 'hour')->sole();

    expect($statRollup->sum)->toBe(100.0)
        ->and($statRollup->dimensions)->toEqualCanonicalizing(['connection' => (string) $sonarr->id, 'path' => '/data'])
        ->and($total->sum)->toBe(500.0)
        ->and($queue->sum)->toBe(7.0)
        ->and($queue->dimensions)->toEqualCanonicalizing(['connection' => (string) $sonarr->id, 'service' => 'sonarr']);
});

it('skips unsupported Bazarr gauges without issuing requests or logging a collection failure', function (): void {
    ServiceConnection::factory()->bazarr()->create([
        'is_active' => true,
        'url' => 'http://bazarr.test',
    ]);
    Log::spy();

    resolve(ServiceGaugeCollector::class)->collect();

    Http::assertNothingSent();
    Log::shouldNotHaveReceived('info');
    expect(StatRollup::query()->count())->toBe(0);
});

it('samples pending requests for a Seerr connection', function (): void {
    $seerr = ServiceConnection::factory()->seerr()->create(['is_active' => true, 'url' => 'http://seerr.test']);

    Http::fake([
        'seerr.test/*' => Http::response(['pending' => 4, 'approved' => 10, 'total' => 14]),
    ]);

    resolve(ServiceGaugeCollector::class)->collect();

    $statRollup = StatRollup::query()->where('metric', 'requests.pending_gauge')->where('period', 'hour')->sole();

    expect($statRollup->sum)->toBe(4.0)
        ->and($statRollup->dimensions)->toBe(['connection' => (string) $seerr->id]);
});

it('samples disk space and queue depth for a SABnzbd connection', function (): void {
    $sab = ServiceConnection::factory()->sabnzbd()->create(['is_active' => true, 'url' => 'http://sab.test']);

    Http::fake([
        'sab.test/*' => Http::response([
            'queue' => [
                'noofslots' => 3,
                'diskspace1' => '10',
                'diskspacetotal1' => '100',
                'download_dir' => '/incomplete',
            ],
        ]),
    ]);

    resolve(ServiceGaugeCollector::class)->collect();

    $statRollup = StatRollup::query()->where('metric', 'service.disk_free_bytes')->where('period', 'hour')->sole();
    $queue = StatRollup::query()->where('metric', 'queue.depth')->where('period', 'hour')->sole();

    expect((int) $statRollup->sum)->toBe((int) round(10 * 1024 ** 3))
        ->and($statRollup->dimensions)->toEqualCanonicalizing(['connection' => (string) $sab->id, 'path' => '/incomplete'])
        ->and($queue->sum)->toBe(3.0)
        ->and($queue->dimensions)->toEqualCanonicalizing(['connection' => (string) $sab->id, 'service' => 'sabnzbd']);
});

it('writes prowlarr indexer stats into the day bucket during the library pass', function (): void {
    ServiceConnection::factory()->prowlarr()->create(['is_active' => true, 'url' => 'http://prowlarr.test']);

    Http::fake([
        'prowlarr.test/*' => Http::response([
            'indexers' => [
                ['indexerName' => 'NZBgeek', 'numberOfGrabs' => 12, 'numberOfQueries' => 34],
            ],
        ]),
    ]);

    resolve(ServiceGaugeCollector::class)->snapshotLibrary();

    $statRollup = StatRollup::query()->where('metric', 'indexer.grabs')->where('period', 'day')->sole();
    $queries = StatRollup::query()->where('metric', 'indexer.queries')->where('period', 'day')->sole();

    expect($statRollup->count)->toBe(12)
        ->and($statRollup->dimensions)->toBe(['indexer' => 'NZBgeek'])
        ->and($queries->count)->toBe(34)
        ->and($queries->dimensions)->toBe(['indexer' => 'NZBgeek']);
});
