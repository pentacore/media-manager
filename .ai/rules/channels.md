---
paths:
  - 'app/Notifications/Channels/**'
---

# Channels

## Push channels: PushMessage in, deliver() throws, send() never does; destination URLs are trusted operator input
Every push channel extends `PushChannel`, declares its own `DRIVER`, and implements only `deliver(mixed $route, PushMessage $message)` (throws) and `label()`. Notifications expose one `toPush(): PushMessage`; per-channel formatting (ntfy priority/tags, Discord embed, Telegram HTML, webhook JSON + HMAC) lives in the channel. `send()` swallows and logs, so anything that must surface the failure to a user (the two test-send actions) calls `deliver()` directly and shows `PushFailureMessage::for($throwable)` — never `$throwable->getMessage()`, which for Telegram embeds the bot token in the URI. SSRF stance, decided 2026-09-05, narrowed 2026-09-27: the generic webhook channel is admin-only — only admins can configure it, test-send it, or receive through it (`PreferenceResolver` drops it for non-admins at delivery time, so a role downgrade stops delivery without a data migration). It POSTs to whatever URL the admin saved; `http://` and private hosts are allowed on purpose so self-hosted receivers (Home Assistant, n8n, local ntfy) work. Discord stays per-user but is restricted to `https://discord.com/api/webhooks/` (and `discordapp.com`) URLs. Revisit with an opt-in private-URL block only if non-admins are ever allowed arbitrary destination URLs.
