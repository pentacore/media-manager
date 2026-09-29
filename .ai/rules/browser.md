---
paths:
  - 'tests/Browser/**'
---

# Browser

## Browser tests: actingAs, visit, assertNoSmoke
Authenticate with `$this->actingAs(User::factory()->{role}()->create())` before `visit()`, resolve paths via `route($name, absolute: false)`, and open every flow with `->assertNoSmoke()`. Scope element assertions to `data-*` attribute selectors (`assertSeeIn('[data-x]', ...)`) so sidebar/layout text cannot satisfy them. Register every new page route in `SmokeTest`'s member/admin route-name datasets.

## Sidebar badge counters must always cache, or browser tests stall per-request
HandleInertiaRequests recomputes InterventionCounter/SabnzbdDownloadCounter/WantedCounter inline on every request when the cache is cold. A recompute reaching an unreachable factory host (e.g. sonarr.local — a .local mDNS name that stalls DNS ~4s per call, ~13s per request) used to leave the cache unwritten, so every page render re-walked the host until the 30s assertion budget was gone (broke ConnectionSubtitleCheckTagsTest and SmokeTest's root-folder test, 2026-08). Two guards now exist — preserve both in refactors: InterventionCounter negative-caches failures (FAILURE_CACHE_TTL, 60s); WantedCounter is kept warm by the scheduled `library:refresh-wanted-count` (every 5 min) and HandleInertiaRequests only calls its `warm()` on a cold cache and only for manage-library users — one request at a time under a short Cache::lock (others get the cached value or 0), non-retrying upstream calls, a failure with nothing cached writing a 60s entry; and the Browser beforeEach in tests/Pest.php pre-seeds all three counter CACHE_KEYs so browser tests never recompute at all (badge logic is covered by InterventionCounterTest / HandleInertiaRequestsTest / WantedCounterTest). Keep test Http::fakes scoped — never a '*' catch-all, it blanks the SSR POST.
