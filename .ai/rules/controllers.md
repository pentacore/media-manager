---
paths:
  - 'app/Http/Controllers/**'
---

# Controllers

## Query Eloquent directly — no repository layer
Call Eloquent models directly from controllers — do not add a repository or query-object indirection. When a query needs conditional filters, extract a `private` method on the controller returning a `Builder`, annotated `@return Builder&lt;Model&gt;`.

## Validate writes with Form Requests
Form Requests in `app/Http/Requests/&lt;Area&gt;` are the rule for validation; remaining inline `$request->validate()` sites are legacy — do not add new ones, and prefer converting to a Form Request when touching one. `Validator::make()` appears only where Fortify's contracts require it.

## Read validated input as an array named $validated
Assign `->validated()` to a local `$validated` array and read keys from it with `?? null` defaults. Do not use `->safe()`.

## Controller shape: invokable for one action, multi-method for CRUD groups
A controller serving a single screen or action is invokable (`__invoke`); CRUD-style groupings are plain multi-method controllers with explicit verb routes. Do not use `Route::resource` for new routes.

## Report action outcomes with Inertia::flash('toast')
After a write action, call `Inertia::flash('toast', ['type' => 'success'|'error'|'info', 'message' => __('...')])` before returning the redirect. Wrap the message in `__()`. Do not use `->with('success')`, `session()->flash()`, or other flash keys — a single global frontend listener renders the toast.

## Inertia for pages, JSON for XHR
Return `Inertia::render` (typed `Inertia\Response`) for anything the user navigates to; reserve `response()->json()` (typed `JsonResponse`) for fetch/XHR endpoints called from within a page. When passing a Resource to Inertia, unwrap it with `Resource::collection($paginator->getCollection())->toArray($request)` so the JSON `data` envelope never reaches props.

## Paginate database listings with paginate() and a manual props envelope
Use `->paginate($n)->withQueryString()` for Eloquent-backed index pages; do not use `simplePaginate` or `cursorPaginate`. Pass props as `['data' => ..., 'links' => $paginator->linkCollection()->toArray(), 'meta' => ['current_page','last_page','total','per_page']]` instead of handing the paginator to Inertia directly. Remote-service listings are not paginated server-side.

## Inject services as action parameters, not constructor properties
Type-hint services on the controller action itself so the container resolves them per request. Reserve constructor promotion for dependencies genuinely used by every action in the class.

## Upstream calls from controllers: base class, defer, narrow catches
Controllers for the same upstream service extend a shared abstract base (e.g. `BaseArrController`, `BazarrController`) that owns connection resolution, client construction, and failure redirects. Wrap slow upstream round-trips in `Inertia::defer()` and use the base's `tryClientCall()` inside deferred closures (you cannot redirect from one). Catch `RequestException|ConnectionException`, never `Throwable`.
