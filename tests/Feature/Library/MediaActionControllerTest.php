<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Enums\ServiceType;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Actions\ManualActionDispatcher;
use App\Services\Actions\ManualActionOutcome;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
    $this->seed(ActionTypeConfigSeeder::class);

    $this->sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k', 'name' => 'Sonarr']);
    $this->radarr = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k', 'name' => 'Radarr']);
    IndexedSeries::factory()->for($this->sonarr, 'serviceConnection')->create(['sonarr_id' => 7, 'title' => 'Severance', 'year' => 2022]);
    IndexedMovie::factory()->for($this->radarr, 'serviceConnection')->create(['radarr_id' => 10, 'title' => 'Dune', 'year' => 2021]);
    $this->member = User::factory()->member()->create(['name' => 'Mia']);
});

/**
 * Episodes 70 and 71 (season 1) and 80 (season 2) of series 7, for Http::fake().
 *
 * @return array<string, mixed>
 */
function mediaActionEpisodesOfSeriesSeven(): array
{
    return ['sonarr.local:8989/api/v3/episode?seriesId=7*' => Http::response([
        ['id' => 70, 'seriesId' => 7, 'seasonNumber' => 1],
        ['id' => 71, 'seriesId' => 7, 'seasonNumber' => 1],
        ['id' => 80, 'seriesId' => 7, 'seasonNumber' => 2],
    ])];
}

test('a member toggles series monitoring through the action pipeline, pinned to the connection', function (): void {
    $this->actingAs($this->member)
        ->post(route('media.library.actions.monitor'), ['service' => 'sonarr', 'service_connection_id' => $this->sonarr->id, 'item_id' => 7, 'monitored' => false])
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast.type', 'success')
        ->assertSessionHas('inertia.flash_data.toast.message', 'Monitoring updated.');

    $actionRequest = ActionRequest::query()->where('type', 'monitor_series')->sole();
    expect($actionRequest->origin)->toBe('manual')
        ->and($actionRequest->status)->toBe(ActionRequestStatus::Approved)
        ->and($actionRequest->payload)->toEqual(['series_id' => 7, 'monitored' => false, 'service_connection_id' => $this->sonarr->id])
        ->and($actionRequest->title)->toBe('Unmonitor series "Severance (2022)"');
});

test('an action type that requires approval is queued and says so', function (): void {
    ActionTypeConfig::query()->where('type', 'monitor_movie')->update(['requires_approval' => true]);

    $this->actingAs($this->member)
        ->post(route('media.library.actions.monitor'), ['service' => 'radarr', 'service_connection_id' => $this->radarr->id, 'item_id' => 10, 'monitored' => true])
        ->assertSessionHas('inertia.flash_data.toast.type', 'info')
        ->assertSessionHas('inertia.flash_data.toast.message', 'Queued for approval in the Action Queue.');

    expect(ActionRequest::query()->where('type', 'monitor_movie')->sole()->status)->toBe(ActionRequestStatus::Pending);
});

test('a disabled action type is refused', function (): void {
    ActionTypeConfig::query()->where('type', 'search_media')->update(['is_enabled' => false]);

    $this->actingAs($this->member)
        ->post(route('media.library.actions.search'), ['service' => 'sonarr', 'service_connection_id' => $this->sonarr->id, 'command' => 'series_search', 'series_id' => 7])
        ->assertSessionHas('inertia.flash_data.toast.message', 'This action is disabled in Action Rules.');

    expect(ActionRequest::query()->count())->toBe(0);
});

test('monitoring is refused while a replacement is in flight for the title', function (): void {
    ActionRequest::factory()->create([
        'type' => 'replace_media_file',
        'status' => ActionRequestStatus::Pending,
        'payload' => ['target' => ['service' => 'sonarr', 'service_connection_id' => $this->sonarr->id, 'series_id' => 7, 'season_number' => 1, 'episode_numbers' => [1]]],
    ]);

    $this->actingAs($this->member)
        ->post(route('media.library.actions.monitor-episodes'), ['service_connection_id' => $this->sonarr->id, 'series_id' => 7, 'episode_ids' => [70], 'monitored' => false])
        ->assertSessionHas('inertia.flash_data.toast.message', 'A file replacement is in progress for this title — try again when it finishes.');

    expect(ActionRequest::query()->where('type', 'monitor_episodes')->exists())->toBeFalse();
});

test("a season toggle sends that season's episode ids", function (): void {
    Http::fake(mediaActionEpisodesOfSeriesSeven());

    $this->actingAs($this->member)
        ->post(route('media.library.actions.monitor-episodes'), ['service_connection_id' => $this->sonarr->id, 'series_id' => 7, 'episode_ids' => [70, 71], 'season_number' => 1, 'monitored' => true])
        ->assertSessionHas('inertia.flash_data.toast.message', 'Monitoring updated.');

    expect(ActionRequest::query()->where('type', 'monitor_episodes')->sole()->payload)
        ->toEqual(['series_id' => 7, 'episode_ids' => [70, 71], 'season_number' => 1, 'monitored' => true, 'service_connection_id' => $this->sonarr->id]);
});

test('a connection that does not match the service is rejected', function (): void {
    $this->actingAs($this->member)
        ->post(route('media.library.actions.monitor'), ['service' => 'sonarr', 'service_connection_id' => $this->radarr->id, 'item_id' => 7, 'monitored' => true])
        ->assertStatus(422);
});

test('a quality profile change is dispatched with the profile id', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/qualityprofile' => Http::response([['id' => 6, 'name' => 'Ultra-HD']])]);

    $this->actingAs($this->member)
        ->post(route('media.library.actions.quality-profile'), ['service' => 'sonarr', 'service_connection_id' => $this->sonarr->id, 'item_id' => 7, 'quality_profile_id' => 6])
        ->assertSessionHas('inertia.flash_data.toast.message', 'Quality profile updated.');

    expect(ActionRequest::query()->where('type', 'set_series_quality_profile')->sole()->payload)
        ->toEqual(['series_id' => 7, 'quality_profile_id' => 6, 'service_connection_id' => $this->sonarr->id]);
});

test('searches are dispatched as search_media with only the fields the command needs', function (array $body, array $payload): void {
    Http::fake(mediaActionEpisodesOfSeriesSeven());

    $this->actingAs($this->member)
        ->post(route('media.library.actions.search'), ['service_connection_id' => $this->{$body['service']}->id, ...$body])
        ->assertSessionHas('inertia.flash_data.toast.message', 'Search started.');

    expect(ActionRequest::query()->where('type', 'search_media')->sole()->payload)
        ->toMatchArray($payload);
})->with([
    'season' => [['service' => 'sonarr', 'command' => 'season_search', 'series_id' => 7, 'season_number' => 2], ['service' => 'sonarr', 'command' => 'season_search', 'series_id' => 7, 'season_number' => 2]],
    'episode' => [['service' => 'sonarr', 'command' => 'episode_search', 'series_id' => 7, 'episode_ids' => [70]], ['command' => 'episode_search', 'episode_ids' => [70]]],
    'movie' => [['service' => 'radarr', 'command' => 'movies_search', 'movie_ids' => [10]], ['service' => 'radarr', 'movie_ids' => [10]]],
    'all missing movies' => [['service' => 'radarr', 'command' => 'missing_movies_search'], ['service' => 'radarr', 'command' => 'missing_movies_search']],
]);

test('a search command must belong to the service and carry its ids', function (array $body, string $field): void {
    $this->actingAs($this->member)
        ->post(route('media.library.actions.search'), ['service_connection_id' => $this->sonarr->id, 'service' => 'sonarr', ...$body])
        ->assertSessionHasErrors($field);
})->with([
    'radarr command on sonarr' => [['command' => 'movies_search', 'movie_ids' => [1]], 'command'],
    'episode search without ids' => [['command' => 'episode_search', 'series_id' => 7], 'episode_ids'],
    'season search without number' => [['command' => 'season_search', 'series_id' => 7], 'season_number'],
    'unknown command' => [['command' => 'RssSync'], 'command'],
]);

test('releases are fetched from Sonarr for a season, presented and remembered for the grab', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/release*' => Http::response([
        [
            'guid' => 'guid-1', 'indexerId' => 3, 'title' => 'Severance.S01.1080p', 'indexer' => 'NZBgeek', 'protocol' => 'usenet',
            'size' => 10_000, 'ageHours' => 30.25, 'seeders' => null, 'rejected' => false, 'rejections' => [],
            'quality' => ['quality' => ['name' => 'WEBDL-1080p']], 'downloadUrl' => 'https://secret',
        ],
        [
            'guid' => 'guid-2', 'indexerId' => 4, 'title' => 'Severance.S01.720p', 'indexer' => 'Tracker', 'protocol' => 'torrent',
            'size' => 5_000, 'ageHours' => 2, 'seeders' => 12, 'rejected' => true, 'rejections' => ['Not an upgrade'],
            'quality' => ['quality' => ['name' => 'HDTV-720p']],
        ],
    ])]);

    $this->actingAs($this->member)
        ->getJson(route('media.library.actions.releases', ['service' => 'sonarr', 'service_connection_id' => $this->sonarr->id, 'item_id' => 7, 'season_number' => 1]))
        ->assertOk()
        ->assertJsonPath('releases.0', [
            'key' => hash('sha256', 'guid-1'), 'indexer_id' => 3, 'title' => 'Severance.S01.1080p', 'quality' => 'WEBDL-1080p', 'size' => 10000,
            'age_hours' => 30.3, 'peers' => null, 'protocol' => 'usenet', 'indexer' => 'NZBgeek', 'rejected' => false, 'rejections' => [],
        ])
        ->assertJsonPath('releases.1.peers', 12)
        ->assertJsonPath('releases.1.rejected', true)
        ->assertJsonMissingPath('releases.0.guid')
        ->assertJsonMissingPath('releases.1.guid')
        ->assertDontSee('guid-1', false)
        ->assertDontSee('guid-2', false);

    Http::assertSent(fn (Request $request): bool => $request['seriesId'] === 7 && $request['seasonNumber'] === 1);
});

test('an episode release search asks Sonarr by episode id', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/release*' => Http::response([]), ...mediaActionEpisodesOfSeriesSeven()]);

    $this->actingAs($this->member)
        ->getJson(route('media.library.actions.releases', ['service' => 'sonarr', 'service_connection_id' => $this->sonarr->id, 'item_id' => 7, 'episode_id' => 70]))
        ->assertOk();

    Http::assertSent(fn (Request $request): bool => ($request['episodeId'] ?? null) === 70 && ! isset($request['seriesId']));
});

test('release searches are throttled per user, with a JSON message the dialog can show', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/release*' => Http::response([])]);

    for ($attempt = 1; $attempt <= 6; $attempt++) {
        $this->actingAs($this->member)
            ->getJson(route('media.library.actions.releases', ['service' => 'sonarr', 'service_connection_id' => $this->sonarr->id, 'item_id' => 7, 'season_number' => 1]))
            ->assertOk();
    }

    $this->actingAs($this->member)
        ->getJson(route('media.library.actions.releases', ['service' => 'sonarr', 'service_connection_id' => $this->sonarr->id, 'item_id' => 7, 'season_number' => 1]))
        ->assertStatus(429)
        ->assertJsonPath('message', 'Too many release searches — wait a moment.');
});

test('an unreachable Sonarr answers 502 for releases', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/release*' => Http::response([], 503)]);

    $this->actingAs($this->member)
        ->getJson(route('media.library.actions.releases', ['service' => 'sonarr', 'service_connection_id' => $this->sonarr->id, 'item_id' => 7, 'season_number' => 1]))
        ->assertStatus(502)
        ->assertJsonPath('message', 'Sonarr is unreachable.');
});

test('grab dispatches the remembered release and never trusts browser-sent release facts', function (): void {
    Http::fake(['radarr.local:7878/api/v3/release*' => Http::response([
        ['guid' => 'guid-9', 'indexerId' => 5, 'title' => 'Dune.2021.2160p', 'protocol' => 'torrent', 'seeders' => 40, 'size' => 50_000, 'rejections' => [], 'quality' => ['quality' => ['name' => 'Bluray-2160p']]],
    ])]);
    $this->actingAs($this->member)->getJson(route('media.library.actions.releases', ['service' => 'radarr', 'service_connection_id' => $this->radarr->id, 'item_id' => 10]));

    $this->actingAs($this->member)
        ->postJson(route('media.library.actions.grab'), ['service' => 'radarr', 'service_connection_id' => $this->radarr->id, 'item_id' => 10, 'release_key' => hash('sha256', 'guid-9'), 'indexer_id' => 5, 'title' => 'Forged Title'])
        ->assertCreated()
        ->assertJsonPath('requires_approval', false)
        ->assertJsonPath('message', 'Release sent to the download client.');

    $actionRequest = ActionRequest::query()->where('type', 'grab_release')->sole();
    expect($actionRequest->payload['release']['title'])->toBe('Dune.2021.2160p')
        ->and($actionRequest->payload['release'])->not->toHaveKey('guid')
        ->and($actionRequest->payload['release'])->not->toHaveKey('target')
        ->and($actionRequest->payload['guid'])->toBe('guid-9')
        ->and($actionRequest->payload['movie_id'])->toBe(10)
        ->and($actionRequest->payload['service_connection_id'])->toBe($this->radarr->id)
        ->and($actionRequest->description)->toContain('Dune.2021.2160p');
});

test('a release cached under one connection is refused for a grab pinned to another', function (): void {
    $otherSonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr2.local:8989', 'api_key' => 'k2', 'name' => 'Sonarr 2']);
    IndexedSeries::factory()->for($otherSonarr, 'serviceConnection')->create(['sonarr_id' => 7, 'title' => 'Severance', 'year' => 2022]);
    Http::fake(['sonarr.local:8989/api/v3/release*' => Http::response([
        ['guid' => 'guid-1', 'indexerId' => 3, 'title' => 'Severance.S01.1080p', 'protocol' => 'usenet', 'size' => 10_000, 'rejections' => [], 'quality' => ['quality' => ['name' => 'WEBDL-1080p']]],
    ])]);
    $this->actingAs($this->member)->getJson(route('media.library.actions.releases', ['service' => 'sonarr', 'service_connection_id' => $this->sonarr->id, 'item_id' => 7, 'season_number' => 1]));

    $this->actingAs($this->member)
        ->postJson(route('media.library.actions.grab'), ['service' => 'sonarr', 'service_connection_id' => $otherSonarr->id, 'item_id' => 7, 'release_key' => hash('sha256', 'guid-1'), 'indexer_id' => 3])
        ->assertStatus(422)
        ->assertJsonPath('message', 'That release is no longer available — run the search again.');

    expect(ActionRequest::query()->count())->toBe(0);
});

test('a grab queued for approval reports requires_approval and the queued message', function (): void {
    ActionTypeConfig::query()->where('type', 'grab_release')->update(['requires_approval' => true]);
    Http::fake(['radarr.local:7878/api/v3/release*' => Http::response([
        ['guid' => 'guid-9', 'indexerId' => 5, 'title' => 'Dune.2021.2160p', 'protocol' => 'torrent', 'seeders' => 40, 'size' => 50_000, 'rejections' => [], 'quality' => ['quality' => ['name' => 'Bluray-2160p']]],
    ])]);
    $this->actingAs($this->member)->getJson(route('media.library.actions.releases', ['service' => 'radarr', 'service_connection_id' => $this->radarr->id, 'item_id' => 10]));

    $this->actingAs($this->member)
        ->postJson(route('media.library.actions.grab'), ['service' => 'radarr', 'service_connection_id' => $this->radarr->id, 'item_id' => 10, 'release_key' => hash('sha256', 'guid-9'), 'indexer_id' => 5])
        ->assertCreated()
        ->assertJsonPath('requires_approval', true)
        ->assertJsonPath('message', 'Queued for approval in the Action Queue.');

    expect(ActionRequest::query()->where('type', 'grab_release')->sole()->status)->toBe(ActionRequestStatus::Pending);
});

test('grabbing a disabled action type answers 422 with the disabled message', function (): void {
    ActionTypeConfig::query()->where('type', 'grab_release')->update(['is_enabled' => false]);
    Http::fake(['radarr.local:7878/api/v3/release*' => Http::response([
        ['guid' => 'guid-9', 'indexerId' => 5, 'title' => 'Dune.2021.2160p', 'protocol' => 'torrent', 'seeders' => 40, 'size' => 50_000, 'rejections' => [], 'quality' => ['quality' => ['name' => 'Bluray-2160p']]],
    ])]);
    $this->actingAs($this->member)->getJson(route('media.library.actions.releases', ['service' => 'radarr', 'service_connection_id' => $this->radarr->id, 'item_id' => 10]));

    $this->actingAs($this->member)
        ->postJson(route('media.library.actions.grab'), ['service' => 'radarr', 'service_connection_id' => $this->radarr->id, 'item_id' => 10, 'release_key' => hash('sha256', 'guid-9'), 'indexer_id' => 5])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This action is disabled in Action Rules.');

    expect(ActionRequest::query()->count())->toBe(0);
});

// The controller maps an undescribable outcome onto the same HTTP 422 path
// as a disabled one (`abort_unless($outcome->dispatched(), 422, $outcome
// ->toast('')['message'])`), already exercised end-to-end above for
// DISABLED. Reaching UNDESCRIBABLE for real needs a pinned connection that
// vanished between search and grab, but GrabReleaseRequest::connection()
// already 422s that case before ManualActionDispatcher ever runs — and both
// ManualActionDispatcher and ActionDescriber are final, so the outcome can't
// be mocked in from the HTTP layer either. Assert the message text itself
// directly, alongside the dispatcher-level test above that proves the state
// is reachable.
test('an undescribable outcome carries the refresh-and-retry toast message', function (): void {
    expect(new ManualActionOutcome(state: ManualActionOutcome::UNDESCRIBABLE)->toast('')['message'])
        ->toBe('That item could not be found — refresh and try again.');
});

test('an unreachable Radarr answers 502 for releases', function (): void {
    Http::fake(['radarr.local:7878/api/v3/release*' => Http::response([], 503)]);

    $this->actingAs($this->member)
        ->getJson(route('media.library.actions.releases', ['service' => 'radarr', 'service_connection_id' => $this->radarr->id, 'item_id' => 10]))
        ->assertStatus(502)
        ->assertJsonPath('message', 'Radarr is unreachable.');
});

test('grabbing a release that was never listed is refused', function (): void {
    $this->actingAs($this->member)
        ->postJson(route('media.library.actions.grab'), ['service' => 'sonarr', 'service_connection_id' => $this->sonarr->id, 'item_id' => 7, 'release_key' => str_repeat('0', 64), 'indexer_id' => 1])
        ->assertStatus(422)
        ->assertJsonPath('message', 'That release is no longer available — run the search again.');

    expect(ActionRequest::query()->count())->toBe(0);
});

test('grabbing a release with the item id of a different title is refused', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/release*' => Http::response([
        ['guid' => 'guid-1', 'indexerId' => 3, 'title' => 'Severance.S01.1080p', 'protocol' => 'usenet', 'size' => 10_000, 'rejections' => [], 'quality' => ['quality' => ['name' => 'WEBDL-1080p']]],
    ])]);
    $this->actingAs($this->member)->getJson(route('media.library.actions.releases', ['service' => 'sonarr', 'service_connection_id' => $this->sonarr->id, 'item_id' => 7, 'season_number' => 1]));

    $this->actingAs($this->member)
        ->postJson(route('media.library.actions.grab'), ['service' => 'sonarr', 'service_connection_id' => $this->sonarr->id, 'item_id' => 99, 'release_key' => hash('sha256', 'guid-1'), 'indexer_id' => 3])
        ->assertStatus(422)
        ->assertJsonPath('message', 'That release is no longer available — run the search again.');

    expect(ActionRequest::query()->count())->toBe(0);
});

test('the dispatcher turns a deleted pinned connection into an undescribable outcome, not a server error', function (): void {
    // The MediaActionController's FormRequests already 422 a deleted/wrong-type
    // pin before ManualActionDispatcher ever runs (see the next test), but
    // ManualActionDispatcher is a general-purpose entry point (also reachable
    // from other callers), so it must not surface a 500 on its own when
    // ActionDescriber::describe() resolves a strictly-pinned connection
    // (monitor_episodes/search_media/grab_release) that no longer exists.
    $sonarrId = $this->sonarr->id;
    $this->sonarr->delete();

    $manualActionOutcome = resolve(ManualActionDispatcher::class)->dispatch(
        'search_media',
        ServiceType::Sonarr,
        ['command' => 'series_search', 'series_id' => 7, 'service_connection_id' => $sonarrId],
        'Test.',
    );

    expect($manualActionOutcome->state)->toBe(ManualActionOutcome::UNDESCRIBABLE)
        ->and($manualActionOutcome->dispatched())->toBeFalse();
    expect(ActionRequest::query()->count())->toBe(0);
});

test('a wrong-type pin is refused at validation before it ever reaches the describer', function (): void {
    $this->actingAs($this->member)
        ->post(route('media.library.actions.search'), ['service' => 'sonarr', 'service_connection_id' => $this->radarr->id, 'command' => 'series_search', 'series_id' => 7])
        ->assertStatus(422);

    expect(ActionRequest::query()->count())->toBe(0);
});

test("an episode toggle naming episodes that are not that series' is refused and nothing is filed", function (array $body): void {
    Http::fake(mediaActionEpisodesOfSeriesSeven());

    $this->actingAs($this->member)
        ->post(route('media.library.actions.monitor-episodes'), ['service_connection_id' => $this->sonarr->id, 'series_id' => 7, 'monitored' => false, ...$body])
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast.type', 'error')
        ->assertSessionHas('inertia.flash_data.toast.message', 'Those episodes are not part of this series — refresh and try again.');

    expect(ActionRequest::query()->where('type', 'monitor_episodes')->exists())->toBeFalse();
})->with([
    'an episode of another series' => [['episode_ids' => [70, 999]]],
    "a season toggle carrying another season's episode" => [['episode_ids' => [70, 80], 'season_number' => 1]],
]);

test("an episode search naming episodes that are not that series' is refused and nothing is filed", function (): void {
    Http::fake(mediaActionEpisodesOfSeriesSeven());

    $this->actingAs($this->member)
        ->post(route('media.library.actions.search'), ['service' => 'sonarr', 'service_connection_id' => $this->sonarr->id, 'command' => 'episode_search', 'series_id' => 7, 'episode_ids' => [999]])
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast.type', 'error')
        ->assertSessionHas('inertia.flash_data.toast.message', 'Those episodes are not part of this series — refresh and try again.');

    expect(ActionRequest::query()->where('type', 'search_media')->exists())->toBeFalse();
});

test('an episode release search for an episode of another series is refused before Sonarr is searched', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/release*' => Http::response([]), ...mediaActionEpisodesOfSeriesSeven()]);

    $this->actingAs($this->member)
        ->getJson(route('media.library.actions.releases', ['service' => 'sonarr', 'service_connection_id' => $this->sonarr->id, 'item_id' => 7, 'episode_id' => 999]))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Those episodes are not part of this series — refresh and try again.');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/release'));
});

test('an unreachable Sonarr refuses an episode toggle without filing it', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/episode*' => fn (): never => throw new ConnectionException('Connection refused.')]);

    $this->actingAs($this->member)
        ->post(route('media.library.actions.monitor-episodes'), ['service_connection_id' => $this->sonarr->id, 'series_id' => 7, 'episode_ids' => [70], 'monitored' => false])
        ->assertSessionHas('inertia.flash_data.toast.message', 'Sonarr is unreachable.');

    expect(ActionRequest::query()->where('type', 'monitor_episodes')->exists())->toBeFalse();
});

test('an unreachable Sonarr answers 502 for an episode release search', function (): void {
    Http::fake(['sonarr.local:8989/api/v3/episode*' => fn (): never => throw new ConnectionException('Connection refused.')]);

    $this->actingAs($this->member)
        ->getJson(route('media.library.actions.releases', ['service' => 'sonarr', 'service_connection_id' => $this->sonarr->id, 'item_id' => 7, 'episode_id' => 70]))
        ->assertStatus(502)
        ->assertJsonPath('message', 'Sonarr is unreachable.');
});

test('a member monitors a season through the action pipeline, pinned to the connection', function (): void {
    Http::fake(['sonarr.local:8989/*' => Http::response([])]);

    $this->actingAs($this->member)
        ->post(route('media.library.actions.monitor-season'), ['service_connection_id' => $this->sonarr->id, 'series_id' => 7, 'season_number' => 2])
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast.message', 'Monitoring updated.');

    $actionRequest = ActionRequest::query()->where('type', 'monitor_season')->sole();
    expect($actionRequest->origin)->toBe('manual')
        ->and($actionRequest->payload)->toEqual(['series_id' => 7, 'season_number' => 2, 'service_connection_id' => $this->sonarr->id])
        ->and($actionRequest->title)->toBe('Monitor season 2 of series "Severance (2022)"');
});

test('a monitor sent from the Seasonal Anime page names that page in the action reason', function (string $routeName, array $body, string $type): void {
    Http::fake(['sonarr.local:8989/*' => Http::response([])]);

    $this->actingAs($this->member)
        ->post(route($routeName), [...$body, 'service_connection_id' => $this->sonarr->id, 'origin' => 'seasonal_anime'])
        ->assertSessionHas('inertia.flash_data.toast.message', 'Monitoring updated.');

    expect(ActionRequest::query()->where('type', $type)->sole()->description)
        ->toStartWith('Requested from Seasonal Anime by Mia.');
})->with([
    'season' => ['media.library.actions.monitor-season', ['series_id' => 7, 'season_number' => 2], 'monitor_season'],
    'series' => ['media.library.actions.monitor', ['service' => 'sonarr', 'item_id' => 7, 'monitored' => true], 'monitor_series'],
]);

test('a monitor without an origin keeps the library wording in the action reason', function (): void {
    Http::fake(['sonarr.local:8989/*' => Http::response([])]);

    $this->actingAs($this->member)
        ->post(route('media.library.actions.monitor-season'), ['service_connection_id' => $this->sonarr->id, 'series_id' => 7, 'season_number' => 2])
        ->assertSessionHas('inertia.flash_data.toast.message', 'Monitoring updated.');

    expect(ActionRequest::query()->where('type', 'monitor_season')->sole()->description)
        ->toStartWith('Requested from the library by Mia.');
});

test('an unknown origin is rejected', function (): void {
    $this->actingAs($this->member)
        ->post(route('media.library.actions.monitor'), ['service' => 'sonarr', 'service_connection_id' => $this->sonarr->id, 'item_id' => 7, 'monitored' => true, 'origin' => 'somewhere_else'])
        ->assertSessionHasErrors('origin');

    expect(ActionRequest::query()->count())->toBe(0);
});

test('monitor-season validates its body', function (array $body, string $field): void {
    $this->actingAs($this->member)
        ->post(route('media.library.actions.monitor-season'), [...['service_connection_id' => $this->sonarr->id, 'series_id' => 7, 'season_number' => 1], ...$body])
        ->assertSessionHasErrors($field);
})->with([
    'missing season' => [['season_number' => null], 'season_number'],
    'negative season' => [['season_number' => -1], 'season_number'],
    'bad series' => [['series_id' => 0], 'series_id'],
]);

test('viewers cannot monitor a season', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('media.library.actions.monitor-season'), ['service_connection_id' => $this->sonarr->id, 'series_id' => 7, 'season_number' => 1])
        ->assertForbidden();
});

test('a radarr connection is refused for monitor-season', function (): void {
    $this->actingAs($this->member)
        ->post(route('media.library.actions.monitor-season'), ['service_connection_id' => $this->radarr->id, 'series_id' => 7, 'season_number' => 1])
        ->assertUnprocessable();

    expect(ActionRequest::query()->count())->toBe(0);
});

test('monitor-season is refused while a replacement is in flight', function (): void {
    ActionRequest::factory()->create([
        'type' => 'replace_media_file',
        'status' => ActionRequestStatus::Pending,
        'payload' => ['target' => ['service' => 'sonarr', 'service_connection_id' => $this->sonarr->id, 'series_id' => 7, 'season_number' => 1, 'episode_numbers' => [1]]],
    ]);

    $this->actingAs($this->member)
        ->post(route('media.library.actions.monitor-season'), ['service_connection_id' => $this->sonarr->id, 'series_id' => 7, 'season_number' => 1])
        ->assertSessionHas('inertia.flash_data.toast.message', 'A file replacement is in progress for this title — try again when it finishes.');

    expect(ActionRequest::query()->where('type', 'monitor_season')->exists())->toBeFalse();
});
