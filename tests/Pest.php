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

/**
 * Phone-width overlap check (Batch 3h-3). Returns '' when no two visible
 * controls inside the roots matching $rootSelector cover each other on screen.
 * A control is `a[href]`, `button`, a non-hidden `input`, `select`,
 * `textarea` or an element with a button, checkbox, switch, tab, combobox or
 * menuitem role. Skipped: zero-size controls, `visibility` other than
 * `visible`, `pointer-events: none` on an enabled control (a disabled
 * Button is `pointer-events: none` yet keeps its box, so it stays in),
 * anything inside `.sr-only`, `[aria-hidden="true"]` or a reka-ui
 * `VisuallyHidden` (the inline-style test of `phoneOverflowScript()`), and anything that is, or sits in, a
 * `position: fixed` box below its root (a floating bar overlays content by
 * design). A control is measured by its visible rect: its border box — or,
 * when its computed `display` is `inline`, each of its line boxes — clipped by
 * every ancestor up to the root whose `overflow-x`/`overflow-y` is not
 * `visible`; a control its scroller clips away entirely is skipped. A pair is
 * reported when neither contains the other in the DOM and a rect of each
 * intersects the other's by more than 1 px in both axes, unless the smaller
 * rect lies wholly inside the larger (a checkbox on a card link). Otherwise
 * returns up to ten `<a> × <b>` pairs, each side `tag[data-…] "text"
 * left..right`, in document order of the first control.
 * Use with assertScript(phoneOverlapScript(), '').
 */
function phoneOverlapScript(string $rootSelector = 'main, [role="dialog"]'): string
{
    $selector = json_encode($rootSelector, JSON_THROW_ON_ERROR);

    return <<<JS
        (() => {
            const controlSelector = 'a[href], button, input:not([type="hidden"]), select, textarea, [role="button"], [role="checkbox"], [role="switch"], [role="tab"], [role="combobox"], [role="menuitem"]';
            const visuallyHidden = (el) => el.style.position === 'absolute' && el.style.width === '1px' && el.style.height === '1px' && el.style.overflow === 'hidden' && el.style.clipPath === 'inset(50%)';
            const hidden = (el, root) => {
                const rect = el.getBoundingClientRect();
                const style = getComputedStyle(el);
                if (rect.width === 0 || rect.height === 0 || style.visibility !== 'visible' || (style.pointerEvents === 'none' && !el.matches(':disabled')) || el.closest('.sr-only, [aria-hidden="true"]')) {
                    return true;
                }
                for (let node = el; node && node !== root; node = node.parentElement) {
                    if (visuallyHidden(node)) {
                        return true;
                    }
                }
                return false;
            };
            const floating = (el, root) => {
                for (let node = el; node && node !== root; node = node.parentElement) {
                    if (getComputedStyle(node).position === 'fixed') {
                        return true;
                    }
                }
                return false;
            };
            const clip = (el, root, rect) => {
                let { left, top, right, bottom } = rect;
                for (let parent = el.parentElement; parent && el !== root; parent = parent.parentElement) {
                    const style = getComputedStyle(parent);
                    if (style.display !== 'contents') {
                        const box = parent.getBoundingClientRect();
                        if (style.overflowX !== 'visible') {
                            left = Math.max(left, box.left);
                            right = Math.min(right, box.right);
                        }
                        if (style.overflowY !== 'visible') {
                            top = Math.max(top, box.top);
                            bottom = Math.min(bottom, box.bottom);
                        }
                    }
                    if (parent === root) {
                        break;
                    }
                }
                return right - left > 0 && bottom - top > 0 ? { left, top, right, bottom } : null;
            };
            const describe = (el) => {
                const rect = el.getBoundingClientRect();
                const hooks = [...el.attributes].filter((attribute) => attribute.name.startsWith('data-') && !attribute.name.startsWith('data-v-')).slice(0, 2).map((attribute) => `[\${attribute.name}\${attribute.value ? '=' + attribute.value : ''}]`).join('');
                const text = (el.innerText || el.getAttribute('aria-label') || '').trim().replace(/\s+/g, ' ').slice(0, 40);
                return `\${el.tagName.toLowerCase()}\${hooks} "\${text}" \${Math.round(rect.left)}..\${Math.round(rect.right)}`;
            };
            const controls = [];
            const seen = new Set();
            for (const root of document.querySelectorAll({$selector})) {
                for (const el of root.querySelectorAll(controlSelector)) {
                    if (seen.has(el)) {
                        continue;
                    }
                    seen.add(el);
                    if (hidden(el, root) || floating(el, root)) {
                        continue;
                    }
                    const boxes = (getComputedStyle(el).display === 'inline' ? [...el.getClientRects()] : [el.getBoundingClientRect()]).map((rect) => clip(el, root, rect)).filter((rect) => rect !== null);
                    if (boxes.length > 0) {
                        controls.push({ el, boxes });
                    }
                }
            }
            controls.sort((a, b) => (a.el.compareDocumentPosition(b.el) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1));
            const area = (rect) => (rect.right - rect.left) * (rect.bottom - rect.top);
            const inside = (inner, outer) => inner.left >= outer.left - 1 && inner.right <= outer.right + 1 && inner.top >= outer.top - 1 && inner.bottom <= outer.bottom + 1;
            const clash = (a, b) => Math.min(a.right, b.right) - Math.max(a.left, b.left) > 1 && Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top) > 1 && !(area(a) <= area(b) ? inside(a, b) : inside(b, a));
            const pairs = [];
            for (let i = 0; i < controls.length && pairs.length < 10; i++) {
                for (let j = i + 1; j < controls.length && pairs.length < 10; j++) {
                    const [a, b] = [controls[i], controls[j]];
                    if (a.el.contains(b.el) || b.el.contains(a.el)) {
                        continue;
                    }
                    if (a.boxes.some((boxA) => b.boxes.some((boxB) => clash(boxA, boxB)))) {
                        pairs.push(`\${describe(a.el)} × \${describe(b.el)}`);
                    }
                }
            }
            return pairs.join(' | ');
        })()
    JS;
}

/**
 * Phone-width scroll-sliver check (Batch 3h-3). Returns '' when no visible
 * scroller — a root matching $rootSelector or an element inside one whose
 * computed `overflow-x`/`overflow-y` is `auto` or `scroll` — scrolls along such
 * an axis by 1–12 px (`scrollWidth − clientWidth` sideways, `scrollHeight −
 * clientHeight` down). A scroll that small is no content, only an overhang: a
 * `touch-target` `::after` (40 px) sticking out of a smaller control in a
 * scroller, 12 px being the overhang of the smallest one, a 16 px checkbox or
 * close X ((40 − 16) / 2). Hidden scrollers are skipped like
 * `phoneOverlapScript()` skips hidden controls. Otherwise returns up to ten
 * `tag[data-…] "text" left..right scrolls sideways|down by Npx` entries.
 * Use with assertScript(phoneScrollSliverScript(), '').
 */
function phoneScrollSliverScript(string $rootSelector = 'main, [role="dialog"]'): string
{
    $selector = json_encode($rootSelector, JSON_THROW_ON_ERROR);

    return <<<JS
        (() => {
            const visuallyHidden = (el) => el.style.position === 'absolute' && el.style.width === '1px' && el.style.height === '1px' && el.style.overflow === 'hidden' && el.style.clipPath === 'inset(50%)';
            const hidden = (el, root) => {
                const rect = el.getBoundingClientRect();
                const style = getComputedStyle(el);
                if (rect.width === 0 || rect.height === 0 || style.visibility !== 'visible' || style.pointerEvents === 'none' || el.closest('.sr-only, [aria-hidden="true"]')) {
                    return true;
                }
                for (let node = el; node && node !== root; node = node.parentElement) {
                    if (visuallyHidden(node)) {
                        return true;
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
            const scrolls = (value) => value === 'auto' || value === 'scroll';
            const slivers = [];
            const seen = new Set();
            for (const root of document.querySelectorAll({$selector})) {
                for (const el of [root, ...root.querySelectorAll('*')]) {
                    if (seen.has(el)) {
                        continue;
                    }
                    seen.add(el);
                    const style = getComputedStyle(el);
                    if ((!scrolls(style.overflowX) && !scrolls(style.overflowY)) || hidden(el, root)) {
                        continue;
                    }
                    const dx = el.scrollWidth - el.clientWidth;
                    const dy = el.scrollHeight - el.clientHeight;
                    if (scrolls(style.overflowX) && dx > 0 && dx <= 12) {
                        slivers.push(`\${describe(el)} scrolls sideways by \${dx}px`);
                    }
                    if (scrolls(style.overflowY) && dy > 0 && dy <= 12) {
                        slivers.push(`\${describe(el)} scrolls down by \${dy}px`);
                    }
                }
            }
            return slivers.slice(0, 10).join(' | ');
        })()
    JS;
}

/**
 * Phone touch-target sweep (Batch 3h-2, shared from 3h-3). Every visible
 * control in $root (default `main`) whose tappable area — its box or its
 * touch-target ::after, measured with elementFromPoint from its centre — is
 * under 40 px in either direction, as "tag[hook] "text" Npx". Inline text
 * links are exempt. A native `input[type="checkbox"]` or
 * `input[type="radio"]` is measured through `closest('label')` when it has
 * one: a tap anywhere in the wrapping label toggles the input, so the label
 * is its tap area (a hit counts when it lands inside that area). Empty when
 * every control is a 40 px target.
 */
function phoneSmallTargetsScript(string $root = 'main'): string
{
    return str_replace('__ROOT__', $root, <<<'JS'
        (() => {
            const offenders = [];
            const controls = document.querySelectorAll(['a[href]', 'button', '[role="button"]', '[role="checkbox"]', '[role="switch"]', '[role="tab"]', '[role="combobox"]', 'select', 'input[type="checkbox"]', 'input[type="radio"]'].map((control) => `__ROOT__ ${control}`).join(', '));
            for (const element of controls) {
                const style = getComputedStyle(element);
                if (element.closest('[aria-hidden="true"], .sr-only') || style.visibility !== 'visible' || style.pointerEvents === 'none') {
                    continue;
                }
                if (element.tagName === 'A' && style.display === 'inline') {
                    continue;
                }
                if (element.getBoundingClientRect().width === 0 || element.getBoundingClientRect().height === 0) {
                    continue;
                }
                const area = element.matches('input[type="checkbox"], input[type="radio"]') ? (element.closest('label') ?? element) : element;
                area.scrollIntoView({ block: 'center', inline: 'center' });
                const rect = area.getBoundingClientRect();
                const x = rect.left + rect.width / 2;
                const y = rect.top + rect.height / 2;
                const hits = (px, py) => { const hit = document.elementFromPoint(px, py); return !!hit && area.contains(hit); };
                const reach = (dx, dy) => { let step = 0; while (step < 30 && hits(x + dx * (step + 1), y + dy * (step + 1))) { step++; } return step; };
                const size = hits(x, y) ? Math.min(reach(0, -1) + reach(0, 1) + 1, reach(-1, 0) + reach(1, 0) + 1) : 0;
                if (size < 39) {
                    const hook = [...element.attributes].map((attribute) => attribute.name).find((name) => name.startsWith('data-')) ?? '';
                    const text = (element.innerText || element.getAttribute('aria-label') || '').replace(/\s+/g, ' ').trim().slice(0, 24);
                    offenders.push(`${element.tagName.toLowerCase()}[${hook}] "${text}" ${size}px`);
                }
            }
            return offenders.slice(0, 15).join(' | ');
        })()
    JS);
}
