---
paths:
  - 'app/Services/**'
---

# Services

## Query Eloquent directly — no repository layer
Call Eloquent models directly from controllers, services, jobs, and listeners — do not add a repository or query-object indirection. When a query needs conditional filters, extract a `private` method on the calling class returning a `Builder`, annotated `@return Builder&lt;Model&gt;`. `*Repository` classes are reserved for raw `DB::table()` read layers over aggregate/rollup tables.

## Service layer shape
Group services in a per-integration subfolder and name by role: `*Client` for upstream HTTP, `*Actions` for `ActionExecutor` implementations, `*WebhookHandler` for inbound webhooks, plus domain-named single-purpose collaborators (`*Fingerprint`, `*Ranker`, `*Resolver`, …). Name the entry method after the role — `execute()` for executors, `handle()` for webhook handlers, a domain verb otherwise; `__invoke()` is reserved for controllers.

## Upstream HTTP clients
Build every upstream request with the `Http` facade through a `buildClient()` helper setting base URL, auth header, 10s timeout, 3s connect timeout, the MediaManager user agent, and `retry(..., throw: false)`. Read credentials from the injected `ServiceConnection` or `config('services.*')`, never `env()`. No Saloon, no raw Guzzle. Clients lazily hold their `*Cache` sibling and leave writes uncached — the matching `*Actions` class busts.

## DTOs are hand-rolled readonly value objects
Model the outcome of a multi-step process as a `final readonly` class with promoted public properties, named-argument construction, and a documented `toArray()` — there is no DTO package. Keep upstream API responses as plain arrays annotated with an `array{...}` docblock shape.
