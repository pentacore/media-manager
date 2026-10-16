---
paths:
  - 'app/Events/**'
---

# Events

## Events are broadcast payloads
Reach for an event only when the browser needs to hear about it: implement `ShouldBroadcast`, return a `PrivateChannel` from `broadcastOn()` (or a list of them: `ActivityLogCreated` sends a non-audit row to `activity` and to its owner's `App.Models.User.{id}`, so a listener on several channels deduplicates by id), and hand-write `broadcastWith()` with snake_case scalars and ISO-8601 timestamps — never serialize a model. Wire ordinary service-to-service flow as direct injected calls, not events; listeners exist only for usage recording, rebroadcast fan-out and the `/up` health probe (`CheckInfrastructureHealth` on `DiagnosingHealth`, auto-discovered — never also register it), and are never queued.

## Action results reach the browser through safeResult()
`ActionRequestBrowserPayload::safeResult()` is the only shape of an action's `result` that leaves the server for non-admins: `success`, `reason`, `rejection_reason` (string or null), or `null` while nothing is recorded. `ActionRequestResource` sends it to non-admins (admins get the full result: `message`, `exception`, `indeterminate`), `ActionRequestCreated` / `ActionRequestStatusChanged` broadcast it on `members.actions`, and `ActionRequestActivityLogger` stores it as the member-visible activity row's `metadata.result`. A new reader or broadcast of an action result calls the same method; never hand-pick result keys inline. `tests/Feature/Broadcasting/BroadcastEventTest.php` pins that both broadcasts equal the member resource's `result`; `tests/Feature/Observers/ActionRequestObserverTest.php` pins the activity metadata.

## Action broadcasts carry versions, not just timestamps
`ActionRequestCreated` and `ActionRequestStatusChanged` send `status_version` (the row's, see `models.md`), `pending_count` and `pending_version` (`PendingActionCount::snapshot()`: the count and the newest `status_version` it reflects, read in one statement); `ActionRequestResource` sends `status_version` and `nav` sends `pendingActionsVersion`. The browser orders by these, never by `updated_at` seconds: a same-second change and host clock skew misorder timestamps. A payload without a version (a broadcast built by a worker still on older code) falls back to the timestamp rule, and an equal version is a tie that applies, so keep every new reader tolerant of a missing or unchanged version. `pending_version` is ordered by allocation, not by commit: a transaction that took its number early and committed after a later one produces a second snapshot with the same `pending_version` but a different count, and whichever lands last wins the tie. That is accepted; the next change or full visit corrects it. `BroadcastEventTest` and `HandleInertiaRequestsTest` pin the keys.
