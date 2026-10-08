---
paths:
  - 'app/Http/**'
---

# Http

## Validate enum-backed fields with EnumUtils::validationRule()
For any field constrained to an enum, use `SomeEnum::validationRule()` (from the EnumUtils concern). Do not use `Rule::enum()`, `new Enum(...)`, or a hand-written `in:a,b,c` string.

## Redirect with to_route() or back(), never literal paths
Use `to_route('name')` to send the user elsewhere and `back()` to return to the submitting page; never `redirect()->route()`, `url('/path')`, or `action([])`. Generate URLs for props and notifications with `route('name')`.

## Iterate generator streams yourself under Octane FrankenPHP
With `$_SERVER['LARAVEL_OCTANE']` set, `response()->stream()` keeps a generator callback as-is and expects the server to iterate it; Octane's FrankenPHP client only calls `send()`, so the body is silently empty in production while `artisan serve` works. Any response built from a generator (including laravel/ai protocol streams) must be wrapped so the callback's returned Generator is iterated with echo + `ob_flush()` + `flush()` (see `ChatStreamProtocol::writeFrames()`). Cover it with a feature test that sets `LARAVEL_OCTANE=1`.

## Chat streams stop at the next step when the client leaves
`ChatStreamProtocol` keeps `ignore_user_abort(true)` (a disconnect must never kill the request mid-billing) and calls `ClientConnection::watch()`; `StopWhenClientDisconnected` (MediaAgent and structured sub-agents) then throws `ClientDisconnectedException` before the next step once `ClientConnection::disconnected()` (a `flush()` poll, then `connection_aborted()`) reports the client gone. The SDK turns that into AgentFailed, so `RecordFailedAgentRun` bills every completed step. Never stop a stream by breaking out of the frame loop: abandoning the generator skips AgentFailed and loses the usage row.

## Expensive shared props are closures
`HandleInertiaRequests::share()` wraps `integrations`, `nav` and `version` in closures, which Inertia resolves only for a response that includes the key: full page visits and prefetches, never the partial reloads that deferred groups, polling and realtime reloads make. Share any new prop that queries the database, reads a cache or calls a service the same way; keep cheap scalars (`name`, `auth`, `ai`, `sidebarOpen`) eager. Build the closures inside `share()` on every request, capturing only that request's user — never in a static, a singleton or a cross-request cache (Octane). `integrations` is one `whereIn('type', …)->pluck('type')` query, deliberately uncached so a connection change shows on the next navigation. A partial reload keeps the client's previous value, so a frontend consumer that seeds local state from a shared prop (`useNavCounts`) must `watch()` the prop's reference rather than re-run on every page update. `tests/Feature/HandleInertiaRequestsTest.php` pins the laziness with a query log.
