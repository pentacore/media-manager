<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
    Http::fake([
        'bazarr.test/api/system/health' => Http::response(['data' => []]),
        'bazarr.test/api/episodes/wanted*' => Http::response(['data' => [], 'total' => 0]),
        'bazarr.test/api/movies/wanted*' => Http::response(['data' => [], 'total' => 0]),
    ]);
});

dataset('subtitle center pages', [
    ['bazarr.overview', 'Bazarr/Overview'],
    ['bazarr.missing', 'Bazarr/Missing'],
    ['bazarr.library', 'Bazarr/Library'],
    ['bazarr.history', 'Bazarr/History'],
]);

test('guests are redirected from every subtitle center page', function (string $routeName): void {
    $this->get(route($routeName))->assertRedirect(route('login'));
})->with('subtitle center pages');

test('viewers can read every subtitle center page without secret props', function (
    string $routeName,
    string $component,
): void {
    $bazarr = ServiceConnection::factory()->bazarr()->create([
        'name' => 'Primary Bazarr',
        'url' => 'http://bazarr.test',
        'api_key' => 'bazarr-secret',
    ]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route($routeName, ['connection' => $bazarr->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $assertableInertia): AssertableInertia => $assertableInertia
            ->component($component)
            ->where('connections.0.id', $bazarr->id)
            ->where('connections.0.name', 'Primary Bazarr')
            ->where('selected_connection_id', $bazarr->id)
            ->where('requires_connection_selection', false)
        );

    $props = $response->viewData('page')['props'];
    $forbiddenKeys = collect(arrayKeysRecursively($props))
        ->filter(fn (string $key): bool => preg_match('/(?:password|token|api_?key|secret|path|provider_url)/i', $key) === 1);

    expect($forbiddenKeys)->toBeEmpty();
})->with('subtitle center pages');

test('multiple active Bazarr connections require an explicit selection', function (): void {
    $first = ServiceConnection::factory()->bazarr()->create(['name' => 'First Bazarr']);
    $second = ServiceConnection::factory()->bazarr()->create(['name' => 'Second Bazarr']);

    $this->actingAs(User::factory()->create())
        ->get(route('bazarr.overview'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $assertableInertia): AssertableInertia => $assertableInertia
            ->component('Bazarr/Overview')
            ->has('connections', 2)
            ->where('connections.0.id', $first->id)
            ->where('connections.1.id', $second->id)
            ->where('selected_connection_id', null)
            ->where('requires_connection_selection', true)
            ->where('overview', null)
        );
});

test('inactive and non Bazarr connections cannot be selected', function (): void {
    $inactive = ServiceConnection::factory()->bazarr()->create(['is_active' => false]);
    $sonarr = ServiceConnection::factory()->sonarr()->create();
    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->get(route('bazarr.overview', ['connection' => $inactive->id]))
        ->assertNotFound();

    $this->actingAs($viewer)
        ->get(route('bazarr.overview', ['connection' => $sonarr->id]))
        ->assertNotFound();
});

test('library and history inventory are deferred', function (
    string $routeName,
    string $component,
    string $prop,
): void {
    $bazarr = ServiceConnection::factory()->bazarr()->create([
        'url' => 'http://bazarr.test',
        'api_key' => 'bazarr-secret',
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route($routeName, ['connection' => $bazarr->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $assertableInertia): AssertableInertia => $assertableInertia
            ->component($component)
            ->missing($prop)
            ->loadDeferredProps('default', fn (AssertableInertia $assertableInertia): AssertableInertia => $assertableInertia
                ->has($prop.'.data', 0)
                ->where($prop.'.partial', true)
            )
        );
})->with([
    ['bazarr.library', 'Bazarr/Library', 'library'],
    ['bazarr.history', 'Bazarr/History', 'history'],
]);

test('read query parameters are validated', function (): void {
    $bazarr = ServiceConnection::factory()->bazarr()->create();
    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->get(route('bazarr.library', [
            'connection' => $bazarr->id,
            'page' => 0,
            'per_page' => 101,
            'media_type' => 'invalid',
        ]))
        ->assertSessionHasErrors(['page', 'per_page', 'media_type']);
});

/**
 * @param  array<string, mixed>  $values
 * @return list<string>
 */
function arrayKeysRecursively(array $values, string $prefix = ''): array
{
    $keys = [];

    foreach ($values as $key => $value) {
        $qualifiedKey = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        $keys[] = $qualifiedKey;

        if (is_array($value)) {
            $keys = [...$keys, ...arrayKeysRecursively($value, $qualifiedKey)];
        }
    }

    return $keys;
}
