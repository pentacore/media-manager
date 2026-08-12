---
paths:
  - 'app/Observers/*.php'
---

# Observers

## Keep observers thin
Observer methods are named after the lifecycle event, take the typed model, return void, and only dispatch a domain event or queue a job — no direct persistence. Implement `ShouldHandleEventsAfterCommit`, and have jobs that write back use `saveQuietly()` to avoid re-entry.
