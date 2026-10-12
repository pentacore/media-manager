---
paths:
  - 'resources/js/composables/**'
---

# Composables

## Composables
Name the file and its named export `useX`; export a `function`, never a default or arrow const. Declare and export an explicit `UseXReturn` type for the returned object. Composables owning an Echo/Reverb channel expose `subscribe`/`unsubscribe` and self-release from `onUnmounted`.

## Live rows carry per-reader fields through map
`useRealtimeList({ channel, event, keyField, map })` takes one channel or a list (every channel feeds one list, deduplicated by `keyField`) and an optional `map(payload) => row`. A broadcast goes to every reader, so it can never carry a per-reader field the page's resource computes per request (`can_toggle_played`): broadcast the fact the rule needs (`EmbyPlaybackUpdated.user_id`, the Emby link's owner) and derive the field in `map` with the same rule as the model (`EmbyActivity::playedStateEditableBy()`), leaving the server to re-check on submit. Without `map`, a pushed row that replaces a server row silently drops the field.
