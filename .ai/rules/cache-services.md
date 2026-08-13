---
paths:
  - 'app/Cache/Services/*.php'
---

# Cache Services

## Per-service caches
Add a cache by extending `BaseServiceCache` and implementing only `service()`, `connectionId()`, and `ttls()` (read from `config('mediamanager.cache.ttl')`). Read through `rememberList`/`rememberEntity`/`rememberMetadata`, and bust with `bustAll()` from the matching `*Actions` class after a write. Instantiate with `new XCache($connection)`.
