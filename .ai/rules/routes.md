---
paths:
  - 'routes/*.php'
---

# Routes

## Route to controllers, never to closures
Every HTTP route resolves to a controller class — `[Controller::class, 'method']` or an invokable `Controller::class`. Use `Route::inertia()` for static pages instead of a closure returning a view. Route files are split one-per-domain, each wrapped in a single top-level middleware group.

## Assign middleware in route groups, not on controllers
Declare middleware with `Route::middleware()->group()` in the route file, escalating privileges via nested groups. Do not implement `HasMiddleware` or use the `#[Middleware]` attribute on controllers. Reference app middleware by its `bootstrap/app.php` alias.

## Bind local models implicitly; constrain scalar parameters
Name route parameters after the model in camelCase so implicit binding resolves them, and type-hint the model in the action. Reserve scalar `{id}` parameters for identifiers owned by an upstream service (Sonarr, Radarr, …), and constrain every scalar parameter with `->whereNumber()`, `->whereUuid()`, or `->whereIn()`.
