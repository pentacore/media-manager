---
paths:
  - 'app/Services/**'
---

# Services

## Query Eloquent directly — no repository layer
Call Eloquent models directly from controllers, services, jobs, and listeners — do not add a repository or query-object indirection. When a query needs conditional filters, extract a `private` method on the calling class returning a `Builder`, annotated `@return Builder&lt;Model&gt;`. `*Repository` classes are reserved for raw `DB::table()` read layers over aggregate/rollup tables.

## Service layer shape
Group services in a per-integration subfolder and name by role: `*Client` for upstream HTTP, `*Actions` for `ActionExecutor` implementations, `*WebhookHandler` for inbound webhooks, plus domain-named single-purpose collaborators (`*Fingerprint`, `*Ranker`, `*Resolver`, …). Name the entry method after the role — `execute()` for executors, `handle()` for webhook handlers, a domain verb otherwise; `__invoke()` is reserved for controllers.

## Upstream HTTP clients
Build every upstream request with the `Http` facade through a `buildClient()` helper setting base URL, auth header, 10s timeout, 3s connect timeout, the MediaManager user agent, and `retry(..., throw: false)`. Read credentials from the injected `ServiceConnection` or `config('services.*')`, never `env()`. No Saloon, no raw Guzzle. Clients lazily hold their `*Cache` sibling and leave writes uncached — the matching `*Actions` class busts.

## Arr reads and writes: jsonArray() / confirmedWrite(), never a bare ->json()
`ArrClient` (Sonarr/Radarr/Whisparr/Prowlarr) wraps every decoded response through one of two helpers, never a bare `->throw()->json() ?? []`. Reads go through `jsonArray()`, which throws `ArrUnexpectedResponse` on a 200 whose body isn't JSON data (a reverse-proxy login page, an HTML error page) instead of silently returning an empty list. Writes go through `confirmedWrite()`, which throws `ArrWriteUnconfirmed` on the same condition — an empty body still counts as success (DELETE/command endpoints answer `200 ''`). `ArrWriteUnconfirmed` is never retried: a synchronous write controller must catch it **before** its generic `RequestException|ConnectionException` catch and word the outcome as unknown ("whether it was applied is unknown"), never as a refusal or a success — repeating a write whose outcome is unknown can duplicate it. `ExecuteActionRequest` does the equivalent for queued writes (`needs_reconciliation`, `indeterminate: true`). `SabnzbdClient`'s reads follow the same shape with `SabnzbdRefused`. `EmbyClient` guards only `getUsers()` and `getSystemInfo()` with `EmbyUnexpectedResponse`; its other reads still decode with `json() ?? []`.

## DTOs are hand-rolled readonly value objects
Model the outcome of a multi-step process as a `final readonly` class with promoted public properties, named-argument construction, and a documented `toArray()` — there is no DTO package. Keep upstream API responses as plain arrays annotated with an `array{...}` docblock shape.

## Webhook handlers never mark events processed
`ProcessWebhookEvent` calls `WebhookEvent::markProcessed($status)` after `handle()` returns (one write of `processed_at` + `handling_status`, then `WebhookEventProcessed`). Handlers return a `WebhookHandlingStatus` and must not touch `processed_at`: a handler that marked processed and then threw (e.g. in a cache `bustAll()`) left the row stuck in `Processing`, because `claim()` skips processed rows on retry.

## Emby library scans go through EmbyLibraryScanScheduler
Webhook handlers request Emby refreshes with `EmbyLibraryScanScheduler::schedule()`, never `ActionOrchestrator::dispatch('emby_library_scan', …)`. It folds triggers for one Emby connection into one not-yet-started request (Pending or Approved, < 10 min old) and delays execution 60 s past the latest trigger via `ExecuteDebouncedLibraryScan`, so a season pack yields one refresh. `EmbyActions` scans `payload.emby_connection_id` when present.

## The subtitle discovery memo lives for one injected instance
`App\Services\Bazarr\SubtitleCaseCandidates` memoizes one reconciliation cycle's mapped-library scan in `$discoveryFeeds`, so it is `final` but not `readonly` and is never bound in a provider: `ReconcileBazarrConnection::handle()` method-injects it, which makes one instance one cycle. Never bind it `singleton()` (a long-lived worker would serve every later cycle the first cycle's catalog) or `scoped()` (unbound already gives one instance per injection, which is today's behaviour — `scoped()` adds nothing and invites a future per-request-but-cross-cycle leak), and do not cache its result across instances. The other inventory collaborators (`SubtitleLibraryReader`, `SubtitleInspector`, `SubtitleItemMapper`, `SubtitleInventoryConnections`) are stateless `final readonly` classes; add new subtitle inventory reads to the one that owns the concern rather than reviving a catch-all service.

## Webhook handlers clear caches only for events that change cached data
Each `*WebhookHandler` lists, in `private const array CACHE_CLEARING_EVENTS`, the event types that change what its service cache holds, and calls `bustAll()` only for those: arr imports, renames, adds, deletes and file deletes; Seerr's `MEDIA_*` request lifecycle. Test, Grab (no cache holds the download queue), Health, HealthRestored, ApplicationUpdate, ManualInteractionRequired, Seerr `TEST_NOTIFICATION`/`ISSUE_*` and ignored types clear nothing. A clearing event still flushes the whole connection scope, because it changes nearly every key there and the calendar keys are named by date range. SABnzbd keeps nothing in `SabnzbdCache`, so its handler clears nothing. When a handler learns a new event type that changes library or request data, add it to the list and to the handler's `*WebhookHandlerCacheBustTest` "clears" dataset.

## The Prowlarr release cache is written only for admins
`IndexerReleaseCache::remember()` stores each search row's guid so a later grab can only target a release MediaManager showed. Only admins can grab (`prowlarr.grab` is `can:admin`), so `SearchIndexersController` calls `remember()` for admins and `present()` for everyone else — the same display allowlist with `key` and `indexer_id` null and nothing written. A new caller that shows Prowlarr releases follows the same split.
