---
paths:
  - 'app/Services/Actions/**'
  - 'app/Services/**/*Actions.php'
  - 'app/Services/Library/LibraryActionRequester.php'
  - 'app/Http/Controllers/Actions/**'
  - 'app/Http/Controllers/Library/MediaActionController.php'
  - 'app/Http/Controllers/Whisparr/WhisparrActionController.php'
  - 'app/Http/Requests/Actions/**'
  - 'app/Http/Requests/Library/Bulk*.php'
  - 'app/Http/Requests/Whisparr/Bulk*.php'
  - 'app/Http/Resources/Action*.php'
  - 'app/Jobs/ExecuteActionRequest.php'
  - 'app/Console/Commands/ReconcileStuckActionRequests.php'
  - 'app/Events/ActionRequest*.php'
  - 'app/Models/ActionRequest.php'
  - 'app/Models/ActionTypeConfig.php'
  - 'app/Observers/ActionRequest*.php'
  - 'app/Enums/ActionRequestStatus.php'
  - 'app/Enums/ActionQueueBulkAction.php'
  - 'database/factories/Action*.php'
  - 'database/seeders/ActionTypeConfigSeeder.php'
  - 'resources/js/pages/Actions/**'
  - 'resources/js/components/actions/**'
  - 'resources/js/composables/useActionQueueLive.ts'
  - 'tests/Feature/Actions/**'
  - 'tests/Feature/Services/Actions/**'
  - 'tests/**/ActionRequest*Test.php'
  - 'tests/**/ActionQueue*Test.php'
  - 'tests/**/ActionTypeConfig*Test.php'
  - 'tests/Feature/Library/BulkLibraryActionTest.php'
  - 'tests/Feature/Library/MediaActionControllerTest.php'
  - 'tests/Feature/Whisparr/WhisparrActionControllerTest.php'
  - 'tests/Feature/Services/Library/LibraryActionRequesterTest.php'
  - 'tests/Feature/Console/ReconcileStuckActionRequestsCommandTest.php'
---

# Actions

The action queue: `ActionRequest`, `ActionOrchestrator`, the `ActionExecutor`s (`*Actions`), bulk endpoints and the Action Queue pages, in every layer. AI tools that queue an action also follow `ai.md`.

## Every action request carries a server-verified ActionDescription
ActionOrchestrator::dispatch()/dispatchFromAgent() require an ActionDescription. Word arr/Seerr/Emby/download types with ActionDescriber (Bazarr: SubtitleOperationDescriber, replacements: ReplacementRequestBuilder) and add only the "why" via because() plus trigger facts. Target names come from ActionTargets (index → live client); LLM text is only a fallback_title, which marks the description unverified and forces Pending. agent_rationale stays in the payload and never becomes the title/description. Read-only lookups for descriptions are allowed from Destructive tools. Also applies to app/Ai/**, app/Services/**/*WebhookHandler.php, and app/Http/Controllers/**. Replacement and Bazarr describers must not put caller-supplied free text (e.g. a model's reason) into details.

## Conditional ActionRequest transitions log themselves
Query-builder status updates on ActionRequest (the Approved→Executing claim in ExecuteActionRequest, actions:reconcile-stuck) bypass ActionRequestObserver. After such an update succeeds, refresh the model and call ActionRequestActivityLogger::statusChanged() so the audit trail is complete. resolvePinned() aborts on a deactivated pinned connection of the requested type.

## Bulk endpoints reuse the single-item path
Every `POST …/bulk` endpoint validates `ids` (1–100, distinct) and loops them through `BulkRunner::run()`, whose closure calls the SAME service method the single button calls (`LibraryActionRequester`, `ActionRequestReviewer`, `SabnzbdSlotOperator`, `QueueItemRemover`). Never add a bulk-only shortcut around permissions, pinning, approval rules, activity rows or audit rows — put new single-item behaviour in the shared service so bulk inherits it. Failure titles come from server data, never from the request. Also applies to app/Http/Controllers/**.

## Every action request status change bumps status_version
`action_requests.status_version` orders status changes for the browser (a same-second change and host clock skew misorder `updated_at`). Every write that changes `status` must take a new number from the `action_request_status_versions` sequence. A model create or save gets it from `ActionRequestStatusVersionObserver` (`creating`, and `updating` when `status` is dirty, through `ActionRequest::nextStatusVersion()`), so it is written in the same statement and the model carries it. A query-builder update skips every observer, so it must set `'status_version' => ActionRequest::statusVersionExpression()` (a `nextval(…)` expression) in the same update array, as `ExecuteActionRequest::claimForExecution()`, `StaleApprovedRequestRedispatcher::failAsNeverStarted()` and `actions:reconcile-stuck` do; `tests/Unit/Architecture/ActionRequestStatusVersionArchTest.php` fails on one that does not. That test cannot see a builder kept in a variable, a relation builder or `newQuery()`, raw SQL, `upsert()`/`insert()`/`increment()`, or an update array built elsewhere: never change `status` that way. Never add `status_version` to `#[Fillable]`, never `saveQuietly()`/`updateQuietly()` a status change (it skips the observer), and never compute a number as `+1`. Why a version never goes backwards on one row: a builder writer's UPDATE takes its number itself, after it holds the row — a writer that waited on another's row lock re-evaluates its SET list against the committed row (Postgres EvalPlanQual under READ COMMITTED), so it lands above the number that writer committed; a number taken in PHP before the UPDATE could land below it (the claim racing a retry). The model savers hold the row lock (the reviewer, the retry) or are the only writer of the row at that point. The one unguarded save, `ExecuteActionRequest`'s final result, could only race `actions:reconcile-stuck` hours later. The column default (`nextval`) numbers an insert that bypasses Eloquent. In tests, a bare `Event::fake()` replaces the model event dispatcher and turns the observer off (a factory row then carries no number in memory and a status save is not renumbered): fake the specific events, as `ActionRequestStatusVersionTest` does, or read versions with `->fresh()`. `ActionRequestStatusVersionTest` pins the observer, the three writers (including a claim that waited behind another writer), the column default and the backfill.

## Classification outcome hooks live in ActionRequestObserver
The stuck-import outcome hook (`resolveStuckImportOutcome()`, which calls `ClassificationOutcomeRecorder::resolve()` when a classifier-queued `resolve_manual_import` or `remove_stuck_download` request is approved, rejected or auto-completed) runs in `ActionRequestObserver::updated()`, not in a separate observer, so it shares that class's after-commit timing. It is a thin call into the recorder, which never throws. `tests/Feature/Observers/ActionRequestObserverStuckImportOutcomeTest.php` pins the status-to-outcome mapping.

## Action results reach the browser through safeResult()
`ActionRequestBrowserPayload::safeResult()` is the only shape of an action's `result` that leaves the server for non-admins: `success`, `reason`, `rejection_reason` (string or null), or `null` while nothing is recorded. `ActionRequestResource` sends it to non-admins (admins get the full result: `message`, `exception`, `indeterminate`), `ActionRequestCreated` / `ActionRequestStatusChanged` broadcast it on `members.actions`, and `ActionRequestActivityLogger` stores it as the member-visible activity row's `metadata.result`. A new reader or broadcast of an action result calls the same method; never hand-pick result keys inline. `tests/Feature/Broadcasting/BroadcastEventTest.php` pins that both broadcasts equal the member resource's `result`; `tests/Feature/Observers/ActionRequestObserverTest.php` pins the activity metadata.

## Action broadcasts carry versions, not just timestamps
`ActionRequestCreated` and `ActionRequestStatusChanged` send `status_version` (the row's, see "Every action request status change bumps status_version" above), `pending_count` and `pending_version` (`PendingActionCount::snapshot()`: the count and the newest `status_version` it reflects, read in one statement); `ActionRequestResource` sends `status_version` and `nav` sends `pendingActionsVersion`. The browser orders by these, never by `updated_at` seconds: a same-second change and host clock skew misorder timestamps. A payload without a version (a broadcast built by a worker still on older code) falls back to the timestamp rule, and an equal version is a tie that applies, so keep every new reader tolerant of a missing or unchanged version. `pending_version` is ordered by allocation, not by commit: a transaction that took its number early and committed after a later one produces a second snapshot with the same `pending_version` but a different count, and whichever lands last wins the tie. That is accepted; the next change or full visit corrects it. `BroadcastEventTest` and `HandleInertiaRequestsTest` pin the keys.

## Action Queue live results merge by status
`ActionRequestStatusChanged` carries only `ActionRequestBrowserPayload::safeResult()`. `useActionQueueLive` (the Action Queue's live state) folds it into a row with `mergeLiveResult()` (`@/components/actions`): merged over the existing result while the status and the reason are unchanged (an admin keeps the `message` and `indeterminate` the page props brought), replaced when either changes, cleared by a `null` result (a retry). Every live `failed` event an admin sees, same status or not, schedules the debounced `requests` reload to fetch the full result: Echo does not replay events missed while the socket was down, so a `failed → failed` event can stand for a whole retry and a new outcome. Read failure detail only through the `components/actions` helpers: an uncertain outcome is `indeterminate: true`, or `needs_reconciliation` without an explicit `indeterminate: false` (a never-started request), and only that case gates Retry behind `useConfirm()`. `tests/Browser/ActionQueueFailureTest.php` drives the live cases through `echoEmitScript('members.actions', …, 3)`, since tests broadcast through the null driver. `useActionQueueLive` also remembers the newest live status per listed row (`liveStatuses`): `applyStatusChange()` ignores an event older than one it applied, or — when both the event and the row on screen carry a `status_version` — older than the row on screen (`compareLiveStatus()` from `@/components/actions`: the status version decides when both sides have one, because `updated_at` seconds misorder a same-second change by someone else and clock skew between hosts; without a version, a later `updated_at` second wins, a same-second pair goes by lifecycle step, and an unreadable timestamp is a tie), and every `requests` reseed passes each row through `reconcileWithLive()`, so a reload or redirect that started before a fast change arrived cannot roll the row back. A visit that brings `requests` and started after a remembered status arrived wins over it, whatever the versions say (`settleRequestsVisit()` on `success`; arrival and start share one counter): it read the database after that status was broadcast, so a lower version there means the versions went backwards (a restored database, a bogus payload), and keeping the memory would pin the row until a remount. Refresh forgets every remembered status first. When a reload that started earlier keeps a live `failed` for an admin, it schedules one more `requests` reload — once per live change (`liveChangeKey()`) — so the admin gets the message and outcome flag the broadcast never carries. The user's own approve / reject / retry / bulk review forgets the memory for those rows first, or the redirect's older row would lose to it. `ActionQueueFailureTest` pins it, with a forget case for approve, reject, retry and bulk approve. `ActionRequestCreated` is ordered the same way: the realtime list replaces a listed row only when `pushedRowIsCurrent()` says the pushed row is not older (`compareLiveStatus()`; a tie replaces), because the queued Created broadcast reads the row when it runs and can land after a newer status change. `ActionQueueLiveListTest` pins it.

## The Action Queue detail has a pane and a sheet layout
`ActionRequestDetail` is the one implementation of an action's detail, review buttons and failure/second-opinion cards:
- **`layout="pane"`** (default) is the desktop panel beside the table, `max-sm:hidden`.
- **`layout="sheet"`** renders the same markup inside `ActionRequestSheet`, the phone's full-screen `Sheet`. The card stops clipping (sticky needs a non-clipping ancestor) and the Approve/Decline and Retry rows become `sticky bottom-0` bars.

Never fork the detail for a new presentation; add a layout case.

The sheet opens on its title (`@open-auto-focus` focuses `[data-action-title]`), never on the pinned Approve, so a held Enter cannot approve and a screen reader starts at the top. It opens from a card (`openSheet`) or, on a phone, from a `?request=<id>` deep link (a request past the listed page comes as its own `preselectedRequest` prop) in `onMounted` (`openDeepLinkedSheet()`, called from the page's single `onMounted` after `subscribeLive()`: `ActionQueueLiveListTest` re-runs the page's only mounted hook, so never add a second one). On close it focuses the card and brings it back into view with `scrollIntoView({ block: 'nearest' })`, not a saved `scrollY`, because a live row can be added above it meanwhile; when the card has left the list or has no box (the viewport crossed `sm`), focus goes to the page `h1` (`focusPageHeading()` from `@/lib/bulk`). Both run from `@close-auto-focus`, default prevented. Closing also drops `?request=` from the URL with a client-side `router.replace` (no new history entry), so a reload or back/forward does not reopen a deep link. The forget is sticky (re-run on every page URL change after a close) and reviews post with `replace: true`, so a visit in flight across the close cannot bring the deep link back. The page's `visibleRequests` watcher closes the sheet before it moves the selection, and the sheet renders a held row (`shownRow`, frozen once `open` turns false) with the detail `inert` while it slides out, so a row that leaves the list (approved under the Pending tab) never turns the sheet into another action, not even during the exit animation. Live status changes for a listed row need nothing extra, because the sheet renders the same `selected` row `useActionQueueLive` orders by `status_version`. An off-page deep-linked request (`preselectedRequest`) is not in the live list: a status change for it makes the debounced `statusCounts` reload also name `preselectedRequest`, a lazy prop the server re-reads only then. `ActionQueuePhoneTest` pins it.
