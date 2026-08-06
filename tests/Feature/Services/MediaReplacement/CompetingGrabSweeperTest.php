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
use Illuminate\Support\Facades\Log;
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

test('it removes a competing grab from a later queue page', function (): void {
    $mediaReplacementAttempt = sweeperAttempt($this->connection->id);
    $pageOne = [];

    for ($index = 1; $index <= 200; $index++) {
        $pageOne[] = [
            'id' => 1_000 + $index,
            'seriesId' => 99,
            'episodeId' => 999,
            'downloadId' => 'DL-OTHER-'.$index,
            'title' => 'Unrelated.Release.'.$index,
        ];
    }

    Http::fake(function (Request $request) use ($pageOne) {
        if ($request->method() === 'DELETE') {
            return Http::response([], 200);
        }

        if (str_contains($request->url(), 'page=2')) {
            return Http::response([
                'page' => 2,
                'pageSize' => 200,
                'totalRecords' => 201,
                'records' => [[
                    'id' => 9_900,
                    'seriesId' => 42,
                    'episodeId' => 101,
                    'downloadId' => 'DL-PAGE-TWO',
                    'title' => 'Random.Anime.S01E01.PAGE2',
                ]],
            ]);
        }

        return Http::response([
            'page' => 1,
            'pageSize' => 200,
            'totalRecords' => 201,
            'records' => $pageOne,
        ]);
    });

    expect(resolve(CompetingGrabSweeper::class)->sweep($this->connection, $mediaReplacementAttempt))->toBe(1);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), 'page=1'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), 'page=2'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/queue/9900'));
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

test('a multi-row removal sends one aggregated notification, not one per row', function (): void {
    $admin = User::factory()->admin()->create();
    $mediaReplacementAttempt = sweeperAttempt($this->connection->id);

    // Two competing rows for one target. Notifying per row would alert the operator
    // twice about a single event, and a competing season pack can span many more.
    fakeSweeperQueue([
        ['id' => 921, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => 'DL-OTHER-A', 'title' => 'Random.Anime.S01E01.A'],
        ['id' => 922, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => 'DL-OTHER-B', 'title' => 'Random.Anime.S01E01.B'],
    ]);

    expect(resolve(CompetingGrabSweeper::class)->sweep($this->connection, $mediaReplacementAttempt))->toBe(2);

    Notification::assertSentToTimes($admin, MediaReplacementStatusChanged::class, 1);
    Notification::assertSentTo(
        $admin,
        MediaReplacementStatusChanged::class,
        // Both titles named, so the single alert does not hide what the second
        // removal was.
        fn (MediaReplacementStatusChanged $notification): bool => str_contains($notification->message, 'Removed 2 competing downloads')
            && str_contains($notification->message, 'Random.Anime.S01E01.A')
            && str_contains($notification->message, 'Random.Anime.S01E01.B'),
    );
});

test('a season pack sharing one download id is removed once, without 404 noise', function (): void {
    $mediaReplacementAttempt = sweeperAttempt($this->connection->id);

    // Sonarr lists a pack as one row per contained episode, all carrying the same
    // downloadId. removeFromClient settles the whole download on the first call, so
    // the second row would 404 — an expected failure that must not be retried and
    // must not log a warning suggesting something went wrong.
    fakeSweeperQueue([
        ['id' => 931, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => 'DL-PACK', 'title' => 'Random.Anime.S01.Batch'],
        ['id' => 932, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => 'DL-PACK', 'title' => 'Random.Anime.S01.Batch'],
    ]);

    Log::spy();

    expect(resolve(CompetingGrabSweeper::class)->sweep($this->connection, $mediaReplacementAttempt))->toBe(1);

    // Exactly one DELETE: the second row is skipped because its download is already gone.
    Http::assertSentCount(2); // the queue GET plus one DELETE
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/queue/931'));
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/queue/932'));

    Log::shouldNotHaveReceived('warning');
});

test('a malformed queue row is ignored without an array-to-string warning', function (): void {
    $mediaReplacementAttempt = sweeperAttempt($this->connection->id);

    // A row whose downloadId and title are arrays rather than strings. Casting them
    // with (string) would emit "Array to string conversion" into the log for a row
    // we are about to ignore anyway.
    fakeSweeperQueue([
        ['id' => 941, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => ['nested'], 'title' => ['nested']],
    ]);

    $removed = resolve(CompetingGrabSweeper::class)->sweep($this->connection, $mediaReplacementAttempt);

    // Treated as a row with no download id and no title, so it is a competitor and
    // is removed — the point is that it happens without a PHP warning.
    expect($removed)->toBe(1);
});

test('a notification failure neither truncates the sweep nor distorts the removal count', function (): void {
    User::factory()->admin()->create();
    $mediaReplacementAttempt = sweeperAttempt($this->connection->id);

    fakeSweeperQueue([
        ['id' => 907, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => 'DL-OTHER-A', 'title' => 'Random.Anime.S01E01.A'],
        ['id' => 908, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => 'DL-OTHER-B', 'title' => 'Random.Anime.S01E01.B'],
    ]);

    // Notification::fake() never throws, so stand in a dispatcher whose every
    // send fails the way a broken mailer or notifications table would. Once, not
    // twice: the sweep now sends a single aggregated notification after the loop,
    // which is also why a failing dispatcher can no longer truncate the loop by
    // construction rather than by this test's vigilance. The count assertion below
    // is what still has to hold.
    $mock = Mockery::mock(Dispatcher::class);
    $mock->shouldReceive('send')->once()->andThrow(new RuntimeException('notification backend unavailable'));
    Notification::swap($mock);

    $removed = resolve(CompetingGrabSweeper::class)->sweep($this->connection, $mediaReplacementAttempt);

    // Both rows must still be removed, and the count must reflect the queue rather
    // than the notification outcome — a swallowed send must not read as 0 removed.
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

test('a target whose stored service disagrees with the connection matches nothing', function (): void {
    // A Radarr-shaped target stored against a Sonarr connection.
    // Unreachable in practice: inspectFromSnapshot() dispatches on the target's own
    // service, so a contradicting target queries the wrong arr API, returns
    // ambiguous, and trips sameFiles() before any grab. (Not resolveConnection() —
    // that compares payload['service'], not the target's.) But movie ids and series ids are independent
    // id spaces, so movie_id 42 against series 42 is an ordinary numeric
    // collision, not a coincidence. Reading the target in one shape and the
    // queue rows in another makes that collision compare equal, land in the
    // either-side-empty branch, and match EVERY row of the series — deleting
    // them with removeFromClient: true. One shape decision used for both sides
    // is what stops it, and corrupt data then removes nothing at all.
    $mediaReplacementAttempt = sweeperAttempt($this->connection->id, [
        'target' => ['service' => 'radarr', 'scope' => 'movie', 'movie_id' => 42, 'movie_file_ids' => [70]],
        'download_id' => 'DL-OURS',
    ]);

    fakeSweeperQueue([
        ['id' => 945, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => 'DL-A', 'title' => 'Series42.S01E01'],
        ['id' => 946, 'seriesId' => 42, 'episodeId' => 102, 'downloadId' => 'DL-B', 'title' => 'Series42.S01E02'],
    ]);

    $removed = resolve(CompetingGrabSweeper::class)->sweep($this->connection, $mediaReplacementAttempt);

    expect($removed)->toBe(0);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

test('a target stored without a service field still matches its rows', function (): void {
    // Data-shape coverage, not a branch guard: the shape decision comes from the
    // connection, so a target with no `service` field is not a special case any
    // more. Kept because targets predating that field are real rows on disk.
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
