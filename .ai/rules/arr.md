---
paths:
  - 'app/Services/Arr/**'
---

# Arr

## Pooled bulk reads make one attempt
`ArrClient::fetchResourcesByIds()` (Sonarr `fetchSeriesByIds()`, Radarr `fetchMoviesByIds()`) deliberately skips `buildClient()`'s retry: a retry multiplies page-load wait for a hung host. Unreadable items come back null and callers fall back per item; it stops after a batch of ten that failed entirely on transport (every request a ConnectionException). Do not add retry.
