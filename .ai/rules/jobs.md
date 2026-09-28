---
paths:
  - 'app/Jobs/**'
---

# Jobs

## Queued jobs
Implement `ShouldQueue` with the single `Queueable` trait, declare `tries`/`timeout`/`backoff` as typed public properties, and add `ShouldBeUnique` with a `uniqueId()` for reconcile and agent jobs (timeout below queue `retry_after`). Route a job to its lane with `#[Queue(QueueLane::X)]` (`Illuminate\Queue\Attributes\Queue`): `Actions` for action execution, `Webhooks` for webhook processing, `Ai` for any job that calls a model provider (agents, embeddings, titles, price refresh); every other job carries no attribute and stays on `default`. Never call `onQueue()` at a dispatch site. Keep every job timeout below its worker's `--timeout` (300s) and the redis `retry_after` (330s). Put throttling/concurrency limits in a job middleware class under `app/Jobs/Middleware`. Carry payload snapshots, not models, when the row may be trimmed; set `$deleteWhenMissingModels = true` when a model may vanish.
