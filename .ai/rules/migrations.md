---
paths:
  - 'database/migrations/*.php'
---

# Migrations

## Declare foreign keys with foreignId()->constrained() and an explicit delete rule
Define foreign keys as `$blueprint->foreignId('x_id')->constrained()`, passing the table name explicitly when the column does not derive it. Always chain an explicit delete rule — `cascadeOnDelete` for owned child rows, `nullOnDelete` for nullable FKs, `restrictOnDelete` for audit rows that must not vanish — never leave the default. The closure parameter is named `$blueprint`, not `$table`.

## Write a real down() for every migration
Implement `down()` as a genuine inverse of `up()`, including data migrations. Drop indexes and foreign keys before the columns they cover, and restore prior column definitions rather than leaving the migration one-way. This is a PostgreSQL app — use `DB::statement` for partial/expression indexes the builder cannot express.

## Store enums as string columns cast to backed PHP enums
Never use `$blueprint->enum()`; declare the column as `->string()` with a matching `->default()` and back it with a string-backed enum in `app/Enums`, registered in the model's `casts()` method.

## Timestamps: nullable *_at columns, no soft deletes
Call `$blueprint->timestamps()` on every application table and name extra time columns `*_at` as nullable `->timestamp()`. Do not use `softDeletes()` — deletion is hard, with FK cascade/restrict rules carrying the semantics. Cast datetime columns as `immutable_datetime` on the model.
