<?php

declare(strict_types=1);

use App\Jobs\FetchLatestServiceVersion;
use App\Jobs\PingServiceHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Pest\Browser\Playwright\Playwright;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // The ServiceConnection observer dispatches PingServiceHealth and
        // FetchLatestServiceVersion on create / identity-update. In tests
        // (sync queue) those jobs would run real HTTP inside factory create,
        // which conflicts with Http::preventStrayRequests() in many suites.
        // Fake only those two jobs by default — other jobs (webhook handlers,
        // etc.) keep their normal sync dispatch behaviour.
        Queue::fake([PingServiceHealth::class, FetchLatestServiceVersion::class]);

        // External-API caches (app/Cache/Services) default to redis in production.
        // Force the array store in tests so per-test state is isolated and tagged
        // flushes don't leak across runs.
        config()->set('mediamanager.cache.store', 'array');
        Cache::store('array')->flush();
    })
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        Queue::fake([PingServiceHealth::class, FetchLatestServiceVersion::class]);

        config()->set('mediamanager.cache.store', 'array');
        Cache::store('array')->flush();

        // Inertia hydration on slow CI / parallel workers can outrun the
        // default 5s assertion wait. Bump to 15s for browser tests only.
        Playwright::setTimeout(15_000);
    })
    ->in('Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something(): void
{
    // ..
}
