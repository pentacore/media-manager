---
paths:
  - 'app/Http/Resources/**'
---

# Resources

## Resources: declared TS shape, guarded relations, flattened data
Every JsonResource carries `#[TypefinderResource(shape: [...])]` listing each `toArray()` key with its TypeScript type, plus a `@mixin` docblock for the backing model. Wrap any relationship-derived field in `$this->whenLoaded('relation', fn () => $this->relation?->field)` and flatten related data to scalars or an inline array — never nest another Resource.
