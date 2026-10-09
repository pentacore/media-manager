---
paths:
  - 'app/Events/**'
---

# Events

## Events are broadcast payloads
Reach for an event only when the browser needs to hear about it: implement `ShouldBroadcast`, return a `PrivateChannel` from `broadcastOn()`, and hand-write `broadcastWith()` with snake_case scalars and ISO-8601 timestamps — never serialize a model. Wire ordinary service-to-service flow as direct injected calls, not events; listeners exist only for usage recording, rebroadcast fan-out and the `/up` health probe (`CheckInfrastructureHealth` on `DiagnosingHealth`, auto-discovered — never also register it), and are never queued.

## Action results reach the browser through safeResult()
`ActionRequestBrowserPayload::safeResult()` is the only shape of an action's `result` that leaves the server for non-admins: `success`, `reason`, `rejection_reason` (string or null), or `null` while nothing is recorded. `ActionRequestResource` sends it to non-admins (admins get the full result: `message`, `exception`, `indeterminate`), and `ActionRequestCreated` / `ActionRequestStatusChanged` broadcast it on `members.actions`. A new reader or broadcast of an action result calls the same method; never hand-pick result keys inline. `tests/Feature/Broadcasting/BroadcastEventTest.php` pins that both broadcasts equal the member resource's `result`.
