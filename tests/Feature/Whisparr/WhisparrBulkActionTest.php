<?php

declare(strict_types=1);

use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Models\User;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
    $this->seed(ActionTypeConfigSeeder::class);

    $this->whisparr = ServiceConnection::factory()->whisparr()->create(['url' => 'http://whisparr.local:6969', 'api_key' => 'k', 'name' => 'Whisparr']);
    $titles = [11 => 'Aurora Scene', 12 => 'Borealis Scene', 13 => 'Cirrus Scene'];

    Http::fake([
        'whisparr.local:6969/api/v3/movie/*' => function (Request $request) use ($titles) {
            $id = (int) Str::afterLast($request->url(), '/');

            return Http::response(['id' => $id, 'title' => $titles[$id] ?? 'Unknown', 'year' => 2024]);
        },
        'whisparr.local:6969/api/v3/movie' => Http::response(array_map(
            static fn (int $id, string $title): array => ['id' => $id, 'title' => $title],
            array_keys($titles),
            $titles,
        )),
        'whisparr.local:6969/api/v3/qualityprofile' => Http::response([['id' => 2, 'name' => 'HD']]),
    ]);

    $this->admin = User::factory()->admin()->create();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bulkWhisparrPayload(ServiceConnection $serviceConnection, array $overrides = []): array
{
    return ['service_connection_id' => $serviceConnection->id, 'ids' => [11, 12, 13], 'action' => 'monitor', ...$overrides];
}

test('an admin monitors many Whisparr titles, each pinned and through the Action Queue', function (): void {
    $this->actingAs($this->admin)
        ->postJson(route('media.whisparr.bulk'), bulkWhisparrPayload($this->whisparr))
        ->assertOk()
        ->assertJsonPath('started', 3)
        ->assertJsonPath('toast.message', '3 started');

    expect(ActionRequest::query()->where('type', 'whisparr_monitor_item')->orderBy('id')->pluck('payload')->all())->toEqual([
        ['whisparr_item_id' => 11, 'monitored' => true, 'service_connection_id' => $this->whisparr->id],
        ['whisparr_item_id' => 12, 'monitored' => true, 'service_connection_id' => $this->whisparr->id],
        ['whisparr_item_id' => 13, 'monitored' => true, 'service_connection_id' => $this->whisparr->id],
    ]);
});

test('a bulk delete queues each title and writes one whisparr.deleted audit row per title', function (): void {
    $this->actingAs($this->admin)
        ->postJson(route('media.whisparr.bulk'), bulkWhisparrPayload($this->whisparr, ['ids' => [11, 12], 'action' => 'delete', 'delete_files' => true]))
        ->assertJsonPath('queued', 2)
        ->assertJsonPath('toast.message', '2 queued for approval');

    expect(ActionRequest::query()->where('type', 'whisparr_delete_item')->count())->toBe(2)
        ->and(ActivityLog::query()->where('category', 'audit')->where('action', 'whisparr.deleted')->count())->toBe(2);
});

test('a bulk search uses whisparr_search for every title', function (): void {
    $this->actingAs($this->admin)
        ->postJson(route('media.whisparr.bulk'), bulkWhisparrPayload($this->whisparr, ['action' => 'search']))
        ->assertJsonPath('started', 3);

    expect(ActionRequest::query()->where('type', 'whisparr_search')->count())->toBe(3);
});

test('a refused item is named from the Whisparr library, not from the request', function (): void {
    ActionTypeConfig::query()->where('type', 'whisparr_search')->update(['is_enabled' => false]);

    $this->actingAs($this->admin)
        ->postJson(route('media.whisparr.bulk'), bulkWhisparrPayload($this->whisparr, ['ids' => [12], 'action' => 'search']))
        ->assertJsonPath('failed', [['id' => 12, 'title' => 'Borealis Scene', 'reason' => 'This action is disabled in Action Rules.']]);
});

test('a Sonarr connection id is refused and nothing is filed', function (): void {
    $sonarr = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);

    $this->actingAs($this->admin)
        ->postJson(route('media.whisparr.bulk'), bulkWhisparrPayload($sonarr))
        ->assertUnprocessable();

    expect(ActionRequest::query()->count())->toBe(0);
});

test('members cannot run Whisparr bulk actions', function (): void {
    $this->actingAs(User::factory()->member()->create())
        ->postJson(route('media.whisparr.bulk'), bulkWhisparrPayload($this->whisparr))
        ->assertForbidden();

    expect(ActionRequest::query()->count())->toBe(0);
});
