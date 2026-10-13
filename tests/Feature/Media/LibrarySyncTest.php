<?php

declare(strict_types=1);

use App\Cache\Services\RadarrCache;
use App\Cache\Services\SonarrCache;
use App\Cache\Services\WhisparrCache;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    config()->set('mediamanager.cache.store', 'array');
    config()->set('mediamanager.cache.ttl.list', 60);
    config()->set('mediamanager.cache.ttl.entity', 300);
    config()->set('mediamanager.cache.ttl.metadata', 600);
    Cache::store('array')->flush();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function librarySyncConnection(ServiceType $serviceType, array $attributes = []): ServiceConnection
{
    $factory = match ($serviceType) {
        ServiceType::Sonarr => ServiceConnection::factory()->sonarr(),
        ServiceType::Radarr => ServiceConnection::factory()->radarr(),
        ServiceType::Whisparr => ServiceConnection::factory()->whisparr(),
    };

    return $factory->create($attributes);
}

function librarySyncCache(ServiceConnection $serviceConnection): SonarrCache|RadarrCache|WhisparrCache
{
    return match ($serviceConnection->type) {
        ServiceType::Sonarr => new SonarrCache($serviceConnection),
        ServiceType::Radarr => new RadarrCache($serviceConnection),
        ServiceType::Whisparr => new WhisparrCache($serviceConnection),
    };
}

/**
 * Warms the slot the assertions read: it holds "cached" until a sync empties it.
 */
function librarySyncWarm(ServiceConnection $serviceConnection): void
{
    librarySyncCache($serviceConnection)->rememberList('library', fn (): string => 'cached');
}

/**
 * "cached" while the slot is warm, "fresh" once a sync emptied it.
 */
function librarySyncSlot(ServiceConnection $serviceConnection): string
{
    return librarySyncCache($serviceConnection)->rememberList('library', fn (): string => 'fresh');
}

test("a Sync empties that connection's cache, and only that one", function (ServiceType $serviceType, string $indexRoute, string $syncRoute): void {
    $serviceConnection = librarySyncConnection($serviceType);
    $inactive = librarySyncConnection($serviceType, ['is_active' => false]);
    librarySyncWarm($serviceConnection);
    librarySyncWarm($inactive);

    $this->actingAs(User::factory()->create())
        ->from(route($indexRoute))
        ->post(route($syncRoute))
        ->assertRedirect(route($indexRoute))
        ->assertSessionHas('inertia.flash_data.toast.type', 'success')
        ->assertSessionHas('inertia.flash_data.toast.message', sprintf('Synced with %s.', $serviceType->label()));

    expect(librarySyncSlot($serviceConnection))->toBe('fresh')
        ->and(librarySyncSlot($inactive))->toBe('cached');
})->with([
    'Sonarr, as a viewer' => [ServiceType::Sonarr, 'media.series.index', 'media.series.sync'],
    'Radarr, as a viewer' => [ServiceType::Radarr, 'media.movies.index', 'media.movies.sync'],
]);

test('a second Sync within 30 s leaves the cache alone and says when to retry, and the next one after it busts again', function (): void {
    $serviceConnection = librarySyncConnection(ServiceType::Sonarr);
    $viewer = User::factory()->create();

    $this->actingAs($viewer)->from(route('media.series.index'))->post(route('media.series.sync'));
    librarySyncWarm($serviceConnection);

    $this->travel(10)->seconds();

    $this->actingAs($viewer)
        ->from(route('media.series.index'))
        ->post(route('media.series.sync'))
        ->assertRedirect(route('media.series.index'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'info')
        ->assertSessionHas('inertia.flash_data.toast.message', 'Sonarr was synced moments ago — try again in 20 s.');

    expect(librarySyncSlot($serviceConnection))->toBe('cached');

    $this->travel(21)->seconds();

    $this->actingAs($viewer)
        ->from(route('media.series.index'))
        ->post(route('media.series.sync'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'success');

    expect(librarySyncSlot($serviceConnection))->toBe('fresh');
});

test('the throttle is per connection, shared by every user', function (): void {
    $serviceConnection = librarySyncConnection(ServiceType::Sonarr);

    $this->actingAs(User::factory()->create())->from(route('media.series.index'))->post(route('media.series.sync'));
    librarySyncWarm($serviceConnection);
    $this->travel(5)->seconds();

    $this->actingAs(User::factory()->member()->create())
        ->from(route('media.series.index'))
        ->post(route('media.series.sync'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'info');

    expect(librarySyncSlot($serviceConnection))->toBe('cached');
});

test('Sonarr and Radarr throttle separately', function (): void {
    $serviceConnection = librarySyncConnection(ServiceType::Sonarr);
    $radarr = librarySyncConnection(ServiceType::Radarr);
    $viewer = User::factory()->create();

    $this->actingAs($viewer)->from(route('media.series.index'))->post(route('media.series.sync'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'success');
    librarySyncWarm($serviceConnection);
    librarySyncWarm($radarr);

    $this->actingAs($viewer)->from(route('media.movies.index'))->post(route('media.movies.sync'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'success');

    expect(librarySyncSlot($radarr))->toBe('fresh')
        ->and(librarySyncSlot($serviceConnection))->toBe('cached');
});

test('only admins can sync Whisparr, and a refused sync leaves the cache alone', function (): void {
    $serviceConnection = librarySyncConnection(ServiceType::Whisparr);
    librarySyncWarm($serviceConnection);

    $this->actingAs(User::factory()->member()->create())
        ->post(route('media.whisparr.sync'))
        ->assertForbidden();

    expect(librarySyncSlot($serviceConnection))->toBe('cached');

    $this->actingAs(User::factory()->admin()->create())
        ->from(route('media.whisparr.index'))
        ->post(route('media.whisparr.sync'))
        ->assertRedirect(route('media.whisparr.index'))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Synced with Whisparr.');

    expect(librarySyncSlot($serviceConnection))->toBe('fresh');
});

test('guests cannot sync', function (string $syncRoute): void {
    $serviceConnection = librarySyncConnection(ServiceType::Sonarr);
    librarySyncWarm($serviceConnection);

    $this->post(route($syncRoute))->assertRedirect(route('login'));

    expect(librarySyncSlot($serviceConnection))->toBe('cached');
})->with(['media.series.sync', 'media.movies.sync', 'media.whisparr.sync']);

test('a Sync without an active connection says so and stays on the page', function (): void {
    $this->actingAs(User::factory()->create())
        ->from(route('dashboard'))
        ->post(route('media.series.sync'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error')
        ->assertSessionHas('inertia.flash_data.toast.message', 'No active Sonarr connection configured.');
});
