---
paths:
  - 'database/seeders/*.php'
---

# Seeders

## Seeders are idempotent and environment-gated
Write seeders so re-running is safe: `updateOrCreate`/`firstOrCreate` keyed on a natural key. Register them via `$this->call()` in `DatabaseSeeder` and gate demo or test data behind `app()->environment()`.
