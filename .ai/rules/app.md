---
paths:
  - 'app/**'
---

# App

## Authorize with the role middleware, not Gates or Policies
Do not create Policy classes or `Gate::define` definitions. Authorization is the `UserRole` enum hierarchy enforced by the `role:admin|member|viewer` middleware on route groups; use `User::isAdmin()`/`isMember()` or `UserRole::isAtLeast()` for in-code checks, and `abort_if`/`abort_unless` with a bare status code for per-record ownership or state guards.

## Dependency acquisition
Inject collaborators through the constructor as `private readonly` promoted properties; fall back to `resolve()` only where the framework constructs the class for you (AI tools/agents, `Notification::via()`, static helpers, dynamic `match` tables). Construct `ServiceConnection`-scoped `*Client` and `*Cache` objects with `new` — they are never container-resolved.

## Facade vs helper idiom
Read configuration with the `config()` helper and resolve from the container with `resolve()` — never the `Config` facade or `app()`. Use facades (`Log`, `DB`, `Cache`, `Http`, `Notification`) for other framework services, and prefer the `event()`/`dispatch()`/`to_route()`/`response()` helpers. In controllers use `$request->user()` over `Auth::user()`.

## Namespace layout
Add a new integration as a subfolder named after it under `app/Services`, mirroring the same name under `app/Ai/Tools`, `app/Http/Controllers`, and `app/Http/Requests` as needed. Declare interfaces beside their implementations (no `Contracts` folder) and co-locate custom exceptions with the domain that throws them (all `extend RuntimeException`). No `app/Domain`, `app/Modules`, `app/DTOs`, or `app/Traits`.

## Use DB::transaction() closures, never manual transaction control
Wrap multi-statement writes in `DB::transaction(function (): T { ... })` with an explicit closure return type; never call `beginTransaction`/`commit`/`rollBack`. Guard contended rows with `lockForUpdate()` inside the closure and defer events and job dispatch past commit with `DB::afterCommit()` or `->afterCommit()`.

## Idempotent writes for externally-driven data
Persist data derived from webhooks, syncs, or retryable jobs with `updateOrCreate()` or `firstOrCreate()` keyed on a natural key, not a find-then-save sequence. Back the lookup key with a unique index in a migration.

## Iteration idiom
Chain `->map()`/`->filter()` directly on Eloquent results. For plain PHP arrays use native `array_map`/`array_filter` with a typed (usually `static`) arrow function, wrapping in `collect()` only when the transformation needs a multi-step pipeline or a Collection-only method. Use `foreach` with an accumulator and `continue` guards when normalizing external payloads.

## String manipulation
Use static `Str::` calls for a single operation and `Str::of()` only when chaining two or more transforms, ending with `->toString()` or a `(string)` cast. Compose interpolated strings with `sprintf()` rather than `.` concatenation.

## Dates are immutable
`Date::use(CarbonImmutable::class)` is registered globally — type-hint and annotate `CarbonImmutable`, never `Carbon`. Use the `now()`/`today()` helpers for current time, and `CarbonImmutable::parse()`/`::createFromTimestamp()` only when building a date from external input.
