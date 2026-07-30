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
        'download_id' => null,
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
});

test('it leaves the vetted release alone when matched by title', function (): void {
    $mediaReplacementAttempt = sweeperAttempt($this->connection->id);

    fakeSweeperQueue([
        ['id' => 901, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => 'DL-OURS', 'title' => 'trusted.anime.s01e01.cr'],
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

test('it refuses to sweep when neither a title nor a download id can identify our own grab', function (): void {
    $mediaReplacementAttempt = sweeperAttempt($this->connection->id, [
        'candidate' => [], 'download_id' => null,
    ]);

    fakeSweeperQueue([
        ['id' => 904, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => 'DL-ANY', 'title' => 'Anything'],
    ]);

    $removed = resolve(CompetingGrabSweeper::class)->sweep($this->connection, $mediaReplacementAttempt);

    expect($removed)->toBe(0);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
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
        'download_id' => null,
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
