<?php

declare(strict_types=1);

use App\Models\AnimeIdMap;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('the request button stays disabled until a Seerr user is picked, then submits as that user', function (): void {
    config()->set('mediamanager.anime.source', 'anilist');
    Date::setTestNow(Date::create(2026, 8, 15, 12));
    Queue::fake();

    ServiceConnection::factory()->seerr()->create([
        'url' => 'http://seerr.local:5055',
        'api_key' => 'test-api-key',
    ]);

    // A single mapped, requestable entry.
    AnimeIdMap::factory()->tv()->create([
        'anilist_id' => 154587,
        'tmdb_tv_id' => 1396,
        'tvdb_id' => 81189,
        'tmdb_season' => 1,
    ]);

    Http::fake([
        'graphql.anilist.co' => Http::response([
            'data' => [
                'Page' => [
                    'pageInfo' => ['hasNextPage' => false],
                    'media' => [
                        [
                            'id' => 154587,
                            'idMal' => 52991,
                            'format' => 'TV',
                            'status' => 'RELEASING',
                            'episodes' => 12,
                            'popularity' => 5000,
                            'averageScore' => 88,
                            'title' => ['romaji' => 'Test', 'english' => 'Test Show'],
                            'startDate' => ['year' => 2026, 'month' => 7, 'day' => 1],
                            'coverImage' => ['large' => 'https://img/test.jpg'],
                        ],
                    ],
                ],
            ],
        ]),
        'seerr.local:5055/api/v1/request*' => Http::response(['results' => []]),
        'seerr.local:5055/api/v1/user*' => Http::response(['results' => [
            ['id' => 4, 'displayName' => 'Alice', 'email' => 'alice@example.com'],
            ['id' => 5, 'displayName' => 'Bob', 'email' => 'bob@example.com'],
        ]]),
    ]);

    // No email match against either faked Seerr user, so pickerOptions()
    // returns no default — the picker starts on its "Select user" placeholder.
    $member = User::factory()->member()->create(['email' => 'nobody@example.com']);
    $this->actingAs($member);

    $webpage = visit(route('media.anime.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-anime-user-select]', 'Select user')
        ->assertSee('Test Show')
        ->assertDisabled('[data-anime-request]');

    $webpage->click('[data-anime-user-select]')
        ->click('[data-slot="select-item"]:has-text("Alice")')
        ->assertEnabled('[data-anime-request]')
        ->click('[data-anime-request]')
        ->assertSee('Request submitted.');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/api/v1/request')
        && ($request->data()['userId'] ?? null) === 4);
});
