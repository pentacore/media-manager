---
paths:
  - 'app/Notifications/**'
---

# Notifications

## Notifications
Resolve channels in `via()` through `PreferenceResolver::channelsFor()` instead of hardcoding them, and give every notification `toArray()`, `toBroadcast()`, and `toPush(): PushMessage` (channel-neutral; ntfy/Discord/Telegram/webhook formatting lives in the `PushChannel` subclasses). Custom channels extend `PushChannel`: implement `deliver()` (throws) and `label()`; `send()` swallows and logs delivery failures, never throws.
