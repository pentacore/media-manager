---
paths:
  - 'app/Http/Controllers/Admin/NotificationDestinationController.php, app/Http/Controllers/Settings/NotificationPreferencesController.php'
---

# Controllers Settings

## Encrypted destination fields: blank on update means keep, never echo them to props
Discord webhook URLs and webhook signing secrets (user columns and `NotificationDestination::config`) are encrypted at rest and never sent to Inertia props in full — the pages get a `…last4` hint or a `*_set` boolean, and their inputs start blank. On save, a key absent from the payload keeps the stored value, an empty string clears it (user page), and on the admin `update()` a blank encrypted field keeps the stored value only when the row's channel is unchanged (a channel change with a blank Discord URL is rejected). Plain fields (ntfy topic, Telegram chat id, generic webhook URL) are shown to their owner and always written. Admin fan-out goes through `AdminNotifier::send()` (users + enabled destinations in one `Notification::send`), never an inline admin query.
