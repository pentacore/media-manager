---
paths:
  - 'app/Observers/*.php'
---

# Observers

## Keep observers thin
Observer methods are named after the lifecycle event, take the typed model, return void, and only dispatch a domain event or queue a job — no direct persistence. Implement `ShouldHandleEventsAfterCommit`, and have jobs that write back use `saveQuietly()` to avoid re-entry.

One exception: `ActionRequestStatusVersionObserver` sets `status_version` in `creating`/`updating`, so it does **not** implement `ShouldHandleEventsAfterCommit` (that interface defers every method, `updating` included, until after the save) and it stays a separate class from `ActionRequestObserver`. It only sets that one attribute on the model being saved; it never writes anything itself.
