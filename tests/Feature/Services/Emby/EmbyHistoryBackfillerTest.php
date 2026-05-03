<?php

declare(strict_types=1);

use App\Models\EmbyActivity;
use App\Models\EmbyUserLink;
use App\Models\ServiceConnection;
use App\Services\Emby\EmbyClient;
use App\Services\Emby\EmbyHistoryBackfiller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->connection = ServiceConnection::factory()->emby()->create([
        'url' => 'http://emby.local:8096',
        'api_key' => 'emby-test-key',
    ]);

    $this->link = EmbyUserLink::factory()->create([
        'emby_user_id' => 'emby-user-1',
        'emby_username' => 'alice',
    ]);

    $this->backfiller = new EmbyHistoryBackfiller(new EmbyClient($this->connection));
});

/**
 * @return array<string, int|mixed[]>
 */
function fakeEmbyItemsResponse(array $items, ?int $totalRecordCount = null): array
{
    return [
        'Items' => $items,
        'TotalRecordCount' => $totalRecordCount ?? count($items),
    ];
}

test('maps a played movie into a finished activity row', function (): void {
    Http::fake([
        'emby.local:8096/Users/emby-user-1/Items*' => Http::response(fakeEmbyItemsResponse([
            [
                'Id' => 'item-1',
                'Type' => 'Movie',
                'Name' => 'The Matrix',
                'RunTimeTicks' => 80000000000,
                'UserData' => [
                    'Played' => true,
                    'PlaybackPositionTicks' => 0,
                    'LastPlayedDate' => '2025-12-01T10:00:00.000Z',
                ],
            ],
        ])),
    ]);

    $result = $this->backfiller->backfillUser($this->link);

    expect($result->itemsCreated)->toBe(1);
    expect($result->itemsFetched)->toBe(1);
    expect($result->itemsSkipped)->toBe(0);

    $this->assertDatabaseHas('emby_activities', [
        'emby_user_link_id' => $this->link->id,
        'emby_item_id' => 'item-1',
        'media_type' => 'movie',
        'media_title' => 'The Matrix',
        'series_title' => null,
        'action' => 'finished',
        'duration_ticks' => 80000000000,
        'play_position' => 0,
        'play_session_id' => null,
    ]);
});

test('maps a partial-progress episode into a stopped activity row', function (): void {
    Http::fake([
        'emby.local:8096/Users/emby-user-1/Items*' => Http::response(fakeEmbyItemsResponse([
            [
                'Id' => 'item-2',
                'Type' => 'Episode',
                'Name' => 'Pilot',
                'SeriesName' => 'Breaking Bad',
                'RunTimeTicks' => 30000000000,
                'UserData' => [
                    'Played' => false,
                    'PlaybackPositionTicks' => 5000000000,
                    'LastPlayedDate' => '2025-12-02T10:00:00.000Z',
                ],
            ],
        ])),
    ]);

    $result = $this->backfiller->backfillUser($this->link);

    expect($result->itemsCreated)->toBe(1);

    $this->assertDatabaseHas('emby_activities', [
        'emby_item_id' => 'item-2',
        'media_type' => 'episode',
        'media_title' => 'Pilot',
        'series_title' => 'Breaking Bad',
        'action' => 'stopped',
        'play_position' => 5000000000,
    ]);
});

test('skips items with no playback progress and no played flag', function (): void {
    Http::fake([
        'emby.local:8096/Users/emby-user-1/Items*' => Http::response(fakeEmbyItemsResponse([
            [
                'Id' => 'item-3',
                'Type' => 'Movie',
                'Name' => 'Untouched',
                'UserData' => ['Played' => false, 'PlaybackPositionTicks' => 0],
            ],
        ])),
    ]);

    $result = $this->backfiller->backfillUser($this->link);

    expect($result->itemsSkipped)->toBe(1);
    expect($result->itemsCreated)->toBe(0);
    expect(EmbyActivity::count())->toBe(0);
});

test('skips items whose Type is not Movie or Episode', function (): void {
    Http::fake([
        'emby.local:8096/Users/emby-user-1/Items*' => Http::response(fakeEmbyItemsResponse([
            [
                'Id' => 'item-4',
                'Type' => 'MusicAlbum',
                'Name' => 'An Album',
                'UserData' => ['Played' => true],
            ],
        ])),
    ]);

    $result = $this->backfiller->backfillUser($this->link);

    expect($result->itemsSkipped)->toBe(1);
    expect(EmbyActivity::count())->toBe(0);
});

test('writes LastPlayedDate verbatim into created_at and updated_at', function (): void {
    Http::fake([
        'emby.local:8096/Users/emby-user-1/Items*' => Http::response(fakeEmbyItemsResponse([
            [
                'Id' => 'item-5',
                'Type' => 'Movie',
                'Name' => 'Dated',
                'UserData' => [
                    'Played' => true,
                    'LastPlayedDate' => '2024-06-15T14:30:00.000Z',
                ],
            ],
        ])),
    ]);

    $this->backfiller->backfillUser($this->link);

    $activity = EmbyActivity::where('emby_item_id', 'item-5')->firstOrFail();
    expect($activity->created_at->equalTo(CarbonImmutable::parse('2024-06-15T14:30:00.000Z')))->toBeTrue();
    expect($activity->updated_at->equalTo(CarbonImmutable::parse('2024-06-15T14:30:00.000Z')))->toBeTrue();
});

test('falls back to now when LastPlayedDate is missing', function (): void {
    CarbonImmutable::setTestNow('2026-05-03 12:00:00');

    Http::fake([
        'emby.local:8096/Users/emby-user-1/Items*' => Http::response(fakeEmbyItemsResponse([
            [
                'Id' => 'item-6',
                'Type' => 'Movie',
                'Name' => 'NoDate',
                'UserData' => ['Played' => true],
            ],
        ])),
    ]);

    $this->backfiller->backfillUser($this->link);

    $activity = EmbyActivity::where('emby_item_id', 'item-6')->firstOrFail();
    expect($activity->created_at->equalTo(CarbonImmutable::parse('2026-05-03 12:00:00')))->toBeTrue();

    CarbonImmutable::setTestNow();
});

test('is idempotent across runs — re-running yields zero new rows', function (): void {
    Http::fake([
        'emby.local:8096/Users/emby-user-1/Items*' => Http::response(fakeEmbyItemsResponse([
            [
                'Id' => 'item-7',
                'Type' => 'Movie',
                'Name' => 'IdempotentMovie',
                'UserData' => [
                    'Played' => true,
                    'LastPlayedDate' => '2025-01-01T00:00:00.000Z',
                ],
            ],
        ])),
    ]);

    $first = $this->backfiller->backfillUser($this->link);
    $second = $this->backfiller->backfillUser($this->link);

    expect($first->itemsCreated)->toBe(1);
    expect($second->itemsCreated)->toBe(0);
    expect($second->itemsUpdated)->toBe(1);
    expect(EmbyActivity::where('emby_item_id', 'item-7')->count())->toBe(1);
});

test('paginates by incrementing StartIndex until all items consumed', function (): void {
    Http::fake([
        'emby.local:8096/Users/emby-user-1/Items*' => Http::sequence()
            ->push(fakeEmbyItemsResponse(
                items: [
                    [
                        'Id' => 'page1-1',
                        'Type' => 'Movie',
                        'Name' => 'P1A',
                        'UserData' => ['Played' => true],
                    ],
                    [
                        'Id' => 'page1-2',
                        'Type' => 'Movie',
                        'Name' => 'P1B',
                        'UserData' => ['Played' => true],
                    ],
                ],
                totalRecordCount: 3
            ))
            ->push(fakeEmbyItemsResponse(
                items: [
                    [
                        'Id' => 'page2-1',
                        'Type' => 'Movie',
                        'Name' => 'P2A',
                        'UserData' => ['Played' => true],
                    ],
                ],
                totalRecordCount: 3
            )),
    ]);

    $result = $this->backfiller->backfillUser($this->link, pageSize: 2);

    expect($result->itemsCreated)->toBe(3);
    expect(EmbyActivity::count())->toBe(3);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'StartIndex=0'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'StartIndex=2'));
});

test('passes since as MinDateLastSaved when provided', function (): void {
    Http::fake([
        'emby.local:8096/Users/emby-user-1/Items*' => Http::response(fakeEmbyItemsResponse([])),
    ]);

    $this->backfiller->backfillUser(
        $this->link,
        since: CarbonImmutable::parse('2025-06-01 00:00:00'),
    );

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'MinDateLastSaved=2025-06-01'));
});

test('dry-run reads from API but writes nothing', function (): void {
    Http::fake([
        'emby.local:8096/Users/emby-user-1/Items*' => Http::response(fakeEmbyItemsResponse([
            [
                'Id' => 'item-dry',
                'Type' => 'Movie',
                'Name' => 'DryMovie',
                'UserData' => ['Played' => true],
            ],
        ])),
    ]);

    $result = $this->backfiller->backfillUser($this->link, dryRun: true);

    expect($result->itemsFetched)->toBe(1);
    expect($result->itemsCreated)->toBe(1);
    expect(EmbyActivity::count())->toBe(0);
});
