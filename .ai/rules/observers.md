---
paths:
  - 'app/Observers/*.php'
---

# Observers

## Keep observers thin
Observer methods are named after the lifecycle event, take the typed model, return void, and only dispatch a domain event or queue a job — no direct persistence. Implement `ShouldHandleEventsAfterCommit`, and have jobs that write back use `saveQuietly()` to avoid re-entry — except for an `ActionRequest` status change, which must never be saved quietly (it skips the numbering observer; see `models.md`).

`ShouldHandleEventsAfterCommit` defers only the post-save hooks: the event dispatcher (`Dispatcher::createClassCallable()`) always runs `creating`, `updating`, `saving`, `deleting`, `restoring` and `forceDeleting` synchronously, inside the save, even on an after-commit observer. `ActionRequestStatusVersionObserver` sets `status_version` in `creating`/`updating`; it does not implement the interface and stays a separate class from `ActionRequestObserver` only to keep numbering apart from the after-commit audit and broadcast work. It only sets that one attribute on the model being saved; it never writes anything itself.

## ServerCheckObserver bumps servers.config_version synchronously
`app/Observers/ServerCheckObserver.php` (under this file's `app/Observers/*.php` glob) is the one exception to "no direct persistence": it increments `servers.config_version` in `created`, in `updated` when `type`, `params` or `is_enabled` changed, and in `deleted`, and it does not implement `ShouldHandleEventsAfterCommit`. An agent refetches its checks only when the version it reports differs, so the bump must commit in the same transaction as the check write; an after-commit or queued bump could leave a committed change that agents never fetch. It is the only hook that sees every check write (controllers, factories, tinker). Keep it one builder `increment()` on the server row; agent results are stored with builder updates (`CheckResultRecorder`), so they never reach it. `ServerModelTest` pins the bumps and that a result does not bump.
