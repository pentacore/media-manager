<?php

declare(strict_types=1);

use App\Enums\ServiceType;
use App\Enums\WhisparrVersion;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Whisparr\WhisparrActions;
use App\Services\Whisparr\WhisparrClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    ServiceConnection::factory()->whisparr()->create([
        'url' => 'http://whisparr.local:6969', 'api_key' => 'k', 'is_active' => true,
    ]);
});

test('deleteItem sends DELETE to the movie resource with deleteFiles', function (): void {
    $serviceConnection = whisparrActionsConnection();
    Http::fake(['whisparr.local:6969/api/v3/movie/99*' => Http::response(null, 200)]);

    $request = ActionRequest::factory()->create([
        'type' => 'whisparr_delete_item',
        'payload' => ['whisparr_item_id' => 99, 'delete_files' => true, 'service_connection_id' => $serviceConnection->id],
    ]);

    $result = (new WhisparrActions)->execute($request);

    expect($result)->toMatchArray(['whisparr_item_id' => 99, 'delete_files' => true]);
    Http::assertSent(fn ($r): bool => $r->method() === 'DELETE'
        && str_contains((string) $r->url(), '/api/v3/movie/99')
        && str_contains((string) $r->url(), 'deleteFiles=true'));
});

test('deleteItem throws when whisparr_item_id is missing', function (): void {
    $request = ActionRequest::factory()->create(['type' => 'whisparr_delete_item', 'payload' => []]);
    expect(fn (): array => (new WhisparrActions)->execute($request))->toThrow(InvalidArgumentException::class);
});

test('execute throws for an unknown type', function (): void {
    $request = ActionRequest::factory()->create(['type' => 'zzz', 'payload' => []]);
    expect(fn (): array => (new WhisparrActions)->execute($request))->toThrow(InvalidArgumentException::class);
});

test('ExecuteActionRequest routes whisparr_* types to WhisparrActions', function (): void {
    $job = new ExecuteActionRequest(
        ActionRequest::factory()->create(['type' => 'whisparr_add_item', 'payload' => []]),
    );
    $resolve = new ReflectionMethod(ExecuteActionRequest::class, 'resolveExecutor');
    expect($resolve->invoke($job, 'whisparr_add_item'))->toBeInstanceOf(WhisparrActions::class);
});

test('addItem sends searchForMissingEpisodes (not searchForMovie) for a v2/series connection', function (): void {
    ServiceConnection::where('is_active', true)->update(['is_active' => false]);
    $v2 = ServiceConnection::factory()->whisparr()->whisparrVersion(WhisparrVersion::V2)->create([
        'url' => 'http://whisparr.local:6969', 'api_key' => 'k', 'is_active' => true,
    ]);

    Http::fake([
        'whisparr.local:6969/api/v3/series/lookup*' => Http::response([['title' => 'X', 'tvdbId' => 1]], 200),
        'whisparr.local:6969/api/v3/series' => Http::response(['id' => 42, 'title' => 'X'], 201),
    ]);

    $request = ActionRequest::factory()->create([
        'type' => 'whisparr_add_item',
        'payload' => ['tmdb_id' => 1, 'quality_profile_id' => 1, 'root_folder_path' => '/media', 'service_connection_id' => $v2->id],
    ]);

    $result = (new WhisparrActions)->execute($request);

    expect($result)->toMatchArray(['whisparr_item_id' => 42, 'title' => 'X', 'tmdb_id' => 1]);

    Http::assertSent(function ($r): bool {
        if ($r->method() !== 'POST' || ! str_contains((string) $r->url(), '/api/v3/series')) {
            return false;
        }

        $body = json_decode((string) $r->body(), true);

        return isset($body['addOptions']['searchForMissingEpisodes'])
            && $body['addOptions']['searchForMissingEpisodes'] === true
            && ! isset($body['addOptions']['searchForMovie']);
    });
});

function whisparrActionsConnection(): ServiceConnection
{
    return ServiceConnection::query()->where('type', ServiceType::Whisparr)->where('is_active', true)->sole();
}

test('whisparr_search sends MoviesSearch to the pinned v3 connection', function (): void {
    $serviceConnection = whisparrActionsConnection();
    Http::fake(['whisparr.local:6969/api/v3/command' => Http::response(['id' => 501, 'name' => 'MoviesSearch'], 201)]);

    $result = (new WhisparrActions)->execute(ActionRequest::factory()->create([
        'type' => 'whisparr_search',
        'payload' => ['whisparr_item_id' => 11, 'service_connection_id' => $serviceConnection->id],
    ]));

    expect($result)->toBe(['whisparr_item_id' => 11, 'whisparr_command_id' => 501]);
    Http::assertSent(fn ($r): bool => $r->method() === 'POST'
        && str_ends_with((string) $r->url(), '/api/v3/command')
        && $r['name'] === 'MoviesSearch'
        && $r['movieIds'] === [11]);
});

test('whisparr_search sends SeriesSearch to a v2 connection', function (): void {
    $v2 = ServiceConnection::factory()->whisparr()->whisparrVersion(WhisparrVersion::V2)->create([
        'url' => 'http://whisparr2.local:6969', 'api_key' => 'k', 'is_active' => true,
    ]);
    Http::fake(['whisparr2.local:6969/api/v3/command' => Http::response(['id' => 7], 201)]);

    (new WhisparrActions)->execute(ActionRequest::factory()->create([
        'type' => 'whisparr_search',
        'payload' => ['whisparr_item_id' => 5, 'service_connection_id' => $v2->id],
    ]));

    Http::assertSent(fn ($r): bool => $r['name'] === 'SeriesSearch' && $r['seriesId'] === 5);
});

test('whisparr_search does not retry a failed command transparently', function (): void {
    $serviceConnection = whisparrActionsConnection();
    Http::fake(['whisparr.local:6969/api/v3/command' => Http::response(['message' => 'boom'], 500)]);

    expect(fn (): array => (new WhisparrActions)->execute(ActionRequest::factory()->create([
        'type' => 'whisparr_search',
        'payload' => ['whisparr_item_id' => 11, 'service_connection_id' => $serviceConnection->id],
    ])))->toThrow(RequestException::class);

    Http::assertSentCount(1);
});

test('whisparr_search refuses a payload without a pin', function (): void {
    expect(fn (): array => (new WhisparrActions)->execute(ActionRequest::factory()->create([
        'type' => 'whisparr_search',
        'payload' => ['whisparr_item_id' => 11],
    ])))->toThrow(InvalidArgumentException::class);
});

test('whisparr_search aborts instead of using another instance when the pinned one was deactivated', function (): void {
    $serviceConnection = whisparrActionsConnection();
    $serviceConnection->update(['is_active' => false]);
    ServiceConnection::factory()->whisparr()->create(['url' => 'http://other.local:6969', 'api_key' => 'k', 'is_active' => true]);

    expect(fn (): array => (new WhisparrActions)->execute(ActionRequest::factory()->create([
        'type' => 'whisparr_search',
        'payload' => ['whisparr_item_id' => 11, 'service_connection_id' => $serviceConnection->id],
    ])))->toThrow(ModelNotFoundException::class);
});

test('ExecuteActionRequest routes whisparr_search to WhisparrActions', function (): void {
    $job = new ExecuteActionRequest(ActionRequest::factory()->create(['type' => 'whisparr_search', 'payload' => []]));
    $resolve = new ReflectionMethod(ExecuteActionRequest::class, 'resolveExecutor');

    expect($resolve->invoke($job, 'whisparr_search'))->toBeInstanceOf(WhisparrActions::class);
});

// R12 hardening: every Whisparr executor resolves its connection strictly
// (ServiceConnection::resolvePinnedStrict()) — a legacy unpinned request or one
// naming a different-type connection must abort instead of silently hitting
// the first Whisparr instance.
test('the pinned-connection Whisparr executors refuse to run without a pinned connection', function (string $type, array $payload): void {
    expect(fn (): array => (new WhisparrActions)->execute(ActionRequest::factory()->create(['type' => $type, 'payload' => $payload])))
        ->toThrow(InvalidArgumentException::class, 'not pinned to a Whisparr connection');

    Http::assertNothingSent();
})->with([
    'whisparr_delete_item' => ['whisparr_delete_item', ['whisparr_item_id' => 11, 'delete_files' => false]],
    'whisparr_add_item' => ['whisparr_add_item', ['tmdb_id' => 1, 'quality_profile_id' => 1, 'root_folder_path' => '/media']],
    'whisparr_monitor_item' => ['whisparr_monitor_item', ['whisparr_item_id' => 11, 'monitored' => true]],
    'whisparr_set_quality_profile' => ['whisparr_set_quality_profile', ['whisparr_item_id' => 11, 'quality_profile_id' => 2]],
    'whisparr_search' => ['whisparr_search', ['whisparr_item_id' => 11]],
]);

test('the pinned-connection Whisparr executors refuse to run against a mismatched connection type', function (string $type, array $payload): void {
    $sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);

    expect(fn (): array => (new WhisparrActions)->execute(ActionRequest::factory()->create([
        'type' => $type,
        'payload' => [...$payload, 'service_connection_id' => $sonarr->id],
    ])))->toThrow(InvalidArgumentException::class, 'not pinned to a Whisparr connection');

    Http::assertNothingSent();
})->with([
    'whisparr_delete_item' => ['whisparr_delete_item', ['whisparr_item_id' => 11, 'delete_files' => false]],
    'whisparr_add_item' => ['whisparr_add_item', ['tmdb_id' => 1, 'quality_profile_id' => 1, 'root_folder_path' => '/media']],
    'whisparr_monitor_item' => ['whisparr_monitor_item', ['whisparr_item_id' => 11, 'monitored' => true]],
    'whisparr_set_quality_profile' => ['whisparr_set_quality_profile', ['whisparr_item_id' => 11, 'quality_profile_id' => 2]],
    'whisparr_search' => ['whisparr_search', ['whisparr_item_id' => 11]],
]);

test('a Whisparr write starts from what Whisparr holds now, not from a cached snapshot', function (string $type, array $payload, array $changedInWhisparr, array $expectedPut): void {
    $serviceConnection = whisparrActionsConnection();
    $upstream = new stdClass;
    $upstream->item = ['id' => 42, 'title' => 'Aurora Scene', 'monitored' => true, 'qualityProfileId' => 1];
    $upstream->puts = [];
    Http::fake([
        'whisparr.local:6969/api/v3/movie/42' => function (Request $request) use ($upstream) {
            if ($request->method() === 'PUT') {
                $upstream->puts[] = $request->data();

                return Http::response($request->data());
            }

            return Http::response($upstream->item);
        },
    ]);

    new WhisparrClient($serviceConnection)->getItemById(42);
    $upstream->item = [...$upstream->item, ...$changedInWhisparr];

    new WhisparrActions()->execute(ActionRequest::factory()->create([
        'type' => $type,
        'payload' => [...$payload, 'service_connection_id' => $serviceConnection->id],
    ]));

    expect($upstream->puts)->toHaveCount(1)
        ->and($upstream->puts[0])->toMatchArray($expectedPut);
})->with([
    'monitoring keeps a profile changed in Whisparr' => ['whisparr_monitor_item', ['whisparr_item_id' => 42, 'monitored' => false], ['qualityProfileId' => 4], ['monitored' => false, 'qualityProfileId' => 4]],
    'a profile change keeps monitoring changed in Whisparr' => ['whisparr_set_quality_profile', ['whisparr_item_id' => 42, 'quality_profile_id' => 7], ['monitored' => false], ['monitored' => false, 'qualityProfileId' => 7]],
]);
