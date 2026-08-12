---
paths:
  - 'app/Console/Commands/**'
---

# Commands

## Artisan commands
Use `namespace:verb-noun` signatures (`services:check-health`), return `self::SUCCESS` from `handle(): int`, and keep the body thin — inject collaborators into `handle()` and delegate to a service or dispatch a job. Register schedules in `routes/console.php` using the command class-string, not the signature string.
