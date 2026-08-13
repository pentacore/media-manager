---
paths:
  - 'database/factories/*.php'
---

# Factories

## Expose factory variants as named state methods
Give each factory a `/** @extends Factory&lt;Model&gt; */` docblock and a `@return array&lt;string, mixed&gt;` on `definition()`. Express variants as `public function name(): static` returning `$this->state(fn (array $attributes): array => [...])` — never inline `->state()` at use sites — and assign enum cases rather than raw strings.
