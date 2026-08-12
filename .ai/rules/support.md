---
paths:
  - 'app/Support/**'
---

# Support

## Support is cross-cutting glue only
Reserve `app/Support` for integration-free helpers: `final` static utility classes with `public const` keys, or small injectable infrastructure services. Keep upstream-API and domain logic in `app/Services`.
