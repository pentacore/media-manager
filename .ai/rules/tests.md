---
paths:
  - 'tests/**'
---

# Tests

## Use test() with a sentence name
Declare tests with `test('describes the behaviour in a sentence', function (): void { ... })`; do not use `it()` or `describe()`. Use `expect()` for non-HTTP assertions, inline `->with([...])` datasets (named datasets only for generated/shared lists), and file-local prefixed helper `function`s rather than adding helpers to Pest.php. Start every test file with `declare(strict_types=1);`.

## RefreshDatabase comes from Pest.php, not the test file
Feature and Browser tests inherit `RefreshDatabase` and the shared `beforeEach` from `tests/Pest.php` — never add `uses(RefreshDatabase::class)` there. Keep `tests/Unit` free of the framework; if a unit test needs the container add `uses(TestCase::class)`, adding `RefreshDatabase` alongside only when it truly hits the DB.

## Arrange with factories and named states
Build test data with `Model::factory()` and its named states (`->admin()`, `->member()`, `->sonarr()`, `->bazarr()`, …) rather than manual `create()` calls or `DB::table()` inserts. Authenticate with `$this->actingAs(User::factory()->{role}()->create())`. Call `$this->seed()` only for the config-table seeders (`ActionTypeConfigSeeder`, `ServiceConnectionSeeder`) a test genuinely depends on.

## Double external services at the HTTP layer, not the class
Exercise the real service client against `Http::fake(['&lt;service&gt;.local:&lt;port&gt;/path' => Http::response([...])])` keyed by URL pattern, and call `Http::preventStrayRequests()` first in `beforeEach`. Create the backing `ServiceConnection` with its factory state and the conventional fake host (`http://sonarr.local:8989`, `http://radarr.local:7878`, `http://emby.local:8096`, `http://bazarr.local:6767`, `http://seerr.local:5055`, …); verify outbound calls with `Http::assertSent`/`assertSentCount`/`assertNothingSent`.

## Reserve Mockery for internal collaborators
Use `Mockery::mock()`/`$this->mock()` plus `app()->instance()` only for in-app services (executors, indexers, guards, handlers) — never for the HTTP service clients. Fake AI agents with `&lt;Agent&gt;::fake([...])`; an unfaked agent fails loudly because phpunit.xml sets dummy API keys. Prefer selective `Event::fake([...])` over global fakes.
