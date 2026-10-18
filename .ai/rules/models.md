---
paths:
  - 'app/Models/*.php'
---

# Models

## Declare model metadata with class attributes, not properties
Put an explicit `#[Fillable([...])]` attribute on every model listing each writable column; never use `$fillable` or `$guarded` properties. Use the matching attributes `#[Hidden]`, `#[Appends]`, `#[Table]`, `#[WithoutTimestamps]`, `#[WithoutIncrementing]` instead of their property forms. Only `$primaryKey`/`$keyType` remain properties (no attribute form exists).

## Keep the generated model PHPDoc block current
Every model carries an ide-helper `@property` / `@property-read` / `@method static` block above its class attributes — regenerate it after changing columns or relations. Type date columns as `CarbonImmutable` and enum columns as the enum class. Give every relation method a native return type plus a `@return HasMany&lt;Related, $this&gt;`-style generic docblock, and precede `use HasFactory;` with `/** @use HasFactory&lt;ModelFactory&gt; */` when a factory exists.

## Cast with built-in strings and enum classes
Use Laravel's built-in cast strings and backed-enum `::class` casts in `casts()`; do not write `CastsAttributes` classes. Cast secrets with `'encrypted'`. Reach for a `#[TypefinderOverrides]` attribute only to correct a generated TypeScript shape.

## Wire observers with #[ObservedBy]
Register model observers with an `#[ObservedBy(SomeObserver::class)]` attribute on the model; never call `Model::observe()` or override `booted()`.

## Eager load per query, never model-level $with
Never add a model-level `$with`; eager load at the call site with `->with()`. When the relation only feeds a serializer or Inertia payload, scope it to needed columns (`'user:id,name'`). Strict mode / preventLazyLoading is deliberately off, so explicitly load every relation you intend to touch.

## Prune high-volume tables from config
Give append-only tables `MassPrunable` and a `prunable()` method that reads its window from `config('mediamanager.retention.*_days')`. Treat `0` as "pruning disabled" by returning `whereRaw('1 = 0')`, never an unbounded delete.

Prune only terminal rows, and keep a row while another row's `nullOnDelete`/`cascadeOnDelete` FK still points at it (use `whereNotExists`, never `whereNotIn`, over nullable FK columns). When child rows `restrictOnDelete`, use `Prunable` (not `MassPrunable`) and delete the children in `pruning()` inside a `DB::transaction()` `prune()` override (see `SubtitleCase`).

## Every action request status change bumps status_version
`action_requests.status_version` orders status changes for the browser (a same-second change and host clock skew misorder `updated_at`). Every write that changes `status` must take a new number from the `action_request_status_versions` sequence. A model create or save gets it from `ActionRequestStatusVersionObserver` (`creating`, and `updating` when `status` is dirty, through `ActionRequest::nextStatusVersion()`), so it is written in the same statement and the model carries it. A query-builder update skips every observer, so it must set `'status_version' => ActionRequest::statusVersionExpression()` (a `nextval(…)` expression) in the same update array, as `ExecuteActionRequest::claimForExecution()`, `StaleApprovedRequestRedispatcher::failAsNeverStarted()` and `actions:reconcile-stuck` do; `tests/Unit/Architecture/ActionRequestStatusVersionArchTest.php` fails on one that does not. That test cannot see a builder kept in a variable, a relation builder or `newQuery()`, raw SQL, `upsert()`/`insert()`/`increment()`, or an update array built elsewhere: never change `status` that way. Never add `status_version` to `#[Fillable]`, never `saveQuietly()`/`updateQuietly()` a status change (it skips the observer), and never compute a number as `+1`. Why a version never goes backwards on one row: a builder writer's UPDATE takes its number itself, after it holds the row — a writer that waited on another's row lock re-evaluates its SET list against the committed row (Postgres EvalPlanQual under READ COMMITTED), so it lands above the number that writer committed; a number taken in PHP before the UPDATE could land below it (the claim racing a retry). The model savers hold the row lock (the reviewer, the retry) or are the only writer of the row at that point. The one unguarded save, `ExecuteActionRequest`'s final result, could only race `actions:reconcile-stuck` hours later. The column default (`nextval`) numbers an insert that bypasses Eloquent. In tests, a bare `Event::fake()` replaces the model event dispatcher and turns the observer off (a factory row then carries no number in memory and a status save is not renumbered): fake the specific events, as `ActionRequestStatusVersionTest` does, or read versions with `->fresh()`. `ActionRequestStatusVersionTest` pins the observer, the three writers (including a claim that waited behind another writer), the column default and the backfill.
