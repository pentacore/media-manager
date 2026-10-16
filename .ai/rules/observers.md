---
paths:
  - 'app/Observers/*.php'
---

# Observers

## Keep observers thin
Observer methods are named after the lifecycle event, take the typed model, return void, and only dispatch a domain event or queue a job — no direct persistence. Implement `ShouldHandleEventsAfterCommit`, and have jobs that write back use `saveQuietly()` to avoid re-entry — except for an `ActionRequest` status change, which must never be saved quietly (it skips the numbering observer; see `models.md`).

`ShouldHandleEventsAfterCommit` defers only the post-save hooks: the event dispatcher (`Dispatcher::createClassCallable()`) always runs `creating`, `updating`, `saving`, `deleting`, `restoring` and `forceDeleting` synchronously, inside the save, even on an after-commit observer. `ActionRequestStatusVersionObserver` sets `status_version` in `creating`/`updating`; it does not implement the interface and stays a separate class from `ActionRequestObserver` only to keep numbering apart from the after-commit audit and broadcast work. It only sets that one attribute on the model being saved; it never writes anything itself.
