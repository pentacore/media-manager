---
paths:
  - 'tests/Browser/**'
---

# Browser

## Browser tests: actingAs, visit, assertNoSmoke
Authenticate with `$this->actingAs(User::factory()->{role}()->create())` before `visit()`, resolve paths via `route($name, absolute: false)`, and open every flow with `->assertNoSmoke()`. Scope element assertions to `data-*` attribute selectors (`assertSeeIn('[data-x]', ...)`) so sidebar/layout text cannot satisfy them. Register every new page route in `SmokeTest`'s member/admin route-name datasets.

## Sidebar badge counters must always cache, or browser tests stall per-request
HandleInertiaRequests recomputes InterventionCounter/SabnzbdDownloadCounter inline on every request when the cache is cold. A recompute reaching an unreachable factory host (e.g. sonarr.local — a .local mDNS name that stalls DNS ~4s per call, ~13s per request) used to leave the cache unwritten, so every page render re-walked the host until the 30s assertion budget was gone (broke ConnectionSubtitleCheckTagsTest and SmokeTest's root-folder test, 2026-08). Two guards now exist — preserve both in refactors: InterventionCounter negative-caches failures (FAILURE_CACHE_TTL, 60s), and the Browser beforeEach in tests/Pest.php pre-seeds both counter CACHE_KEYs so browser tests never recompute at all (badge logic is covered by InterventionCounterTest / HandleInertiaRequestsTest). Keep test Http::fakes scoped — never a '*' catch-all, it blanks the SSR POST.
