---
name: running-browser-tests
description: "Use when running one or more Pest browser tests (tests/Browser) locally, including a single file, a filtered run or a shard timing refresh."
---
@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp
# Running Browser Tests

Browser tests share one Sail container with every other session, so run them serially and under the shared lock.

1. Build the frontend first so SSR serves the current bundle: `{{ $assist->nodePackageManagerCommand('run build') }}`.
2. If `public/hot` exists (a Vite dev server is running), move it out of `public/` for the run and put it back afterwards. Otherwise SSR goes through the dev server and every test fails with "module is not defined".
3. Run through the SSR wrapper, wrapped in the shared lock. Use `flock -o`, never plain `flock`: a leaked Playwright server would inherit the lock and deadlock later runs.
   - One file: `flock -o /tmp/mm-browser-tests.lock composer test:browser -- tests/Browser/SomeTest.php`
   - Filtered: `flock -o /tmp/mm-browser-tests.lock composer test:browser -- --filter=Calendar`
   - Whole suite with a timing refresh: `flock -o /tmp/mm-browser-tests.lock composer test:browser -- --update-shards`. Run it serially, never with `--parallel`, and commit `tests/.pest/shards.json` only after a fully green run.
4. In a worktree, pass the worktree's own test database (`-e DB_DATABASE=testing_<name>`). Set `REAP_ORPHANED_PLAYWRIGHT=0` so your run does not reap other sessions' Playwright servers.
5. After a Playwright upgrade or an `npm ci`, run `npx playwright install chromium` again. The browser binary lives in `node_modules`.

When a CI shard fails, download its screenshots with `gh run download <run-id> -n browser-screenshots`.
