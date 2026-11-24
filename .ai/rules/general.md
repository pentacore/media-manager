---
paths:
  - '**'
---

# General

## Run `composer test` before every commit
`vendor/bin/sail composer test` is the full local gate: Pint, the Rector dry-run, Larastan, Vite+ `pnpm run check` (the Oxfmt format check and Oxlint) and vue-tsc, then the Unit and Feature Pest suites in parallel (they include the Typefinder drift check in `GeneratedTypesTest`). It runs what CI's lint, static analysis and Pest jobs run, so run it before every commit and fix every failure it reports instead of leaving it to CI. The browser suite is not part of it; run `vendor/bin/sail composer test:browser` (see `.ai/rules/browser.md`) when a change touches pages, components or browser tests.

## Change a behaviour's description in the same commit
When a change alters behaviour, update every description of it in the same commit. That means the README, `.ai` rules and guidelines, admin settings help text, AI chat tool descriptions and agent instructions (the models read these, so a stale one changes what they do), and the conventional commit subject, which becomes the CHANGELOG entry. Treat a stale description as a review finding.

## Ask Boost before guessing
Use the Laravel Boost MCP tools instead of guessing or grepping `vendor/`. Run `search-docs` before writing Laravel, Inertia, Pest or Tailwind code whose API you are not sure of, `database-schema` before writing a migration or query, `database-query` to read data, and `last-error` or `read-log-entries` before debugging from guesses.

## Make limits admin settings
A limit, window, threshold, timeout or count that an admin could reasonably want to tune is an admin setting with a sensible default, validated and clamped where it is read. It is not a hard-coded constant. (owner's rule, 2026-11-23)

## Put every follow-up in ROADMAP.md
Every review follow-up, deferred minor and bug found in passing goes into `ROADMAP.md` in the same pull request that finds it, under the group that matches what it needs (a decision, a brainstorm, or nothing). Remove an item in the pull request that finishes it. Do not leave follow-ups only in a PR body, a TODO comment or a session's notes. (owner's rule, 2026-11-23)

## Screenshot every UI change
A pull request that changes what a page looks like gets before and after screenshots in its body. Use fake data only: the repository is public, so no real titles, users, hosts or keys. Host the images on the orphan `pr-assets` branch under `pr-assets/<PR number>/` and link them from there. Do not commit them to the feature branch. (owner's rule, 2026-11-23)

## Finish a branch before opening its pull request
Before opening a pull request, rebase the branch on `origin/main`. Then refresh the timings in `tests/.pest/shards.json` for every browser test class the branch adds or changes, so CI's shards stay balanced. (owner's rule, 2026-11-23)

## Give parallel work its own worktree and test database
Work that runs alongside other work gets its own git worktree under `.worktrees/<name>` and its own test database `testing_<name>`, passed as `DB_DATABASE`. Never share the main checkout or another worktree's database. Run Sail from the main checkout, and drop the worktree's databases when you remove it. (owner's rule, 2026-11-23)

## Know the Sail quirks
- Paratest takes one path per run. Use `--testsuite=Feature,Unit` for the whole non-browser suite.
- Regenerate Wayfinder with `php artisan wayfinder:generate --with-form`, because the Vite plugin uses `formVariants`. Without the flag, `types:check` loses the `.form()` typings.
- A fresh `pnpm install` (after deleting `node_modules`, or a Playwright upgrade) loses the Playwright browser inside `node_modules`, so run `pnpm exec playwright install chromium` after it.

(owner's rule, 2026-11-23)

## Keep design specs and plans out of the repository
`docs/superpowers/` holds local design specs and implementation plans. It is gitignored and never committed, not even with `git add -f`. Durable decisions go in the code, the `.ai` rules or `ROADMAP.md` instead. (owner's rule, 2026-11-23)
