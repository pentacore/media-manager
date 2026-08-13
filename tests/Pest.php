<?php

declare(strict_types=1);

use App\Jobs\Ai\GenerateConversationTitle;
use App\Jobs\AuditImportedSubtitles;
use App\Jobs\EmbedLibraryItem;
use App\Jobs\ExecuteActionRequest;
use App\Jobs\FetchLatestServiceVersion;
use App\Jobs\PingServiceHealth;
use App\Services\Library\InterventionCounter;
use App\Services\Sabnzbd\SabnzbdDownloadCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

pest()->browser()->timeout(30_000);

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // The ServiceConnection observer dispatches PingServiceHealth and
        // FetchLatestServiceVersion on create / identity-update. In tests
        // (sync queue) those jobs would run real HTTP inside factory create,
        // which conflicts with Http::preventStrayRequests() in many suites.
        // Fake only these jobs by default — other jobs (webhook handlers,
        // etc.) keep their normal sync dispatch behaviour.
        // GenerateConversationTitle is included because any first-turn chat
        // test would otherwise run it synchronously and prompt the real,
        // unfaked TitleAgent (a live provider HTTP call). Tests that assert
        // on the job re-fake via Bus::fake; the job's own tests call
        // handle() directly, so neither is affected.
        // EmbedLibraryItem is faked for the same reason: the MovieIndexer /
        // SeriesIndexer dispatch it on create, and its handle() calls the
        // Embeddings SDK (unfaked, dummy keys → 401) synchronously otherwise.
        // ExecuteActionRequest is faked because webhook handlers dispatch
        // auto-executing ActionRequests (e.g. emby_library_scan on a Download)
        // that would otherwise run inline under the sync queue and make real
        // HTTP to the (Faker-random) ServiceConnection URLs — nondeterministic
        // outbound requests that flake under --parallel. This matches production
        // (async queue); action execution is covered by the direct executor
        // tests (ExecuteActionRequestTest, *ActionsTest) and by suites that
        // re-fake the queue and assert push behaviour.
        // AuditImportedSubtitles is faked for the same reason: the arr Download
        // handlers queue it, the sync queue ignores its delay, and on a
        // connection with subtitle-check tags it would inspect the arr and sweep
        // every indexer. Its own test calls handle() directly and the handler
        // tests re-fake the queue, so neither is affected.
        Queue::fake([PingServiceHealth::class, FetchLatestServiceVersion::class, GenerateConversationTitle::class, EmbedLibraryItem::class, ExecuteActionRequest::class, AuditImportedSubtitles::class]);

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
        Queue::fake([PingServiceHealth::class, FetchLatestServiceVersion::class, GenerateConversationTitle::class, EmbedLibraryItem::class]);

        config()->set('mediamanager.cache.store', 'array');
        Cache::store('array')->flush();

        // The sidebar badge counters (HandleInertiaRequests) recompute inline
        // on a cold cache, walking every *arr / SAB connection the test
        // created — real HTTP to unreachable factory hosts (.local DNS stalls
        // ~13s) that burns the 30s assertion budget and flakes whichever
        // connection test runs into it. Seed both caches so page renders
        // never recompute; the badge logic itself is covered by feature tests
        // (InterventionCounterTest, HandleInertiaRequestsTest).
        Cache::put(InterventionCounter::CACHE_KEY, 0, 600);
        Cache::put(SabnzbdDownloadCounter::CACHE_KEY, ['queued' => 0, 'completed' => 0], 600);
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

/**
 * Fake outbound service HTTP without intercepting Inertia's SSR gateway.
 *
 * Inertia renders through an HTTP call to the local SSR bundle server, so a
 * wildcard or closure `Http::fake()` swallows it too: the gateway then receives
 * the stubbed service payload instead of `{head, body}` and every page renders
 * blank — which reads as a hydration failure rather than a faked request. Host
 * pattern fakes are unaffected because unmatched requests still execute, so
 * only closure and wildcard fakes need this. Returning null from the closure
 * lets the request through.
 *
 * @param  Closure(Request, array<string, mixed>): mixed  $handler
 */
function fakeServiceHttp(Closure $handler): void
{
    Http::fake(function (Request $request, array $options) use ($handler): mixed {
        $host = parse_url($request->url(), PHP_URL_HOST);

        if (in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return null;
        }

        return $handler($request, $options);
    });
}
