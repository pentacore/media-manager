---
paths:
  - 'app/Providers/*.php'
---

# Providers

## Octane-safe bindings
Bind any service holding per-request mutable state with `$this->app->scoped()`, not `singleton()`, and add a comment naming the state that would otherwise leak between Octane requests. Reserve `singleton()` for stateless services; use `extend()` to decorate SDK bindings.
