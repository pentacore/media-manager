---
paths:
  - '**'
---

# General

## Run `composer test` before every commit
`vendor/bin/sail composer test` is the full local gate: Pint, the Rector dry-run, Larastan, ESLint, Prettier and vue-tsc, then the Unit and Feature Pest suites in parallel (they include the Typefinder drift check in `GeneratedTypesTest`). It runs what CI's lint, static analysis and Pest jobs run, so run it before every commit and fix every failure it reports instead of leaving it to CI. The browser suite is not part of it; run `vendor/bin/sail composer test:browser` (see `.ai/rules/browser.md`) when a change touches pages, components or browser tests.

## Change a behaviour's description in the same commit
When a change alters behaviour, update every description of it in the same commit. That means the README, `.ai` rules and guidelines, admin settings help text, AI chat tool descriptions and agent instructions (the models read these, so a stale one changes what they do), and the conventional commit subject, which becomes the CHANGELOG entry. Treat a stale description as a review finding.

## Ask Boost before guessing
Use the Laravel Boost MCP tools instead of guessing or grepping `vendor/`. Run `search-docs` before writing Laravel, Inertia, Pest or Tailwind code whose API you are not sure of, `database-schema` before writing a migration or query, `database-query` to read data, and `last-error` or `read-log-entries` before debugging from guesses.
