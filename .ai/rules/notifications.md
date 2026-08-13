---
paths:
  - 'app/Notifications/**'
---

# Notifications

## Notifications
Resolve channels in `via()` through `PreferenceResolver::channelsFor()` instead of hardcoding them, and give every notification `toArray()`, `toBroadcast()`, and `toNtfy()`. Custom channels must swallow and log delivery failures, never throw.
