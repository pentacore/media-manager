---
paths:
  - '**'
---

# General

## Run `composer test` before every commit
`vendor/bin/sail composer test` is the full local gate: Pint, the Rector dry-run, Larastan, ESLint, Prettier and vue-tsc, then the Unit and Feature Pest suites in parallel (they include the Typefinder drift check in `GeneratedTypesTest`). It runs what CI's lint, static analysis and Pest jobs run, so run it before every commit and fix every failure it reports instead of leaving it to CI. The browser suite is not part of it; run `vendor/bin/sail composer test:browser` (see `.ai/rules/browser.md`) when a change touches pages, components or browser tests.
