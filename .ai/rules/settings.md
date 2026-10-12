---
paths:
  - 'app/Settings/*.php'
---

# Settings

## Application settings
Model settings as a typed class wrapping the injected `AppSettings` store, with `*_KEY` constants and paired typed getter/setter methods. Always fall back to a `config('mediamanager.*')` default when nothing is persisted, and treat `null` on the setter as "clear back to config".

## Forms edit the configured value and show the effective one
When a getter clamps a setting at runtime (`AiSettings::chatTimeout()` caps the chat timeout below Octane's `max_execution_time`), add a `configured*()` getter that returns the saved value or the config default, unclamped. The admin form binds the configured value and receives the clamped one beside it (`chat_timeout` / `chat_timeout_effective`), showing a hint only when they differ, so saving an untouched form never persists the clamp. Runtime code keeps calling the clamped getter.
