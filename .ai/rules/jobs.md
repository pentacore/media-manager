---
paths:
  - 'app/Jobs/**'
---

# Jobs

## Queued jobs
Implement `ShouldQueue` with the single `Queueable` trait, declare `tries`/`timeout`/`backoff` as typed public properties, and add `ShouldBeUnique` with a `uniqueId()` for reconcile and agent jobs (timeout below queue `retry_after`). Keep jobs on the default queue — no `onQueue()` — and put throttling/concurrency limits in a job middleware class under `app/Jobs/Middleware`. Carry payload snapshots, not models, when the row may be trimmed; set `$deleteWhenMissingModels = true` when a model may vanish.
