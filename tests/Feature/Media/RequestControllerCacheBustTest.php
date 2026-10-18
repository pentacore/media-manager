<?php

declare(strict_types=1);

use App\Cache\Services\SeerrCache;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Seerr\SeerrWriteUnconfirmed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('mediamanager.cache.store', 'array');
    config()->set('mediamanager.cache.ttl.list', 60);
    config()->set('mediamanager.cache.ttl.entity', 300);
    config()->set('mediamanager.cache.ttl.metadata', 600);
    Cache::store('array')->flush();
    Http::preventStrayRequests();

    $this->connection = ServiceConnection::factory()->seerr()->create([
        'url' => 'http://seerr.local:5055',
        'api_key' => 'k',
    ]);
});

test('destroy busts the Seerr cache for its connection', function (): void {
    Http::fake(['seerr.local:5055/api/v1/request/42' => Http::response(null, 200)]);

    $cache = new SeerrCache($this->connection);
    $cache->rememberList('list', fn (): array => ['warm' => true]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->delete(route('media.requests.destroy', 42))
        ->assertRedirect(route('media.requests.index'));

    $hits = 0;
    $cache->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['fresh' => true];
    });

    expect($hits)->toBe(1);
});

test('approve busts the Seerr cache for its connection', function (): void {
    Http::fake(['seerr.local:5055/api/v1/request/42/approve' => Http::response([], 200)]);

    $cache = new SeerrCache($this->connection);
    $cache->rememberList('list', fn (): array => ['warm' => true]);

    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->from(route('media.requests.index'))
        ->post(route('media.requests.approve', 42))
        ->assertRedirect(route('media.requests.index'));

    $hits = 0;
    $cache->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['fresh' => true];
    });

    expect($hits)->toBe(1);
});

test('decline busts the Seerr cache for its connection', function (): void {
    Http::fake(['seerr.local:5055/api/v1/request/42/decline' => Http::response([], 200)]);

    $cache = new SeerrCache($this->connection);
    $cache->rememberList('list', fn (): array => ['warm' => true]);

    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->from(route('media.requests.index'))
        ->post(route('media.requests.decline', 42))
        ->assertRedirect(route('media.requests.index'));

    $hits = 0;
    $cache->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['fresh' => true];
    });

    expect($hits)->toBe(1);
});

test('retry busts the Seerr cache for its connection', function (): void {
    Http::fake(['seerr.local:5055/api/v1/request/42/retry' => Http::response([], 200)]);

    $cache = new SeerrCache($this->connection);
    $cache->rememberList('list', fn (): array => ['warm' => true]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->from(route('media.requests.index'))
        ->post(route('media.requests.retry', 42))
        ->assertRedirect(route('media.requests.index'));

    $hits = 0;
    $cache->rememberList('list', function () use (&$hits): array {
        $hits++;

        return ['fresh' => true];
    });

    expect($hits)->toBe(1);
});

test('a Seerr write behind a login page still busts the cache and says the outcome is unknown', function (string $method, string $routeName, string $path, string $role): void {
    Http::fake([sprintf('seerr.local:5055/api/v1/%s', $path) => Http::response('<html><body>Sign in</body></html>')]);
    $cache = new SeerrCache($this->connection);
    $cache->putEntity('probe', ['warm' => true]);

    $user = $role === 'admin' ? User::factory()->admin()->create() : User::factory()->member()->create();

    $this->actingAs($user)
        ->from(route('media.requests.index'))
        ->{$method}(route($routeName, 42))
        ->assertRedirect(route('media.requests.index'))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'error', 'message' => SeerrWriteUnconfirmed::MESSAGE]);

    expect($cache->getEntity('probe'))->toBeNull();
})->with([
    'delete' => ['delete', 'media.requests.destroy', 'request/42', 'admin'],
    'approve' => ['post', 'media.requests.approve', 'request/42/approve', 'member'],
    'decline' => ['post', 'media.requests.decline', 'request/42/decline', 'member'],
    'retry' => ['post', 'media.requests.retry', 'request/42/retry', 'admin'],
]);
