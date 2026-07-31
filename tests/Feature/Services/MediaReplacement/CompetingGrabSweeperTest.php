<?php

declare(strict_types=1);

use App\Enums\MediaReplacementStatus;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Notifications\MediaReplacementStatusChanged;
use App\Services\MediaReplacement\CompetingGrabSweeper;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();
    Notification::fake();

    $this->connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
    ]);
});

/**
 * An attempt whose Grab webhook has already landed, so the sweep is armed. The
 * download id is what arms it — tests covering the unarmed state override it
 * back to null explicitly.
 *
 * @param  array<string, mixed>  $overrides
 */
function sweeperAttempt(int $connectionId, array $overrides = []): MediaReplacementAttempt
{
    return MediaReplacementAttempt::factory()->create(array_replace([
        'service_connection_id' => $connectionId,
        'status' => MediaReplacementStatus::Downloading,
        'scope' => 'anime',
        'target' => [
            'service' => 'sonarr', 'scope' => 'anime', 'series_id' => 42,
            'episode_ids' => [101], 'episode_file_ids' => [501],
        ],
        'candidate' => ['title' => 'Trusted.Anime.S01E01.CR', 'fingerprint' => 'fp'],
        'download_id' => 'DL-OURS',
    ], $overrides));
}

/**
 * @param  list<array<string, mixed>>  $records
 */
function fakeSweeperQueue(array $records): void
{
    Http::fake([
        'sonarr.local:8989/api/v3/queue*' => Http::response(['records' => $records]),
        'sonarr.local:8989/api/v3/queue/*' => Http::response([], 200),
    ]);
}

test('it removes a queue item on the target whose title is not the vetted release', function (): void {
    $mediaReplacementAttempt = sweeperAttempt($this->connection->id);

    fakeSweeperQueue([
        ['id' => 900, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => 'DL-OTHER', 'title' => 'Random.Anime.S01E01.OTHER'],
    ]);

    $removed = resolve(CompetingGrabSweeper::class)->sweep($this->connection, $mediaReplacementAttempt);

    expect($removed)->toBe(1);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/queue/900')
        && str_contains($request->url(), 'removeFromClient=true')
        && str_contains($request->url(), 'blocklist=false')
        && str_contains($request->url(), 'skipRedownload=true'));

    // The sweeper reads only episodeId/episodeIds, which the queue returns
    // anyway, so it must not ask Sonarr to inline whole episode objects.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), '/api/v3/queue?')
        && str_contains($request->url(), 'pageSize=200')
        && ! str_contains($request->url(), 'includeEpisode'));
});

test('it leaves a row carrying the vetted release title alone even under a different download id', function (): void {
    $mediaReplacementAttempt = sweeperAttempt($this->connection->id);

    // A different download id, so only the title keep-guard can spare this row.
    fakeSweeperQueue([
        ['id' => 901, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => 'DL-CLIENT-RENAMED', 'title' => 'trusted.anime.s01e01.cr'],
    ]);

    $removed = resolve(CompetingGrabSweeper::class)->sweep($this->connection, $mediaReplacementAttempt);

    expect($removed)->toBe(0);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

test('it leaves the vetted release alone when matched by download id', function (): void {
    $mediaReplacementAttempt = sweeperAttempt($this->connection->id, [
        'download_id' => 'DL-OURS',
        'candidate' => ['title' => 'Renamed.After.Grab', 'fingerprint' => 'fp'],
    ]);

    fakeSweeperQueue([
        ['id' => 902, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => 'DL-OURS', 'title' => 'Whatever.The.Client.Called.It'],
    ]);

    $removed = resolve(CompetingGrabSweeper::class)->sweep($this->connection, $mediaReplacementAttempt);

    expect($removed)->toBe(0);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

test('it ignores queue items for other targets', function (): void {
    $mediaReplacementAttempt = sweeperAttempt($this->connection->id);

    fakeSweeperQueue([
        ['id' => 903, 'seriesId' => 99, 'episodeId' => 777, 'downloadId' => 'DL-ELSEWHERE', 'title' => 'Other.Series.S01E01'],
    ]);

    $removed = resolve(CompetingGrabSweeper::class)->sweep($this->connection, $mediaReplacementAttempt);

    expect($removed)->toBe(0);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

test('a whitespace-padded stored download id still identifies our own row', function (): void {
    $mediaReplacementAttempt = sweeperAttempt($this->connection->id, ['download_id' => ' DL-OURS ']);

    // Our row's title deliberately does not resemble the candidate title, so
    // the download-id keep-guard is the only thing that can spare it.
    fakeSweeperQueue([
        ['id' => 910, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => 'DL-OURS', 'title' => 'Client.Renamed.Job'],
        ['id' => 911, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => 'DL-OTHER', 'title' => 'Random.Anime.S01E01.OTHER'],
    ]);

    $removed = resolve(CompetingGrabSweeper::class)->sweep($this->connection, $mediaReplacementAttempt);

    expect($removed)->toBe(1);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/queue/911'));
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/queue/910'));
});

test('it ignores a queue item for a different episode of the same series', function (): void {
    $mediaReplacementAttempt = sweeperAttempt($this->connection->id);

    fakeSweeperQueue([
        ['id' => 909, 'seriesId' => 42, 'episodeId' => 999, 'downloadId' => 'DL-OTHER', 'title' => 'Trusted.Anime.S01E09.OTHER'],
    ]);

    $removed = resolve(CompetingGrabSweeper::class)->sweep($this->connection, $mediaReplacementAttempt);

    expect($removed)->toBe(0);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

test('it stays unarmed until the grab webhook records our download id, making no request at all', function (): void {
    // The candidate title is present and would match nothing in the queue: it
    // must still not be enough to arm a removal on its own.
    $mediaReplacementAttempt = sweeperAttempt($this->connection->id, ['download_id' => null]);

    fakeSweeperQueue([
        ['id' => 904, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => 'DL-ANY', 'title' => 'Anything'],
    ]);

    $removed = resolve(CompetingGrabSweeper::class)->sweep($this->connection, $mediaReplacementAttempt);

    expect($removed)->toBe(0);
    Http::assertNothingSent();
});

test('it stays unarmed when the attempt has neither a candidate title nor a download id', function (): void {
    $mediaReplacementAttempt = sweeperAttempt($this->connection->id, [
        'candidate' => [], 'download_id' => null,
    ]);

    fakeSweeperQueue([
        ['id' => 904, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => 'DL-ANY', 'title' => 'Anything'],
    ]);

    $removed = resolve(CompetingGrabSweeper::class)->sweep($this->connection, $mediaReplacementAttempt);

    expect($removed)->toBe(0);
    Http::assertNothingSent();
});

test('it notifies admins about each competing grab it removed', function (): void {
    $admin = User::factory()->admin()->create();
    $mediaReplacementAttempt = sweeperAttempt($this->connection->id);

    fakeSweeperQueue([
        ['id' => 906, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => 'DL-OTHER', 'title' => 'Random.Anime.S01E01.OTHER'],
    ]);

    expect(resolve(CompetingGrabSweeper::class)->sweep($this->connection, $mediaReplacementAttempt))->toBe(1);

    Notification::assertSentTo(
        $admin,
        MediaReplacementStatusChanged::class,
        fn (MediaReplacementStatusChanged $notification): bool => $notification->service === 'sonarr'
            && $notification->level === 'warning'
            && $notification->title === 'Trusted.Anime.S01E01.CR'
            && str_contains($notification->message, 'Random.Anime.S01E01.OTHER'),
    );
});

test('a notification failure neither truncates the sweep nor distorts the removal count', function (): void {
    User::factory()->admin()->create();
    $mediaReplacementAttempt = sweeperAttempt($this->connection->id);

    fakeSweeperQueue([
        ['id' => 907, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => 'DL-OTHER-A', 'title' => 'Random.Anime.S01E01.A'],
        ['id' => 908, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => 'DL-OTHER-B', 'title' => 'Random.Anime.S01E01.B'],
    ]);

    // Notification::fake() never throws, so stand in a dispatcher whose every
    // send fails the way a broken mailer or notifications table would.
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('send')->twice()->andThrow(new RuntimeException('notification backend unavailable'));
    Notification::swap($dispatcher);

    $removed = resolve(CompetingGrabSweeper::class)->sweep($this->connection, $mediaReplacementAttempt);

    // Both rows must still be removed: the first failure must not abort the
    // loop, and the count must reflect the queue, not the notifications.
    expect($removed)->toBe(2);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/queue/907'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/queue/908'));
});

test('a queue read failure is swallowed rather than aborting the caller', function (): void {
    $mediaReplacementAttempt = sweeperAttempt($this->connection->id);

    Http::fake(['sonarr.local:8989/api/v3/queue*' => Http::response([], 500)]);

    $removed = resolve(CompetingGrabSweeper::class)->sweep($this->connection, $mediaReplacementAttempt);

    expect($removed)->toBe(0);
});

test("it spares another in-flight attempt's download on the same target", function (): void {
    $mediaReplacementAttempt = sweeperAttempt($this->connection->id, ['download_id' => 'DL-OURS']);

    sweeperAttempt($this->connection->id, [
        'download_id' => 'DL-SIBLING',
        'candidate' => ['title' => 'Other.Trusted.Release', 'fingerprint' => 'fp2'],
    ]);

    // Both replacement rows are titled the way a download client renamed them,
    // so neither the vetted-title keep-guard nor our own download id can spare
    // the sibling's row — only knowing it belongs to another live attempt can.
    fakeSweeperQueue([
        ['id' => 930, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => 'DL-OURS', 'title' => 'Client.Renamed.Ours'],
        ['id' => 931, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => 'DL-SIBLING', 'title' => 'Client.Renamed.Sibling'],
        ['id' => 932, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => 'DL-RACE', 'title' => 'Competing.Release'],
    ]);

    $removed = resolve(CompetingGrabSweeper::class)->sweep($this->connection, $mediaReplacementAttempt);

    expect($removed)->toBe(1);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/queue/932'));
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/queue/931'));
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/queue/930'));
});

test("it does not spare a settled attempt's download", function (): void {
    $mediaReplacementAttempt = sweeperAttempt($this->connection->id, ['download_id' => 'DL-OURS']);

    // Verified is terminal: that attempt is finished and nothing is waiting on
    // its download, so it earns no protection from this sweep.
    sweeperAttempt($this->connection->id, [
        'status' => MediaReplacementStatus::Verified,
        'download_id' => 'DL-SETTLED',
        'candidate' => ['title' => 'Settled.Release', 'fingerprint' => 'fp2'],
    ]);

    fakeSweeperQueue([
        ['id' => 933, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => 'DL-SETTLED', 'title' => 'Client.Renamed.Settled'],
    ]);

    $removed = resolve(CompetingGrabSweeper::class)->sweep($this->connection, $mediaReplacementAttempt);

    expect($removed)->toBe(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/queue/933'));
});

test('a sibling attempt with a blank download id spares nothing', function (): void {
    $mediaReplacementAttempt = sweeperAttempt($this->connection->id, ['download_id' => 'DL-OURS']);

    // In flight but its Grab webhook has not landed, so it has no id to protect.
    sweeperAttempt($this->connection->id, [
        'download_id' => '   ',
        'candidate' => ['title' => 'Unstarted.Sibling', 'fingerprint' => 'fp2'],
    ]);

    // A row reporting no download id of its own. A blank sibling id must not
    // become a wildcard that matches it — that is how a missing identifier
    // turns into a match-all and disarms the sweep.
    fakeSweeperQueue([
        ['id' => 934, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => '', 'title' => 'Competing.Release'],
    ]);

    $removed = resolve(CompetingGrabSweeper::class)->sweep($this->connection, $mediaReplacementAttempt);

    expect($removed)->toBe(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/queue/934'));
});

test("it spares a sibling's batch pack even on the row sonarr attributes to our episode", function (): void {
    $mediaReplacementAttempt = sweeperAttempt($this->connection->id, ['download_id' => 'DL-OURS']);

    // A live sibling replacing episode 9 of the same series, which grabbed a
    // batch/season pack.
    sweeperAttempt($this->connection->id, [
        'target' => [
            'service' => 'sonarr', 'scope' => 'anime', 'series_id' => 42,
            'episode_ids' => [999], 'episode_file_ids' => [599],
        ],
        'download_id' => 'DL-PACK',
        'candidate' => ['title' => 'Trusted.Anime.S01.Batch.CR', 'fingerprint' => 'fp2'],
    ]);

    // How Sonarr actually represents that pack: ONE row per contained episode,
    // every row sharing the pack's single downloadId. The row below is the one
    // Sonarr attributes to OUR episode — it matches our target, is not our
    // download id, and does not carry our vetted title, so nothing but the
    // sibling keep set can save it. Removing it takes the whole pack with it
    // (removeFromClient: true) and strands the sibling.
    fakeSweeperQueue([
        ['id' => 935, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => 'DL-PACK', 'title' => 'Trusted.Anime.S01.Batch.CR'],
    ]);

    $removed = resolve(CompetingGrabSweeper::class)->sweep($this->connection, $mediaReplacementAttempt);

    expect($removed)->toBe(0);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

test('it falls back to series identity when the target stored no episode ids', function (): void {
    // Degenerate branch of the shared overlap rule: with no episode ids on our
    // side, series identity is all there is to go on, and a competing download
    // for the series we are replacing into is worth removing.
    $mediaReplacementAttempt = sweeperAttempt($this->connection->id, [
        'target' => ['service' => 'sonarr', 'scope' => 'anime', 'series_id' => 42, 'episode_file_ids' => [501]],
        'download_id' => 'DL-OURS',
    ]);

    fakeSweeperQueue([
        ['id' => 940, 'seriesId' => 42, 'episodeId' => 777, 'downloadId' => 'DL-OTHER', 'title' => 'Random.Anime.S01E07.OTHER'],
    ]);

    $removed = resolve(CompetingGrabSweeper::class)->sweep($this->connection, $mediaReplacementAttempt);

    expect($removed)->toBe(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/queue/940'));
});

test('it falls back to series identity when a queue row has no resolvable episode', function (): void {
    // The other side of the same degenerate branch: a row whose episode mapping
    // is missing or unresolved (episodeId 0 is Sonarr's unmapped value).
    $mediaReplacementAttempt = sweeperAttempt($this->connection->id, ['download_id' => 'DL-OURS']);

    fakeSweeperQueue([
        ['id' => 941, 'seriesId' => 42, 'episodeId' => 0, 'downloadId' => 'DL-OTHER', 'title' => 'Random.Anime.Unmapped'],
    ]);

    $removed = resolve(CompetingGrabSweeper::class)->sweep($this->connection, $mediaReplacementAttempt);

    expect($removed)->toBe(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/queue/941'));
});

test('a target with no series id matches nothing and removes nothing', function (): void {
    // The null-parent bail in the shared rule. Without a parent id there is no
    // identity to match on, and matching everything would be catastrophic.
    $mediaReplacementAttempt = sweeperAttempt($this->connection->id, [
        'target' => ['service' => 'sonarr', 'scope' => 'anime', 'episode_ids' => [101]],
        'download_id' => 'DL-OURS',
    ]);

    // Row 943 is the one that actually needs the null bail: an unidentifiable
    // row against an unidentifiable target compares null to null, so only the
    // explicit bail stops "neither has an id" from reading as a match. Row 942
    // would be spared by the id comparison alone.
    fakeSweeperQueue([
        ['id' => 942, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => 'DL-OTHER', 'title' => 'Random.Anime.S01E01.OTHER'],
        ['id' => 943, 'downloadId' => 'DL-NO-SERIES', 'title' => 'Unidentifiable.Row'],
    ]);

    $removed = resolve(CompetingGrabSweeper::class)->sweep($this->connection, $mediaReplacementAttempt);

    expect($removed)->toBe(0);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

test('a legacy target with no service field is read using the connection type', function (): void {
    // The fallback in targetIdentity(). Rows still have to match, so the target
    // must be reduced to the sonarr shape on the strength of the connection
    // alone.
    $mediaReplacementAttempt = sweeperAttempt($this->connection->id, [
        'target' => ['scope' => 'anime', 'series_id' => 42, 'episode_ids' => [101]],
        'download_id' => 'DL-OURS',
    ]);

    fakeSweeperQueue([
        ['id' => 944, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => 'DL-OTHER', 'title' => 'Random.Anime.S01E01.OTHER'],
    ]);

    $removed = resolve(CompetingGrabSweeper::class)->sweep($this->connection, $mediaReplacementAttempt);

    expect($removed)->toBe(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/queue/944'));
});

test('it matches a radarr queue item by movie id', function (): void {
    $radarrConnection = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878', 'api_key' => 'test', 'is_active' => true,
    ]);

    $mediaReplacementAttempt = MediaReplacementAttempt::factory()->create([
        'service_connection_id' => $radarrConnection->id,
        'status' => MediaReplacementStatus::Downloading,
        'scope' => 'movie',
        'target' => ['service' => 'radarr', 'scope' => 'movie', 'movie_id' => 7, 'movie_file_ids' => [70]],
        'candidate' => ['title' => 'Movie.2020.GOOD', 'fingerprint' => 'fp'],
        'download_id' => 'DL-OURS',
    ]);

    Http::fake([
        'radarr.local:7878/api/v3/queue*' => Http::response(['records' => [
            ['id' => 905, 'movieId' => 7, 'downloadId' => 'DL-OTHER', 'title' => 'Movie.2020.OTHER'],
        ]]),
        'radarr.local:7878/api/v3/queue/*' => Http::response([], 200),
    ]);

    $removed = resolve(CompetingGrabSweeper::class)->sweep($radarrConnection, $mediaReplacementAttempt);

    expect($removed)->toBe(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/queue/905'));
});
