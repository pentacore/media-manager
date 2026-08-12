---
paths:
  - 'tests/Feature/**'
---

# Feature

## Assert Inertia pages fluently, JSON by path
Assert page responses with `->assertInertia(fn ($page) => $page->component('Dir/Page')->has(...)->where(...))` using an untyped `$page` parameter. For JSON endpoints use `assertJsonPath` or `expect($response->json('key'))` — not `assertJson`/`assertJsonFragment`/`AssertableJson`. Prefer named status helpers (`assertOk`, `assertForbidden`, `assertRedirect`) over `assertStatus`, and `assertSessionHasErrors` over `assertInvalid`. Build URLs with `route()`.
