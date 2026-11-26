---
paths:
  - 'app/Services/Arr/**'
  - 'app/Services/Sonarr/**'
  - 'app/Services/Radarr/**'
  - 'app/Services/Whisparr/**'
  - 'app/Services/Prowlarr/ProwlarrClient.php'
  - 'app/Cache/Services/SonarrCache.php'
  - 'app/Cache/Services/RadarrCache.php'
  - 'app/Cache/Services/WhisparrCache.php'
  - 'app/Http/Controllers/Media/BaseArrController.php'
  - 'app/Http/Controllers/Media/SeriesController.php'
  - 'app/Http/Controllers/Media/MovieController.php'
  - 'app/Http/Controllers/Whisparr/**'
  - 'app/Http/Requests/Media/StoreSeriesRequest.php'
  - 'app/Http/Requests/Media/StoreMovieRequest.php'
  - 'app/Http/Requests/Media/DestroyArrMediaRequest.php'
  - 'app/Http/Requests/Whisparr/**'
  - 'resources/js/pages/Sonarr/**'
  - 'resources/js/pages/Radarr/**'
  - 'resources/js/pages/Whisparr/**'
  - 'resources/js/components/series/**'
  - 'resources/js/components/library-actions/**'
  - 'tests/Feature/Services/Arr/**'
  - 'tests/Feature/Services/Sonarr/**'
  - 'tests/Feature/Services/Radarr/**'
  - 'tests/Feature/Services/Whisparr/**'
  - 'tests/Unit/Services/Arr/**'
  - 'tests/Unit/Services/Whisparr/**'
  - 'tests/Feature/Whisparr/**'
  - 'tests/Feature/Media/SeriesControllerTest.php'
  - 'tests/Feature/Media/MovieControllerTest.php'
  - 'tests/Feature/Media/Library*Test.php'
  - 'tests/Browser/Library*Test.php'
  - 'tests/Browser/ArrLibraryOutageTest.php'
  - 'tests/Browser/SeriesShowSectionsTest.php'
  - 'tests/Browser/Whisparr*Test.php'
  - 'tests/Unit/Architecture/ArrClientConstructionArchTest.php'
---

# Arr

The Sonarr, Radarr and Whisparr libraries: their clients, caches and library services, the library and title pages, in every layer. Library writes queue an `ActionRequest` (`actions.md`).

## Pooled bulk reads make one attempt
`ArrClient::fetchResourcesByIds()` (Sonarr `fetchSeriesByIds()`, Radarr `fetchMoviesByIds()`) deliberately skips `buildClient()`'s retry: a retry multiplies page-load wait for a hung host. Unreadable items come back null and callers fall back per item; it stops after a batch of ten that failed entirely on transport (every request a ConnectionException). Do not add retry.

## Arr reads and writes: jsonArray() / confirmedWrite(), never a bare ->json()
`ArrClient` (Sonarr/Radarr/Whisparr/Prowlarr) wraps every decoded response through one of two helpers, never a bare `->throw()->json() ?? []`. Reads go through `jsonArray()`, which throws `ArrUnexpectedResponse` on a 200 whose body isn't JSON data (a reverse-proxy login page, an HTML error page) instead of silently returning an empty list. Writes go through `confirmedWrite()`, which throws `ArrWriteUnconfirmed` on the same condition — an empty body still counts as success (DELETE/command endpoints answer `200 ''`). `ArrWriteUnconfirmed` is never retried: a synchronous write controller must catch it **before** its generic `RequestException|ConnectionException` catch and word the outcome as unknown ("whether it was applied is unknown"), never as a refusal or a success — repeating a write whose outcome is unknown can duplicate it. `ExecuteActionRequest` does the equivalent for queued writes (`needs_reconciliation`, `indeterminate: true`). `SabnzbdClient`'s reads follow the same shape with `SabnzbdRefused`. `EmbyClient` guards only `getUsers()` and `getSystemInfo()` with `EmbyUnexpectedResponse`; its other reads still decode with `json() ?? []`.

## Viewers get names, not option lists
On a detail page (a Sonarr series or Radarr movie show page), the value picker's option list (quality profiles, root folders) goes only to the users who can change the value. Everyone else gets the current value's name as its own deferred prop in its own group (`qualityProfileName`), resolved from the same cached list (`BaseArrController::qualityProfileName()`), with `null` for an outage or a stale id, rendered "—". On a detail page, never send a viewer the whole list to look one name up, and never print the raw id. This is not a privacy boundary: the library index pages send `qualityProfiles` to everyone, viewers included, for the profile filter and the card labels (`DeferredPropGroupsTest` pins it); do not gate that list there, because profile names are not sensitive.

## Library Sync busts the connection cache, throttled per connection
The Sonarr, Radarr and Whisparr index pages' Sync button posts to `media.series.sync` / `media.movies.sync` (`BaseArrController::sync()`, `can:view-library` like the pages) or `media.whisparr.sync` (`WhisparrController::sync()`, `can:admin`). Each goes through `Controller::syncLibraryCache()` → `LibraryCacheSync::sync()`, which `bustAll()`s the connection's cache at most once per 30 s **per connection, whoever asks** (`RateLimiter` key `library-sync:{id}`); a throttled call leaves the cache alone and answers an info toast with the seconds left. The page posts with `only: [<list>, 'qualityProfiles']` so the redirect back reloads just the lists. Never let a page "sync" by `router.reload()` alone: it re-reads the cache. `tests/Feature/Media/LibrarySyncTest.php` pins the throttle with `travel()`, never `sleep()`.

## Library card controls sit beside the card link
A library grid card is a `<Link>` (it keeps every `data-*-card` hook and the hover prefetch), wrapped in a `group relative` `div`. Interactive controls on the card (`BulkCheckbox`, Radarr's open-in-Radarr link) live in a sibling overlay `div.pointer-events-none.absolute.inset-x-0.top-0.aspect-[2/3]` sized like the poster, each with `pointer-events-auto`; never put a button, checkbox or second link inside the card's anchor. Hover styling keys off the wrapper's `group`. On Whisparr the wrapper owns `focusin`/`focusout` and the checkbox wrapper repeats the poster's `pointerenter`/`pointerleave`, so focusing or hovering the checkbox keeps the poster revealed. `LibraryBulkActionsTest` and `WhisparrLibraryTest` pin it.
