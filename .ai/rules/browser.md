---
paths:
  - 'tests/Browser/**'
---

# Browser

## Browser tests: actingAs, visit, assertNoSmoke
Authenticate with `$this->actingAs(User::factory()->{role}()->create())` before `visit()`, resolve paths via `route($name, absolute: false)`, and open every flow with `->assertNoSmoke()`. Scope element assertions to `data-*` attribute selectors (`assertSeeIn('[data-x]', ...)`) so sidebar/layout text cannot satisfy them. Register every new page route in `SmokeTest`'s member/admin route-name datasets.

## Sidebar badge counters must always cache, or browser tests stall per-request
HandleInertiaRequests recomputes InterventionCounter/SabnzbdDownloadCounter/WantedCounter inline on every full page visit and prefetch (the lazy `nav` prop is skipped on partial reloads) when the cache is cold. A recompute reaching an unreachable factory host (e.g. sonarr.local — a .local mDNS name that stalls DNS ~4s per call, ~13s per request) used to leave the cache unwritten, so every page render re-walked the host until the 30s assertion budget was gone (broke ConnectionSubtitleCheckTagsTest and SmokeTest's root-folder test, 2026-08). Two guards now exist — preserve both in refactors: InterventionCounter negative-caches failures (FAILURE_CACHE_TTL, 60s); WantedCounter is kept warm by the scheduled `library:refresh-wanted-count` (every 5 min) and HandleInertiaRequests only calls its `warm()` on a cold cache and only for manage-library users — one request at a time under a short Cache::lock (others get the cached value or 0), non-retrying upstream calls, a failure with nothing cached writing a 60s entry; and the Browser beforeEach in tests/Pest.php pre-seeds all three counter CACHE_KEYs so browser tests never recompute at all (badge logic is covered by InterventionCounterTest / HandleInertiaRequestsTest / WantedCounterTest). Keep test Http::fakes scoped — never a '*' catch-all, it blanks the SSR POST.

## Live tests emit through echoEmitScript() after hydration
Tests broadcast through the null driver, so a live test hands the event to the page with `echoEmitScript($channel, $event, $payload, $minimumListeners)` from `tests/Pest.php`: it waits until that many callbacks are bound for the event on `private-{$channel}` and throws when they never are. Never emit through a file-local `window.Pusher` helper. Call `appHydratedScript()` before reading Echo's channels, hovering a prefetching link or clicking right after `visit()`: SSR markup satisfies DOM assertions before Vue has hydrated, and nothing has subscribed or bound until then. On a full `visit()` every consumer (layout toast, sidebar badges, the page) binds in one mount flush, so one listener is enough there; pass the real count when a consumer binds later, or after a client-side visit under the persistent layout, where the layout's listeners already exist before the page mounts (the Action Queue waits for 3 on `members.actions`, Watch History for 2 on `emby.activity`).
