<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\ActivityLog;
use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use App\Models\User;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
    $this->seed(ActionTypeConfigSeeder::class);

    $this->sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k', 'name' => 'Sonarr']);
    $this->radarr = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k', 'name' => 'Radarr']);

    foreach ([7 => 'Severance', 8 => 'Andor', 9 => 'Silo'] as $sonarrId => $title) {
        IndexedSeries::factory()->for($this->sonarr, 'serviceConnection')->create(['sonarr_id' => $sonarrId, 'title' => $title, 'year' => 2022]);
    }

    IndexedMovie::factory()->for($this->radarr, 'serviceConnection')->create(['radarr_id' => 10, 'title' => 'Dune', 'year' => 2021]);

    Http::fake([
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([['id' => 6, 'name' => 'Ultra-HD']]),
        'radarr.local:7878/api/v3/qualityprofile' => Http::response([['id' => 6, 'name' => 'Ultra-HD']]),
    ]);

    $this->member = User::factory()->member()->create(['name' => 'Mia']);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bulkLibraryPayload(ServiceConnection $serviceConnection, array $overrides = []): array
{
    return [
        'service' => $serviceConnection->type->value,
        'service_connection_id' => $serviceConnection->id,
        'ids' => [7, 8, 9],
        'action' => 'monitor',
        ...$overrides,
    ];
}

test('a member monitors many series: one pinned Action Queue request per title', function (): void {
    $this->actingAs($this->member)
        ->postJson(route('media.library.actions.bulk'), bulkLibraryPayload($this->sonarr))
        ->assertOk()
        ->assertJsonPath('started', 3)
        ->assertJsonPath('queued', 0)
        ->assertJsonPath('skipped', 0)
        ->assertJsonPath('failed', [])
        ->assertJsonPath('toast.type', 'success')
        ->assertJsonPath('toast.message', '3 started');

    $actionRequests = ActionRequest::query()->where('type', 'monitor_series')->orderBy('id')->get();

    expect($actionRequests->pluck('payload')->all())->toEqual([
        ['series_id' => 7, 'monitored' => true, 'service_connection_id' => $this->sonarr->id],
        ['series_id' => 8, 'monitored' => true, 'service_connection_id' => $this->sonarr->id],
        ['series_id' => 9, 'monitored' => true, 'service_connection_id' => $this->sonarr->id],
    ])->and($actionRequests->pluck('origin')->unique()->values()->all())->toBe(['manual']);
});

test('a bulk item is the same request the single action files', function (): void {
    $this->actingAs($this->member)
        ->post(route('media.library.actions.monitor'), ['service' => 'sonarr', 'service_connection_id' => $this->sonarr->id, 'item_id' => 7, 'monitored' => false]);
    $this->actingAs($this->member)
        ->postJson(route('media.library.actions.bulk'), bulkLibraryPayload($this->sonarr, ['ids' => [7], 'action' => 'unmonitor']))
        ->assertOk();

    [$single, $bulk] = ActionRequest::query()->where('type', 'monitor_series')->orderBy('id')->get()->all();

    expect($bulk->payload)->toEqual($single->payload)
        ->and($bulk->title)->toBe($single->title)
        ->and($bulk->status)->toBe($single->status)
        ->and($bulk->requires_approval)->toBe($single->requires_approval)
        ->and($bulk->source_service)->toBe($single->source_service);
});

test('approval rules apply per title and queued requests are worded as such', function (): void {
    ActionTypeConfig::query()->where('type', 'monitor_series')->update(['requires_approval' => true]);

    $this->actingAs($this->member)
        ->postJson(route('media.library.actions.bulk'), bulkLibraryPayload($this->sonarr))
        ->assertJsonPath('queued', 3)
        ->assertJsonPath('toast.type', 'info')
        ->assertJsonPath('toast.message', '3 queued for approval');

    expect(ActionRequest::query()->where('status', ActionRequestStatus::Pending)->count())->toBe(3);
});

test('a title with a replacement in flight fails with the single action reason while the rest proceed', function (): void {
    ActionRequest::factory()->create([
        'type' => 'replace_media_file',
        'status' => ActionRequestStatus::Pending,
        'payload' => ['target' => ['service' => 'sonarr', 'service_connection_id' => $this->sonarr->id, 'series_id' => 8, 'season_number' => 1, 'episode_numbers' => [1]]],
    ]);

    $this->actingAs($this->member)
        ->postJson(route('media.library.actions.bulk'), bulkLibraryPayload($this->sonarr))
        ->assertJsonPath('started', 2)
        ->assertJsonPath('failed', [[
            'id' => 8,
            'title' => 'Andor (2022)',
            'reason' => 'A file replacement is in progress for this title — try again when it finishes.',
        ]]);
});

test('a bulk delete files one delete request and one audit row per title', function (): void {
    $this->actingAs($this->member)
        ->postJson(route('media.library.actions.bulk'), bulkLibraryPayload($this->sonarr, ['action' => 'delete', 'delete_files' => true]))
        ->assertJsonPath('queued', 3);

    expect(ActionRequest::query()->where('type', 'delete_series')->orderBy('id')->pluck('payload')->all())->toEqual([
        ['sonarr_series_id' => 7, 'delete_files' => true, 'service_connection_id' => $this->sonarr->id],
        ['sonarr_series_id' => 8, 'delete_files' => true, 'service_connection_id' => $this->sonarr->id],
        ['sonarr_series_id' => 9, 'delete_files' => true, 'service_connection_id' => $this->sonarr->id],
    ])->and(ActivityLog::query()->where('category', 'audit')->where('action', 'series.delete_requested')->count())->toBe(3);
});

test('a bulk quality profile change needs a profile and applies the same one to every title', function (): void {
    $response = $this->actingAs($this->member)
        ->postJson(route('media.library.actions.bulk'), bulkLibraryPayload($this->sonarr, ['action' => 'quality_profile']))
        ->assertUnprocessable();

    expect($response->json('errors'))->toHaveKey('quality_profile_id');

    $this->actingAs($this->member)
        ->postJson(route('media.library.actions.bulk'), bulkLibraryPayload($this->sonarr, ['action' => 'quality_profile', 'quality_profile_id' => 6]))
        ->assertJsonPath('started', 3);

    expect(ActionRequest::query()->where('type', 'set_series_quality_profile')->get()->pluck('payload.quality_profile_id')->unique()->values()->all())->toBe([6]);
});

test('a bulk search sends one search per movie', function (): void {
    $this->actingAs($this->member)
        ->postJson(route('media.library.actions.bulk'), bulkLibraryPayload($this->radarr, ['ids' => [10], 'action' => 'search']))
        ->assertJsonPath('started', 1);

    expect(ActionRequest::query()->where('type', 'search_media')->sole()->payload)
        ->toEqual(['service' => 'radarr', 'command' => 'movies_search', 'movie_ids' => [10], 'service_connection_id' => $this->radarr->id]);
});

test('more than 100 ids are refused and nothing is dispatched', function (): void {
    $response = $this->actingAs($this->member)
        ->postJson(route('media.library.actions.bulk'), bulkLibraryPayload($this->sonarr, ['ids' => range(1, 101)]))
        ->assertUnprocessable();

    expect($response->json('errors'))->toHaveKey('ids')
        ->and(ActionRequest::query()->count())->toBe(0);
});

test('duplicate ids are refused so one title never gets two requests', function (): void {
    $response = $this->actingAs($this->member)
        ->postJson(route('media.library.actions.bulk'), bulkLibraryPayload($this->sonarr, ['ids' => [7, 7, 8], 'action' => 'delete']))
        ->assertUnprocessable();

    expect($response->json('errors'))->toHaveKey('ids.1')
        ->and(ActionRequest::query()->count())->toBe(0)
        ->and(ActivityLog::query()->where('category', 'audit')->count())->toBe(0);
});

test('an empty id list is refused', function (): void {
    $this->actingAs($this->member)
        ->postJson(route('media.library.actions.bulk'), bulkLibraryPayload($this->sonarr, ['ids' => []]))
        ->assertUnprocessable();
});

test('a pin to a connection of the other service is refused before anything is dispatched', function (): void {
    $this->actingAs($this->member)
        ->postJson(route('media.library.actions.bulk'), bulkLibraryPayload($this->sonarr, ['service_connection_id' => $this->radarr->id]))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The selected connection is unavailable or does not match the service.');

    expect(ActionRequest::query()->count())->toBe(0);
});

test('a deactivated pinned connection is refused', function (): void {
    $this->sonarr->update(['is_active' => false]);

    $this->actingAs($this->member)
        ->postJson(route('media.library.actions.bulk'), bulkLibraryPayload($this->sonarr))
        ->assertUnprocessable();

    expect(ActionRequest::query()->count())->toBe(0);
});

test('viewers cannot run bulk library actions', function (): void {
    $this->actingAs(User::factory()->create())
        ->postJson(route('media.library.actions.bulk'), bulkLibraryPayload($this->sonarr))
        ->assertForbidden();
});

test('the library index pages pin their connection for members only', function (): void {
    $this->actingAs($this->member)->get(route('media.series.index'))
        ->assertInertia(fn ($page) => $page->where('service_connection_id', $this->sonarr->id));
    $this->actingAs($this->member)->get(route('media.movies.index'))
        ->assertInertia(fn ($page) => $page->where('service_connection_id', $this->radarr->id));
    $this->actingAs(User::factory()->create())->get(route('media.series.index'))
        ->assertInertia(fn ($page) => $page->where('service_connection_id', null));
});
