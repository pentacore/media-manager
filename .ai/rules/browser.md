---
paths:
  - 'tests/Browser/**'
---

# Browser

## Browser tests: actingAs, visit, assertNoSmoke
Authenticate with `$this->actingAs(User::factory()->{role}()->create())` before `visit()`, resolve paths via `route($name, absolute: false)`, and open every flow with `->assertNoSmoke()`. Scope element assertions to `data-*` attribute selectors (`assertSeeIn('[data-x]', ...)`) so sidebar/layout text cannot satisfy them. Register every new page route in `SmokeTest`'s member/admin route-name datasets.
