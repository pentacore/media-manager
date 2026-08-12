---
paths:
  - 'app/Events/**'
---

# Events

## Events are broadcast payloads
Reach for an event only when the browser needs to hear about it: implement `ShouldBroadcast`, return a `PrivateChannel` from `broadcastOn()`, and hand-write `broadcastWith()` with snake_case scalars and ISO-8601 timestamps — never serialize a model. Wire ordinary service-to-service flow as direct injected calls, not events; listeners exist only for usage recording and rebroadcast fan-out and are never queued.
