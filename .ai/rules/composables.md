---
paths:
  - 'resources/js/composables/**'
---

# Composables

## Composables
Name the file and its named export `useX`; export a `function`, never a default or arrow const. Declare and export an explicit `UseXReturn` type for the returned object. Composables owning an Echo/Reverb channel expose `subscribe`/`unsubscribe` and self-release from `onUnmounted`, and join a channel only when `useCan()` grants the ability `routes/channels.php` checks (`useNotifications` and `useNavCounts` join `members.actions` under `manage-library`): a refused join logs a console error on every page. `ViewerAccessTest` pins it.

## Live rows carry per-reader fields through map
`useRealtimeList({ channel, event, keyField, map })` takes one channel or a list (every channel feeds one list, deduplicated by `keyField`) and an optional `map(payload) => row`. A broadcast goes to every reader, so it can never carry a per-reader field the page's resource computes per request (`can_toggle_played`): broadcast the fact the rule needs (`EmbyPlaybackUpdated.user_id`, the Emby link's owner) and derive the field in `map` with the same rule as the model (`EmbyActivity::playedStateEditableBy()`), leaving the server to re-check on submit. Without `map`, a pushed row that replaces a server row silently drops the field.

## Sidebar badge counts are absolute and survive a prefetch
`useNavCounts` takes the pending-actions badge from `pending_count` on `ActionRequestCreated` / `ActionRequestStatusChanged` (every pending request when the queued broadcast ran), never from per-id deltas, which could not lower it for a request pending before the tab opened. A `nav` snapshot overwrites a counter only if no live event moved that counter after the snapshot was produced: a response to a request just sent counts as "now", a click that reuses a hover prefetch counts as the prefetch's `fetchedAt` (`router.on('prefetched')`). Never forget that date when a real request for the URL starts: Inertia keeps the cached prefetch reusable for its whole `cacheFor` window, and the page's own deferred-prop reload is such a request. Do drop it once that window has passed (`PREFETCH_CACHE_MS`, matching `app.ts`'s `cacheFor`): no click can reuse the prefetch after it, and the map would otherwise grow all session. Instead, every request that brings `nav` (a full visit, or a reload whose `only` names it) re-applies it as "now" on `success`. A page that changes counts through a JSON request, not a visit (the Action Queue's bulk run), reloads `nav` with its own props. `PrefetchTest` and `ActionQueueBulkTest` pin both.
