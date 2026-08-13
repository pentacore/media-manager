---
paths:
  - 'app/Models/*.php'
---

# Models

## Declare model metadata with class attributes, not properties
Put an explicit `#[Fillable([...])]` attribute on every model listing each writable column; never use `$fillable` or `$guarded` properties. Use the matching attributes `#[Hidden]`, `#[Appends]`, `#[Table]`, `#[WithoutTimestamps]`, `#[WithoutIncrementing]` instead of their property forms. Only `$primaryKey`/`$keyType` remain properties (no attribute form exists).

## Keep the generated model PHPDoc block current
Every model carries an ide-helper `@property` / `@property-read` / `@method static` block above its class attributes — regenerate it after changing columns or relations. Type date columns as `CarbonImmutable` and enum columns as the enum class. Give every relation method a native return type plus a `@return HasMany&lt;Related, $this&gt;`-style generic docblock, and precede `use HasFactory;` with `/** @use HasFactory&lt;ModelFactory&gt; */` when a factory exists.

## Cast with built-in strings and enum classes
Use Laravel's built-in cast strings and backed-enum `::class` casts in `casts()`; do not write `CastsAttributes` classes. Cast secrets with `'encrypted'`. Reach for a `#[TypefinderOverrides]` attribute only to correct a generated TypeScript shape.

## Wire observers with #[ObservedBy]
Register model observers with an `#[ObservedBy(SomeObserver::class)]` attribute on the model; never call `Model::observe()` or override `booted()`.

## Eager load per query, never model-level $with
Never add a model-level `$with`; eager load at the call site with `->with()`. When the relation only feeds a serializer or Inertia payload, scope it to needed columns (`'user:id,name'`). Strict mode / preventLazyLoading is deliberately off, so explicitly load every relation you intend to touch.

## Prune high-volume tables from config
Give append-only tables `MassPrunable` and a `prunable()` method that reads its window from `config('mediamanager.retention.*_days')`. Treat `0` as "pruning disabled" by returning `whereRaw('1 = 0')`, never an unbounded delete.
