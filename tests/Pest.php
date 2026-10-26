<?php

declare(strict_types=1);

use App\Jobs\Ai\GenerateConversationTitle;
use App\Jobs\AuditImportedSubtitles;
use App\Jobs\EmbedLibraryItem;
use App\Jobs\ExecuteActionRequest;
use App\Jobs\FetchLatestServiceVersion;
use App\Jobs\PingServiceHealth;
use App\Services\Library\InterventionCounter;
use App\Services\Library\WantedCounter;
use App\Services\Sabnzbd\SabnzbdDownloadCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\OrphanedPlaywrightServers;
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

// A browser run that is killed or whose tests time out leaves its Playwright
// server running under PID 1; stop those before this run adds its own.
// REAP_ORPHANED_PLAYWRIGHT=0 leaves them alone.
if (getenv('REAP_ORPHANED_PLAYWRIGHT') !== '0') {
    OrphanedPlaywrightServers::reap();
}

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

        // Like the intervention counter: page renders must never walk the
        // *arr wanted endpoints (browser.md). WantedCounterTest forgets the key.
        Cache::put(WantedCounter::CACHE_KEY, 0, 600);

        // Feature tests assert Inertia props on controllers whose Vue page
        // may not be in the committed Vite manifest yet (added in a later
        // task). withoutVite() skips the manifest lookup while the Blade
        // view still renders, so assertViewHas('page') / assertInertia()
        // keep working regardless of build state.
        $this->withoutVite();
    })
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        Queue::fake([PingServiceHealth::class, FetchLatestServiceVersion::class, GenerateConversationTitle::class, EmbedLibraryItem::class]);

        // The browser plugin serves every page request from this test's own
        // container, so scoped instances would outlive the request that made
        // them: Inertia's scoped SsrState then replays the first SSR response
        // on every later full page load, and memos like TaskModelResolver go
        // stale. Octane forgets them after the response is sent; the plugin
        // calls terminate() before streaming a body, so forget when the request
        // instance is rebound instead. That happens when the next request is
        // captured and again when the kernel dispatches to the router, so
        // scoped state is also dropped mid-request, before routing; a streamed
        // response's scoped state still survives until then.
        $this->app->rebinding('request', fn () => $this->app->forgetScopedInstances());

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

        // Like the intervention counter: page renders must never walk the
        // *arr wanted endpoints (browser.md). WantedCounterTest forgets the key.
        Cache::put(WantedCounter::CACHE_KEY, 0, 600);
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

/**
 * Polls inside the page until the JavaScript condition holds (or ~5 s pass),
 * so script() returns only once an async UI change has landed.
 */
function confirmDialogWaitUntilScript(string $condition): string
{
    return <<<JS
        (async () => {
            for (let attempt = 0; attempt < 250; attempt++) {
                if ({$condition}) {
                    return;
                }
                await new Promise((resolve) => setTimeout(resolve, 20));
            }
        })()
    JS;
}

/**
 * Waits until the Vue app has mounted onto #app (Vue sets `__vue_app__` after
 * hydrate() has flushed every onMounted hook), so Echo subscriptions, hover
 * prefetch and click handlers exist. Throws after ~5 s. SSR markup satisfies
 * DOM assertions before hydration, so they are not a substitute.
 */
function appHydratedScript(): string
{
    return <<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 250; attempt++) {
                if (document.querySelector('#app')?.__vue_app__) {
                    return;
                }
                await new Promise((resolve) => setTimeout(resolve, 20));
            }
            throw new Error('The app never hydrated #app.');
        })()
    JS;
}

/**
 * Hands a broadcast to the page's Echo listeners. Tests broadcast through the
 * null driver, so this emits on the pusher-js channel Echo created for
 * `private-{$channel}`, after waiting (up to ~5 s) until at least
 * `$minimumListeners` callbacks are bound for the event. It throws when they
 * never are, so an emit can neither race the page's subscriptions nor pass
 * vacuously.
 *
 * @param  array<string, mixed>  $payload
 */
function echoEmitScript(string $channel, string $event, array $payload, int $minimumListeners = 1): string
{
    $json = json_encode($payload, JSON_THROW_ON_ERROR);

    return <<<JS
        (async () => {
            const channel = () => window.Pusher?.instances?.[0]?.channel('private-{$channel}');
            const bound = () => (channel()?.callbacks?.get('{$event}') ?? []).length;
            for (let attempt = 0; attempt < 250 && bound() < {$minimumListeners}; attempt++) {
                await new Promise((resolve) => setTimeout(resolve, 20));
            }
            if (bound() < {$minimumListeners}) {
                throw new Error('private-{$channel} has ' + bound() + ' listener(s) for {$event} after 5 s.');
            }
            channel().emit('{$event}', {$json});
        })()
    JS;
}

/**
 * Waits until the shared confirm dialog has left the DOM; its exit animation
 * keeps it mounted briefly after it closes.
 */
function confirmDialogGoneScript(): string
{
    return confirmDialogWaitUntilScript("!document.querySelector('[data-confirm-dialog]')");
}

/**
 * Phone-width containment check (Batch 3h). Returns '' when no visible element
 * inside the roots matching $rootSelector ends right of the viewport or starts
 * left of it — except inside a horizontal scroll container below the root that
 * itself fits, or ellipsis-truncated text inside a fitting clip box — and every
 * matching `[role="dialog"]` root has no sideways scroll of its own (its
 * `overflow-y-auto` computes `overflow-x: auto`, so a wide child would scroll
 * the whole dialog instead of overflowing it). A reka-ui `VisuallyHidden`
 * element (the `name`d `Select`'s bubbled native `<select>`), matched by
 * reka's inline style (`position: absolute`, 1×1 px, `overflow: hidden`,
 * `clip-path: inset(50%)`), is excused like `.sr-only` while it does not
 * reach past the right edge — it paints nothing and sits at -1px left of its
 * containing block; one past the right edge would still widen the page's
 * sideways scroll. Otherwise returns up to ten offenders as
 * `tag[data-…] "text" left..right`: outermost only — an element is left out
 * when an ancestor was itself reported, not merely when its parent does not
 * fit, so the children of a zero-height wrapper (skipped as not shown) that
 * end past the edge are still reported.
 * Use with assertScript(phoneOverflowScript(), '').
 */
function phoneOverflowScript(string $rootSelector = 'main, [role="dialog"]'): string
{
    $selector = json_encode($rootSelector, JSON_THROW_ON_ERROR);

    return <<<JS
        (() => {
            const width = window.innerWidth;
            const overflowX = (el) => getComputedStyle(el).overflowX;
            const fits = (el) => {
                const rect = el.getBoundingClientRect();
                return rect.left >= -1 && rect.right <= width + 1;
            };
            const shown = (el) => {
                const rect = el.getBoundingClientRect();
                const style = getComputedStyle(el);
                return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
            };
            const visuallyHidden = (el) => el.style.position === 'absolute' && el.style.width === '1px' && el.style.height === '1px' && el.style.overflow === 'hidden' && el.style.clipPath === 'inset(50%)' && el.getBoundingClientRect().right <= width + 1;
            const excused = (el, root) => {
                for (let parent = el.parentElement; parent && parent !== root && parent.tagName !== 'MAIN' && parent !== document.body; parent = parent.parentElement) {
                    const value = overflowX(parent);
                    if (value === 'auto' || value === 'scroll') {
                        return fits(parent);
                    }
                    if (value === 'hidden' || value === 'clip') {
                        const ellipsis = getComputedStyle(el).textOverflow === 'ellipsis' || getComputedStyle(el.parentElement).textOverflow === 'ellipsis';
                        return fits(parent) && ellipsis;
                    }
                }
                return false;
            };
            const describe = (el) => {
                const rect = el.getBoundingClientRect();
                const hooks = [...el.attributes].filter((attribute) => attribute.name.startsWith('data-') && !attribute.name.startsWith('data-v-')).slice(0, 2).map((attribute) => `[\${attribute.name}\${attribute.value ? '=' + attribute.value : ''}]`).join('');
                const text = (el.innerText || '').trim().replace(/\s+/g, ' ').slice(0, 40);
                return `\${el.tagName.toLowerCase()}\${hooks} "\${text}" \${Math.round(rect.left)}..\${Math.round(rect.right)}`;
            };
            const offenders = [];
            const reported = [];
            const excusedAncestors = [];
            for (const root of document.querySelectorAll({$selector})) {
                if (root.matches('[role="dialog"]') && root.scrollWidth > root.clientWidth + 1) {
                    offenders.push(`\${describe(root)} scrolls sideways (\${root.scrollWidth} > \${root.clientWidth})`);
                }
                for (const el of [root, ...root.querySelectorAll('*')]) {
                    if ((el instanceof SVGElement && !(el instanceof SVGSVGElement)) || el.closest('.sr-only') || visuallyHidden(el) || !shown(el) || fits(el)) {
                        continue;
                    }
                    if (excusedAncestors.some((ancestor) => ancestor.contains(el))) {
                        continue;
                    }
                    if (excused(el, root)) {
                        excusedAncestors.push(el);
                        continue;
                    }
                    if (reported.some((ancestor) => ancestor.contains(el))) {
                        continue;
                    }
                    reported.push(el);
                    offenders.push(describe(el));
                }
            }
            return offenders.slice(0, 10).join(' | ');
        })()
    JS;
}
