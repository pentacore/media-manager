---
paths:
  - 'app/Notifications/**'
---

# Notifications

## Notifications
Resolve channels in `via()` through `PreferenceResolver::channelsFor()` instead of hardcoding them, and give every notification `toArray()`, `toBroadcast()`, and `toPush(): PushMessage` (channel-neutral; ntfy/Discord/Telegram/webhook formatting lives in the `PushChannel` subclasses). Custom channels extend `PushChannel`: implement `deliver()` (throws) and `label()`; `send()` swallows and logs delivery failures, never throws.

## A notification about one action request links to it
When a notification concerns exactly one `ActionRequest`, its link (`toArray()`'s `link`/`url` and `toPush()`'s `url`) is `route('actions.requests.index', ['request' => $id])`, so it opens that request: in the full-screen sheet on a phone, selected in the pane on a desktop. A notification about several requests, or none, links to the bare queue. `DecisionAgentActed` takes `actionRequestId` only when the run queued exactly one (`DecisionRunContext::queuedActionRequestIds()`). `DecisionAgentActedTest`, `ImportedSubtitleAuditorTest` and `CompetingGrabSweeperTest` pin it.
