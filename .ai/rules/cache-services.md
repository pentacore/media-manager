---
paths:
  - 'app/Cache/Services/*.php'
---

# Cache Services

## Per-service caches
Add a cache scoped to one connection that uses the shared `mediamanager.cache.ttl` buckets by extending `ConnectionScopedCache` and implementing only `service()`. Extend `BaseServiceCache` directly — implementing `service()`, `connectionId()` and `ttls()` — only for a household-keyed cache (no connection) or one with its own TTLs or connection checks (`BazarrCache`). Read through `rememberList`/`rememberEntity`/`rememberMetadata`, and bust with `bustAll()` from the matching `*Actions` class after a write. Instantiate with `new XCache($connection)`.
