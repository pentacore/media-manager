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
use App\Services\Actions\ManualActionOutcome;
use App\Services\Library\LibraryActionRequester;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->seed(ActionTypeConfigSeeder::class);

    $this->sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k', 'name' => 'Sonarr']);
    $this->radarr = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k', 'name' => 'Radarr']);
    $this->whisparr = ServiceConnection::factory()->whisparr()->create(['url' => 'http://whisparr.local:6969', 'api_key' => 'k', 'name' => 'Whisparr']);
    IndexedSeries::factory()->for($this->sonarr, 'serviceConnection')->create(['sonarr_id' => 7, 'title' => 'Severance', 'year' => 2022]);
    IndexedMovie::factory()->for($this->radarr, 'serviceConnection')->create(['radarr_id' => 10, 'title' => 'Dune', 'year' => 2021]);

    Http::fake([
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([['id' => 4, 'name' => 'HD']]),
        'radarr.local:7878/api/v3/qualityprofile' => Http::response([['id' => 4, 'name' => 'HD']]),
        'whisparr.local:6969/api/v3/qualityprofile' => Http::response([['id' => 4, 'name' => 'HD']]),
        'whisparr.local:6969/api/v3/movie/11' => Http::response(['id' => 11, 'title' => 'Aurora Scene', 'year' => 2024]),
    ]);

    // AuditLogger records the authenticated user as the actor.
    $this->actingAs(User::factory()->admin()->create());
});

test('each library action files its type with a pinned payload', function (string $service, string $method, array $arguments, string $type, array $payload): void {
    $connection = $this->{$service};

    $outcome = resolve(LibraryActionRequester::class)->{$method}($connection, ...[...$arguments, 'Requested by a test.']);

    expect($outcome->state)->toBe(ManualActionOutcome::STARTED);

    $actionRequest = ActionRequest::query()->where('type', $type)->sole();

    expect($actionRequest->origin)->toBe('manual')
        ->and($actionRequest->payload)->toEqual([...$payload, 'service_connection_id' => $connection->id]);
})->with([
    'sonarr monitor' => ['sonarr', 'monitor', [7, false], 'monitor_series', ['series_id' => 7, 'monitored' => false]],
    'radarr monitor' => ['radarr', 'monitor', [10, true], 'monitor_movie', ['movie_id' => 10, 'monitored' => true]],
    'whisparr monitor' => ['whisparr', 'monitor', [11, true], 'whisparr_monitor_item', ['whisparr_item_id' => 11, 'monitored' => true]],
    'sonarr profile' => ['sonarr', 'setQualityProfile', [7, 4], 'set_series_quality_profile', ['series_id' => 7, 'quality_profile_id' => 4]],
    'radarr profile' => ['radarr', 'setQualityProfile', [10, 4], 'set_movie_quality_profile', ['movie_id' => 10, 'quality_profile_id' => 4]],
    'whisparr profile' => ['whisparr', 'setQualityProfile', [11, 4], 'whisparr_set_quality_profile', ['whisparr_item_id' => 11, 'quality_profile_id' => 4]],
    'sonarr search' => ['sonarr', 'search', [7], 'search_media', ['service' => 'sonarr', 'command' => 'series_search', 'series_id' => 7]],
    'radarr search' => ['radarr', 'search', [10], 'search_media', ['service' => 'radarr', 'command' => 'movies_search', 'movie_ids' => [10]]],
    'whisparr search' => ['whisparr', 'search', [11], 'whisparr_search', ['whisparr_item_id' => 11]],
]);

// R1 (preflight-rulings.md): person delete audits keep 7a's exact shape —
// series.delete_requested / movie.delete_requested, not series.deleted /
// movie.deleted. Whisparr uses the spec's whisparr.deleted.
test('a delete files the pinned delete type and exactly one audit row', function (string $service, int $itemId, string $type, string $idKey, string $auditAction): void {
    $connection = $this->{$service};

    $outcome = resolve(LibraryActionRequester::class)->delete($connection, $itemId, true, 'Requested by a test.');

    expect($outcome->state)->toBe(ManualActionOutcome::QUEUED);

    $actionRequest = ActionRequest::query()->where('type', $type)->sole();
    expect($actionRequest->payload)->toEqual([$idKey => $itemId, 'delete_files' => true, 'service_connection_id' => $connection->id])
        ->and($actionRequest->status)->toBe(ActionRequestStatus::Pending);

    $audit = ActivityLog::query()->where('category', 'audit')->where('action', $auditAction)->sole();
    expect($audit->subject_type)->toBe(ActionRequest::class)
        ->and($audit->subject_id)->toBe($actionRequest->id);
})->with([
    'series' => ['sonarr', 7, 'delete_series', 'sonarr_series_id', 'series.delete_requested'],
    'movie' => ['radarr', 10, 'delete_movie', 'radarr_movie_id', 'movie.delete_requested'],
    'whisparr item' => ['whisparr', 11, 'whisparr_delete_item', 'whisparr_item_id', 'whisparr.deleted'],
]);

test('a delete that Action Rules disabled files nothing and audits nothing', function (): void {
    ActionTypeConfig::query()->where('type', 'whisparr_delete_item')->update(['is_enabled' => false]);

    $outcome = resolve(LibraryActionRequester::class)->delete($this->whisparr, 11, false, 'Requested by a test.');

    expect($outcome->state)->toBe(ManualActionOutcome::DISABLED)
        ->and(ActionRequest::query()->count())->toBe(0)
        ->and(ActivityLog::query()->where('category', 'audit')->count())->toBe(0);
});

test('monitoring is blocked while a replacement is in flight for the title', function (): void {
    ActionRequest::factory()->create([
        'type' => 'replace_media_file',
        'status' => ActionRequestStatus::Pending,
        'payload' => ['target' => ['service' => 'sonarr', 'service_connection_id' => $this->sonarr->id, 'series_id' => 7, 'season_number' => 1, 'episode_numbers' => [1]]],
    ]);

    $outcome = resolve(LibraryActionRequester::class)->monitor($this->sonarr, 7, false, 'Requested by a test.');

    expect($outcome->state)->toBe(ManualActionOutcome::BLOCKED)
        ->and($outcome->dispatched())->toBeFalse()
        ->and($outcome->toast('Monitoring updated.'))->toBe(['type' => 'error', 'message' => 'A file replacement is in progress for this title — try again when it finishes.'])
        ->and(ActionRequest::query()->where('type', 'monitor_series')->exists())->toBeFalse();
});

test('a connection without library actions is refused', function (): void {
    $sabnzbd = ServiceConnection::factory()->sabnzbd()->create(['url' => 'http://sabnzbd.local:8080']);

    expect(fn () => resolve(LibraryActionRequester::class)->monitor($sabnzbd, 1, true, 'Requested by a test.'))
        ->toThrow(InvalidArgumentException::class);
});
