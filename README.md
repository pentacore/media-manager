# MediaManager

A unified web UI for a self-hosted media stack — one dashboard for Sonarr, Radarr, Whisparr, Emby, Seerr, Prowlarr, SABnzbd, and Bazarr, with cross-service orchestration, real-time updates, and an approval workflow for destructive actions.

MediaManager ingests webhooks from every service, normalises them into an activity feed, and dispatches follow-up actions across services (e.g. deleting a series in Emby can cue a matching delete in Sonarr, gated by approval). It ships with browse/add/remove UIs for TV and movies, a Seerr request console, a seasonal-anime browser, a Subtitle Center backed by Bazarr, an SABnzbd download queue, Emby Now Playing + watch history, a live service-health board, a statistics dashboard, per-user notifications (including ntfy push), and an optional multi-agent AI layer that drives the same approval pipeline — from a chat assistant to autonomous subtitle repair and media-file replacement.

## Features

### Per-service

- **Sonarr** — browse, add, and delete TV series; see watch/download activity
- **Radarr** — browse, add, and delete movies; same activity feed
- **Whisparr** — adult content management (v2 *Eros* and v3 supported) via the AI assistant and action pipeline; no dedicated browse UI
- **Emby** — Now Playing widget, per-user watch history, Emby-to-local user linking
- **Seerr** — list media requests, approve / decline / retry / delete (admin only for the last two)
- **Prowlarr** — indexer search from the unified Search page, per-indexer test + stats on the connection admin page
- **SABnzbd** — live download queue ("Downloads" page): pause/resume globally or per item, delete, reprioritise; history polling and sidebar badges
- **Bazarr** — full **Subtitle Center** (Overview / Missing / Library / History / Escalations tabs) plus opt-in automated subtitle repair (see below)
- **Seasonal Anime** — browse a broadcast season from AniList or Jikan/MAL, overlaid with owned/requested state, and request titles through Seerr (id mapping via the Fribb anime-lists dataset, with user-confirmable fuzzy matches)

### Cross-cutting

- **Webhook ingest** — token-authenticated endpoints for every service, payload stored as `WebhookEvent` and processed asynchronously
- **Action pipeline** — `ActionRequest` state machine (pending → approved → executing → completed/failed) with per-type approval rules (24 seeded action types)
- **Cross-service orchestration** — Emby delete cascades to Sonarr/Radarr; *arr downloads/deletes trigger Emby library scans; Seerr "media available" refreshes Emby
- **Media replacement** — grab-before-delete replacement of an already-imported episode/movie with a better release, verified end-to-end via webhooks (see [Media replacement](#media-replacement--subtitle-automation))
- **Subtitle automation** — Bazarr-driven subtitle cases with grace periods, probes, and AI-advisor escalation
- **Real-time dashboard** — Reverb + Echo private channels for service health, Emby playback, action requests, downloads, subtitles, and dashboard stats
- **Notifications** — per-user, per-severity channel matrix (in-app, broadcast, mail, ntfy push) with a notification inbox
- **Statistics** — pre-aggregated rollups powering a user statistics page (plays, watch hours, downloads, request funnel, leaderboards) and an admin operations view (webhooks, actions, queue depth, disk, AI cost); optional Prometheus scrape endpoint
- **Scheduled monitoring** — health pings every 5 minutes; daily upstream GitHub release checks for "update available" badges; nightly retention pruning
- **Authentication** — local email/password via Fortify (with 2FA), optional Authentik SSO, optional Emby-credential login, plus an invite flow with password set on first visit
- **Role model** — `admin` / `member` / `viewer` with hierarchical checks (`isAtLeast`)
- **Optional AI layer** — chat assistant, autonomous per-webhook decision agent, subtitle advisor, and price-verification agent, all routed through the same approval pipeline, with cost accounting, budgets, and a maintained model-price catalog

## Requirements

- Docker + Docker Compose (Sail handles PHP 8.5, Postgres 18, Valkey, Mailpit, Typesense, Reverb)
- Any combination of the external services you want to manage: Sonarr, Radarr, Whisparr, Emby, Seerr, Prowlarr, SABnzbd, Bazarr (none are required to boot)
- Optional: an Authentik instance for SSO
- Optional: an API key for at least one AI provider (OpenAI is the default; Anthropic, Gemini, Groq, Mistral, and others are supported via `laravel/ai`)

## Quick start

```bash
git clone <your-fork-url> MediaManager
cd MediaManager
cp .env.example .env

# start containers
vendor/bin/sail up -d

# install PHP + JS deps (first run only)
vendor/bin/sail composer install
vendor/bin/sail npm install

# Laravel bootstrap
vendor/bin/sail artisan key:generate
vendor/bin/sail artisan migrate --seed

# build the frontend
vendor/bin/sail npm run build

# visit http://localhost:${APP_PORT}
vendor/bin/sail open
```

In local/testing environments, the default seed creates an admin user `test@example.com` (password from the `User` factory — check it via `vendor/bin/sail artisan tinker` if you need it, or create one directly with `vendor/bin/sail artisan users:create`). In non-local environments it skips that fixed user. It always seeds the `ActionTypeConfig` rows (add/delete/monitor/quality-profile for Sonarr+Radarr, Seerr cleanup/approve/decline, Emby library scan, media replacement, stuck-import resolution, and the nine `bazarr_*` subtitle operations) and, if any `*_URL` / `*_API_KEY` env vars are set, the matching `ServiceConnection` rows.

During development you probably want the Vite dev server running instead of `npm run build`:

```bash
vendor/bin/sail npm run dev
```

## Production deployment

The production stack lives in `docker/production` and runs the published application image as separate web (Octane), queue, scheduler, Reverb, migration, and Inertia SSR services, alongside Postgres, Valkey, and Typesense containers. The SSR service uses the same image as the web service, so both processes always run the same application release and frontend bundle.

For a new deployment, create the production environment file and fill in the required application, database, Reverb, Typesense, and service credentials:

```bash
cd docker/production
cp .env.example .env
```

Keep these SSR settings in `.env`:

```ini
INERTIA_SSR_ENABLED=true
INERTIA_SSR_RUNTIME=node
INERTIA_SSR_ENSURE_RUNTIME_EXISTS=true
INERTIA_SSR_URL=http://ssr:13714
INERTIA_SSR_ENSURE_BUNDLE_EXISTS=true
INERTIA_SSR_THROW_ON_ERROR=false
```

Pull and start the stack from `docker/production`:

```bash
docker compose --env-file .env pull
docker compose --env-file .env up -d
docker compose --env-file .env ps
```

The normal image build creates both the browser bundle and `bootstrap/ssr/ssr.js`. The `ssr` container runs that bundle with Node.js on the private Compose network; port `13714` is not exposed publicly. TLS is terminated by your reverse proxy in front of the `web` and `reverb` ports.

### Verify SSR

```bash
docker compose --env-file .env ps ssr
docker compose --env-file .env exec ssr php artisan inertia:check-ssr
```

The Artisan command should print `Inertia SSR server is running.` You can also confirm that an initial Inertia response contains server-rendered markup; replace the URL with the deployment's public login URL:

```bash
curl -fsSL https://media.example.com/login | grep 'data-server-rendered="true"'
```

The web service deliberately does not depend on SSR health. If the renderer is starting, restarting, or unavailable, Inertia logs the rendering failure and falls back to the normal client-rendered application.

### Disable or roll back SSR

Set `INERTIA_SSR_ENABLED=false` in `docker/production/.env`, recreate the web container so its cached configuration uses the new value, and stop the renderer:

```bash
docker compose --env-file .env up -d --force-recreate web
docker compose --env-file .env stop ssr
```

## Configuration

### Required

| Variable | Purpose |
|---|---|
| `APP_KEY` | Generated by `artisan key:generate` |
| `APP_URL` | Public URL — webhook URLs are built from this |
| `APP_PORT` | Host port Sail binds to (default `81`) |
| `DB_*` | Postgres (Sail-managed) |
| `REVERB_APP_ID` / `REVERB_APP_KEY` / `REVERB_APP_SECRET` | WebSocket server credentials |
| `VITE_REVERB_*` | Mirrored to the browser (already defined as `${REVERB_*}` in `.env.example`) |

The dev stack also forwards: `FORWARD_DB_PORT` (Postgres, default 5433), `FORWARD_VALKEY_PORT` (6380), `FORWARD_MAILPIT_PORT` (1026), `FORWARD_MAILPIT_DASHBOARD_PORT` (8026), `FORWARD_TYPESENSE_PORT` (8109).

### Service connections

Two ways to configure — pick whichever fits. The admin UI is the normal path for production; env+seeder is faster for local dev and CI.

#### Via env + seeder

Set any subset in `.env`. Each service follows the same four-variable pattern:

```ini
SONARR_URL=https://sonarr.example.com
SONARR_API_KEY=...
SONARR_WEBHOOK_TOKEN=   # optional — auto-generated if blank
SONARR_NAME=            # optional — defaults to "Sonarr"
```

Supported prefixes: `SONARR`, `RADARR`, `BAZARR`, `EMBY`, `SEERR`, `PROWLARR`, `SABNZBD`. (Whisparr is not covered by the seeder — add it through the admin UI.)

Then seed:

```bash
vendor/bin/sail artisan db:seed --class=ServiceConnectionSeeder
```

`.env` is authoritative: if a connection of the same type already exists, the seeder **updates** it to match the env values (so a stale factory row can't outvote your config). Services with no `URL`/`API_KEY` set are skipped. The seeder uses `getenv()` (not `env()`) so `putenv()`-style overrides from tests propagate.

#### Via admin UI

Sign in as admin → **Admin → Connections → Add Connection**. Hit "Test" before saving to verify the URL + API key, then save. The edit form shows the per-connection webhook token (rotate it by clearing the field and saving) and per-type extras:

- **Sonarr/Radarr** — subtitle-check tags and (Sonarr) per-root-folder library types (`anime` / `tv`), used by media replacement
- **Whisparr** — version select (`v2` Eros/series-based or `v3` movie-based)
- **Bazarr** — mapping to the Sonarr/Radarr connections it manages (required for subtitle cases)
- **SABnzbd** — hidden categories, plus a generated notification script (see [Webhooks](#webhooks))
- **Prowlarr** — read-only indexer list with per-indexer test buttons

Connections can also set an `external_url`, used when the UI links out to the service.

For Sonarr, Radarr, Prowlarr, and Whisparr, the **Configure webhook** button registers MediaManager's webhook endpoint in the remote service automatically.

### Authentication

Three sign-in paths are available. Enable whichever combination you want:

- **Local email/password** (Fortify, with optional two-factor auth) — always available. Users can be created via the invite flow (admin → Users → Invite) which emails a signed `/invite/{user}/accept` link; the invitee sets a password on their first sign-in via the `password.set` middleware gate. `artisan users:create` creates a user directly.
- **Authentik SSO** — set `AUTHENTIK_CLIENT_ID`, `AUTHENTIK_CLIENT_SECRET`, `AUTHENTIK_BASE_URL`, and (usually) leave `AUTHENTIK_REDIRECT_URI` as the default `${APP_URL}/auth/authentik/callback`.
- **Emby credentials** — set `EMBY_URL` + `EMBY_API_KEY` (via the connection form or seeder). Users sign in with their Emby username/password; MediaManager brokers the auth via the Emby connection.

Role model (see `App\Enums\UserRole`):

| Role | Access |
|---|---|
| `admin` | Full access: connections, approval rules, user management, AI chat and settings |
| `member` | Browse + edit media, approve action requests, request subtitle operations |
| `viewer` | Read-only dashboard, sees only their own Emby activity |

Checks are hierarchical via `UserRole::isAtLeast()` — a route gated by `role:member` also admits admins.

### Notifications

Every notification resolves its delivery per user across four channels: **database** (in-app inbox), **broadcast** (live toast), **mail**, and **ntfy** (push). Defaults are database + broadcast on, mail + ntfy off; users tune a channel × severity matrix per notification type under **Settings → Notifications** (service warnings, AI budget warnings, service updates, media-replacement outcomes), with a test-send button. The inbox lives at `/notifications`.

For ntfy, set the server and optional access token:

```ini
NTFY_SERVER=https://ntfy.sh   # or your self-hosted instance
NTFY_TOKEN=
```

Each user sets their own topic on the preferences page. Delivery is best-effort — failures are logged, never thrown.

### AI (optional)

Gated behind `MEDIAMANAGER_AI_ENABLED`. When disabled, `/ai/*` routes 403, all AI pages are hidden, and the autonomous agents no-op. Providers are configured through `laravel/ai` (`config/ai.php`) — set an API key for whichever you use (`OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, `GEMINI_API_KEY`, `GROQ_API_KEY`, `MISTRAL_API_KEY`, …). Defaults:

```ini
MEDIAMANAGER_AI_ENABLED=true
MEDIAMANAGER_AI_MODE=executive          # or 'advisory'
MEDIAMANAGER_AI_MODEL=gpt-5-mini        # any configured provider model
MEDIAMANAGER_AI_TITLE_MODEL=gpt-5.4-nano # cheap model for chat titles ('auto' = provider's cheapest)
MEDIAMANAGER_AI_CHAT_TIMEOUT=120
OPENAI_API_KEY=sk-...
```

Env vars are only the boot defaults — mode, model, budgets, reasoning level, failover provider chain, and more are editable at runtime under **Admin → AI Settings** (stored in `app_settings`).

Five agents live under `app/Ai/Agents/`:

- **`MediaAgent`** — the interactive chat assistant (admin-only, `/ai/chat`). ~45 tools across `app/Ai/Tools/{Arr,Bazarr,Emby,Seerr,Prowlarr,System,Tmdb,Trakt,Workflow,PriceFetcher}/`; Sonarr/Radarr/Whisparr share the `Arr` tools via a `service` parameter. Prowlarr/Bazarr/TMDB/Trakt tools are only exposed when the matching connection or API key exists.
- **`DecisionAgent`** — optional autonomous agent that runs once per inbound webhook event (opt-in via `MEDIAMANAGER_DECISION_AGENT_ENABLED` + Admin → Decision Agent). It can propose actions and resolve stuck imports; every proposal is an `ActionRequest` and each run is recorded as an `AgentDecision` audit row.
- **`SubtitleAdvisorAgent`** — investigates one escalated subtitle case at a time (see [subtitle automation](#media-replacement--subtitle-automation)).
- **`PriceFetcherAgent`** — re-verifies model prices against first-party pricing pages.
- **`TitleAgent`** — names chat conversations.

Every tool declares a `Risk` tier:

- **`Read` / `SafeWrite`** — execute directly and return their result as JSON.
- **`Destructive`** — in **executive** mode they're routed through `ActionOrchestrator` and queued as an `ActionRequest` (the agent cannot bypass `requires_approval`); in **advisory** mode they're refused with an error envelope, and every other `ActionRequest` queues as Pending regardless of config.

For multi-step intents the chat agent calls `ProposeWorkflowTool` to write an `AiProposedWorkflow` row instead of firing each step individually. The chat UI surfaces an Approve/Decline confirm card; on approval the agent executes the steps as a batch (each step still funnels through the same approval rules).

TMDB and Trakt integrations are optional. Set `TMDB_API_KEY` (v4 Read Access Token) and `TRAKT_CLIENT_ID` (just the OAuth client id — no full OAuth flow) to enable the recommendation tools. When unset, the agent falls back to Seerr discovery.

Conversation history is healed automatically before each request — orphaned tool calls (e.g. from an interrupted prior turn) get stub results inserted so providers don't 400 on the malformed transcript.

#### Cost accounting, budgets, and the price catalog

Every agent invocation is recorded as an `AiUsageRecord` (tokens, snapshot of the model's per-Mtok rates, tool-call count) and surfaced under **Admin → AI Usage** (with CSV export). Supporting machinery, all managed in the admin UI:

- **Budgets** — soft/hard monthly USD caps. The soft cap notifies admins once per month; the hard cap makes agents refuse to run.
- **Model price catalog** — `ai_model_prices` rows per provider+model, refreshed weekly from the [Models.dev](https://models.dev) feed (opt-in: `AI_PRICING_MODELS_DEV_ENABLED=true`) and re-verified monthly by `PriceFetcherAgent` against first-party pricing pages, with anomaly guards and per-row price locks. Manual run: `artisan ai:refresh-prices [--verify] [--dry-run]`.
- **Free usage pools** — named daily/weekly/monthly token quotas shared by models (e.g. a provider's free tier), subtracted from reported cost; configurable overflow behaviour.
- **Rate limits** — per-model request/token limits per minute/hour/day, shown as used-vs-limit on the usage page (reporting only, not enforced).

#### Semantic library search

When AI is enabled, library items are embedded (256-dim vectors from the configured embeddings provider — OpenAI's `text-embedding-3-small` by default) and stored in Typesense for meaning-based search via the chat assistant, with optional reranking when a reranking provider (Cohere, Jina) is configured. New/changed items embed automatically through a queued job; backfill an existing library with:

```bash
php artisan ai:embed-library --missing-only
```

## Media replacement & subtitle automation

MediaManager can replace an already-imported episode or movie with a better release — primarily to fix missing or wrong subtitles — using a grab-before-delete pipeline that suspends the *arr's own monitoring, grabs the chosen release, only then deletes and blocklists the old file, sweeps any competing grab the *arr queued in parallel, and verifies the import via webhooks. Every replacement is an `ActionRequest` of type `replace_media_file` (requires approval by default) and a durable `MediaReplacementAttempt` row; stalled attempts are flagged `needs_attention` by an hourly reconciler and notify admins.

Three things can propose a replacement:

1. **The AI chat assistant** — inspect a file, list ranked candidates, replace (manually picked or automatic candidate).
2. **The automatic subtitle check** — on every Sonarr/Radarr import webhook, items carrying a configured *arr tag are audited against the required subtitle languages; a miss dispatches a replacement request. Configure under **Admin → AI Settings → Media replacement** (global + per-scope languages, confidence threshold, season-pack policy, attempt limits/cooldown, per-scope guidance rules like "this release group guarantees subs") and per-connection (subtitle-check tags, Sonarr root-folder scopes for anime/tv classification).
3. **The subtitle advisor** — see below.

**Bazarr subtitle cases** close the loop for media whose subtitles Bazarr should be able to fetch. Opt-in under **Subtitles → Admin → Subtitle automation** (`enabled` is off by default). The reconciler (every 5 minutes, plus Bazarr Apprise notifications for instant nudges) discovers media missing required subtitles and tracks each as a `SubtitleCase`: a grace period first (Bazarr usually fixes things itself), then spaced provider probes that queue `bazarr_download_best` action requests for eligible candidates, and — after repeated empty probes — escalation to the **`SubtitleAdvisorAgent`**, which inspects the case and may queue a media replacement when a high-confidence candidate exists, or parks the case as `needs_review` and notifies admins. Cycle sizes, grace hours per scope, probe spacing, escalation thresholds, and advisor concurrency are all tunable on the same page.

The **Subtitle Center** (`/subtitles`, sidebar "Subtitles") is viewer-visible: Overview, Missing, Library, and History tabs project Bazarr's inventory; the Escalations tab lists cases (members can re-run the advisor; admins can filter by status). Members can request manual subtitle operations (download best/exact, upload, delete, sync, translate, scan) — each becomes a `bazarr_*` action request gated by Action Rules and by Bazarr's advertised capabilities, and carries a file fingerprint so a stale page can't act on a replaced file.

## External-API caching

Slow reads to Sonarr / Radarr / Whisparr / Bazarr / Seerr / Prowlarr / SABnzbd / TMDB / Trakt / anime sources pass through `app/Cache/Services/{Service}Cache.php`, backed by Valkey (Redis-compatible) with tagged invalidation. TTLs are configurable via `MEDIAMANAGER_CACHE_TTL_{LIST,ENTITY,METADATA}` (defaults: 300s / 600s / 1800s; seasonal-anime data has its own longer TTLs). Webhook handlers and local action executors call `bustAll()` to evict per-connection tags so writes feel immediate; TTL alone covers third-party providers (TMDB/Trakt) which we cannot invalidate on demand. A presence-aware warmer (`services:warm-caches`, every minute) refreshes near-expiry entries while users are active and no-ops otherwise (browser heartbeat → `MEDIAMANAGER_PRESENCE_*`). Cache driver is set via `MEDIAMANAGER_CACHE_STORE` (default `redis`) — independent of Laravel's global `CACHE_STORE`. Tests use the `array` driver via `tests/Pest.php`.

## Webhooks

Point each service's outbound webhook at:

```
POST {APP_URL}/api/webhooks/{service}/{connection_id}
Header: X-Webhook-Token: {token}
```

Replace `{service}` with `sonarr`, `radarr`, `whisparr`, `prowlarr`, `sabnzbd`, `emby`, or `seerr`, and `{connection_id}` with the numeric ID from the Connections admin page. The token is per-connection (visible in the Edit form). The `VerifyWebhookToken` middleware rejects any request with a missing/mismatched token or a service-type that doesn't match the connection. For Sonarr, Radarr, Whisparr, and Prowlarr the admin UI can register the webhook automatically ("Configure webhook").

Two services deliver differently:

- **SABnzbd** has no native HTTP webhook — the connection edit page generates a Python notification script (stdlib only, token embedded) to drop into SABnzbd's `scripts/` folder and select under Settings → Notifications.
- **Bazarr** posts to a dedicated endpoint (`POST {APP_URL}/webhooks/bazarr/{connection_id}`) via Apprise — the Subtitles → Admin page shows the exact `json://` config URI to paste into Bazarr's notification settings. These events are treated as reconciliation hints rather than a typed event vocabulary.

Webhook delivery is logged as a `WebhookEvent` (browseable under Admin → Webhook Log, with a 5-minute payload dedupe), then processed asynchronously by `ProcessWebhookEvent` (requires the queue worker — in dev, `vendor/bin/sail artisan queue:listen`).

### Supported events

**Sonarr** — `Test`, `Grab`, `Download`, `Rename`, `SeriesAdd`, `SeriesDelete`, `EpisodeFileDelete`, `ManualInteractionRequired`, `Health`, `HealthRestored`, `ApplicationUpdate`

**Radarr** — `Test`, `Grab`, `Download`, `Rename`, `MovieAdded`, `MovieDelete`, `MovieFileDelete`, `ManualInteractionRequired`, `Health`, `HealthRestored`, `ApplicationUpdate`

**Whisparr** — the union of the Sonarr and Radarr sets (handles both v2 series-shaped and v3 movie-shaped payloads)

**Prowlarr** — `Test`, `Health`, `HealthRestored`, `ApplicationUpdate`

**SABnzbd** (from the notification script) — `complete`, `failed`, `startup`, `pause`, `resume`, `queue_done`, `warning`, `error`, `disk_full`

**Emby** — `playback.start`, `playback.unpause`, `playback.pause`, `playback.stop`, `item.markplayed`, `library.deleted`

**Seerr** — `TEST_NOTIFICATION`, `MEDIA_PENDING`, `MEDIA_APPROVED`, `MEDIA_AUTO_APPROVED`, `MEDIA_DECLINED`, `MEDIA_AVAILABLE`, `MEDIA_FAILED`, `ISSUE_CREATED`, `ISSUE_COMMENT`, `ISSUE_RESOLVED`, `ISSUE_REOPENED`

Unknown event types are logged and skipped — they don't error out the webhook call.

### Cross-service orchestration

Some webhook events dispatch `ActionRequest`s via `ActionOrchestrator`:

| Trigger | Target action | Default behaviour |
|---|---|---|
| Emby `library.deleted` on a Series (payload has `ProviderIds.SonarrSeriesId`) | `delete_series` on Sonarr | Requires approval |
| Emby `library.deleted` on a Movie (payload has `ProviderIds.RadarrMovieId`) | `delete_movie` on Radarr | Requires approval |
| Sonarr `Download` / `SeriesDelete` | `emby_library_scan` | Auto-execute |
| Radarr `Download` / `MovieDelete` | `emby_library_scan` | Auto-execute |
| Seerr `MEDIA_AVAILABLE` | `emby_library_scan` | Auto-execute |

Other webhook side effects: Sonarr/Radarr `Download` also queues the automatic subtitle check; `ManualInteractionRequired` feeds the stuck-import counter (sidebar badge) and, if the decision agent is enabled, can lead to `resolve_manual_import` / `remove_stuck_download` proposals; SABnzbd events refresh download counts; Bazarr notifications trigger subtitle-case reconciliation.

Approval behaviour is editable at `/actions/rules` (admin only). Rows come from `ActionTypeConfigSeeder`; toggle `requires_approval` / `is_enabled` per type. In advisory AI mode every request queues as Pending regardless of these flags.

## Scheduled tasks

The scheduler runs as its own container in both the dev and production stacks (`schedule:work`) — no host cron needed. Registered schedules (see `routes/console.php`; all `withoutOverlapping`):

| Cadence | Task |
|---|---|
| every minute | `services:warm-caches` — presence-aware external-API cache warmer |
| every 5 min | `services:check-health`, `dashboard:broadcast-stats`, `bazarr:reconcile`, `sabnzbd:poll-history`, `sabnzbd:refresh-download-counts`, `library:refresh-intervention-count`, `statistics:collect-gauges` |
| hourly | `media-replacement:reconcile`, `actions:reconcile-stuck`, `statistics:aggregate` (at :05), prune expired subtitle uploads |
| daily | `services:check-versions`, `app:check-version`, `ai:prune-proposed-workflows`, `statistics:prune` (04:30), retention pruning (03:00) for webhook events / activity logs / Emby activity / AI usage + tool invocations / agent decisions / replacement attempts, notification pruning, search-index reconciliation (03:30), daily library gauge snapshot (04:00) |
| weekly | `ai:refresh-prices` (Models.dev sync), anime id-mapping sync |
| monthly | `ai:refresh-prices --verify` (first-party price re-verification) |

Retention windows are configurable via `MEDIAMANAGER_RETENTION_*_DAYS` (0 disables pruning for that table).

Upstream version checks map service types to GitHub repos: Sonarr → `Sonarr/Sonarr`, Radarr → `Radarr/Radarr`, Whisparr → `Whisparr/Whisparr`, Prowlarr → `Prowlarr/Prowlarr`, Bazarr → `morpheus65535/bazarr`, Seerr → `seerr-team/seerr`, Emby → `MediaBrowser/Emby.Releases` (closed-source; canonical release mirror). SABnzbd has no version check.

## Statistics & metrics

`statistics:aggregate` rolls durable event tables into pre-aggregated `stat_rollups` buckets (hour + day granularity); `statistics:collect-gauges` samples live state (disk, queue depth, sessions, subtitle cases). The **Statistics** page (all roles) shows plays, finishes, watch hours, downloads, the request funnel, watch leaderboards, top titles, and an hour heatmap over a selectable window; **Admin → Statistics** adds webhooks, action/approval rates, agent decisions, disk-free and queue-depth series, and AI cost. Backfill history from existing tables with `artisan statistics:backfill`.

A Prometheus scrape endpoint is available at `/metrics`, gated by a bearer token:

```ini
METRICS_ENABLED=true
METRICS_TOKEN=          # empty = deny all
METRICS_ALLOWED_IPS=
```

## Local testing

### Simulate a webhook

Fixtures live at `database/fixtures/webhooks/{service}/{event}.json`. The `webhook:simulate` command posts them at your own webhook endpoint using the active connection's token.

```bash
# list available fixtures
vendor/bin/sail artisan webhook:simulate --list

# fire an Emby playback-start against the first active Emby connection
vendor/bin/sail artisan webhook:simulate emby playback.start

# dry-run: print payload + target URL without sending
vendor/bin/sail artisan webhook:simulate sonarr download --dry-run

# override a payload field with dot-notation
vendor/bin/sail artisan webhook:simulate emby library.deleted \
    --set 'Item.ProviderIds.SonarrSeriesId=42'

# target a specific connection id
vendor/bin/sail artisan webhook:simulate emby playback.stop --connection=3
```

After firing, the command prints the resulting `WebhookEvent` and any `ActionRequest`s that were created. Add your own fixtures under `database/fixtures/webhooks/{service}/{event}.json` — shipped fixtures cover a subset of events; extend as needed.

### Other dev helpers

```bash
vendor/bin/sail artisan demo:fake-webhooks      # curated fake webhooks across services (demo the realtime UI)
vendor/bin/sail artisan demo:fake-actions       # fake ActionRequests in various states
vendor/bin/sail artisan emby:debug-sessions     # what Emby /Sessions returns (--raw, --query-auth)
vendor/bin/sail artisan emby:backfill-history   # import historical Emby watch data
vendor/bin/sail artisan typesense:seed          # seed the search index from Sonarr/Radarr
vendor/bin/sail artisan users:create            # create a user with a chosen role
```

## Running tests

```bash
vendor/bin/sail artisan test --compact
vendor/bin/sail artisan test --compact --filter='Media\\Series'
```

The suite covers auth flows, media controllers, webhook ingest and handlers, the action orchestrator + executor, media replacement, the Bazarr subtitle pipeline, real-time events, AI tools and agents, scheduled commands, and model behaviour. Browser tests run with `composer test:browser` (boots Inertia SSR first).

## Code quality

Order matters — rector first (structural refactors), then pint (formatting):

```bash
vendor/bin/sail bin rector
vendor/bin/sail bin pint --dirty --format agent
```

Other useful checks:

```bash
vendor/bin/sail npm run lint            # eslint --fix
vendor/bin/sail npm run format          # prettier --write
vendor/bin/sail npm run types:check     # vue-tsc --noEmit
vendor/bin/sail npm run build           # vite build
```

Regenerate frontend typings after changing Laravel routes, models, enums, or form requests:

```bash
vendor/bin/sail artisan wayfinder:generate --with-form
vendor/bin/sail artisan typefinder:generate
```

`resources/js/actions/`, `resources/js/routes/`, and `resources/js/typefinder/` are all auto-generated — don't edit by hand.

## Architecture

Data flow for an incoming webhook:

```
External service (Sonarr / Radarr / Whisparr / Prowlarr / SABnzbd / Emby / Seerr)
    |  POST /api/webhooks/{service}/{connection_id}
    v
VerifyWebhookToken middleware  (rejects on token mismatch / wrong service type)
    |
    v
WebhookController::handle      (persists WebhookEvent, dedupes, dispatches ProcessWebhookEvent)
    |
    v  [queue]
ProcessWebhookEvent job        (resolves the right handler)
    |
    v
{Service}WebhookHandler        (writes ActivityLog, calls ActionOrchestrator, feeds trackers)
    |
    v
ActionRequest                  (pending -> approved -> executing -> completed/failed)
    |
    v  [queue]
ExecuteActionRequest job       (delegates to ActionExecutor)
    |
    v
{Service}Actions class         (typed HTTP client wrapper -> {Service}Client)
```

(Bazarr notifications take a parallel path: `POST /webhooks/bazarr/{connection_id}` → `BazarrNotificationController` → subtitle-case reconciliation jobs.)

Real-time fan-out is via Laravel Reverb on private channels (see `routes/channels.php`):

| Channel | Who | Carries |
|---|---|---|
| `App.Models.User.{id}` | own user | per-user notifications |
| `services` | member+ | service health / connection / version events |
| `members.actions` | member+ | action request created/status changed |
| `members.sabnzbd` | member+ | download finished |
| `emby.activity` | authenticated | Emby playback updates |
| `dashboard` | authenticated | dashboard stats, webhook activity, download counts, intervention + subtitle-case changes |
| `activity` | authenticated | activity log entries |
| `admin.ai-prices` | admin | price-refresh progress |
| `ai-chat.{userId}.{conversationId}` | owner | streaming agent steps |

Frontend composables in `resources/js/composables/` subscribe to these (`useServiceHealth`, `useEmbyActivity`, `useDashboardStats`, `useNotifications`, `useNavCounts`, `useAiChat`, …) on top of the lower-level `useWebSocket`.

### Key directories

| Path | Contents |
|---|---|
| `app/Http/Controllers/{Admin,Auth,Media,Emby,Bazarr,Library,Prowlarr,Sabnzbd,Monitoring,Actions,AI,Settings,Webhooks}/` | Feature-scoped controllers |
| `app/Services/{Arr,Sonarr,Radarr,Whisparr,Emby,Seerr,Prowlarr,Sabnzbd,Bazarr,Anime,Tmdb,Trakt}/` | Typed HTTP clients + per-service webhook handlers + `Actions` classes |
| `app/Services/Actions/` | `ActionOrchestrator` (dispatch) and `ActionExecutor` (run) |
| `app/Services/MediaReplacement/` | Replacement pipeline: inspector, candidate finder/ranker, tracker, sweeper, subtitle auditor |
| `app/Services/{Statistics,ServiceMetrics,DashboardMetrics,Dashboard}/` | Rollup aggregation + dashboard/stats repositories |
| `app/Services/{Notifications,Search,Library,AiBudget,AiUsage,GitHub,Webhook}/` | Notification routing, semantic search, intervention counter, AI cost machinery, release checks |
| `app/Ai/{Agents,Tools,Decision,SubtitleAdvisor,Storage}/` | Agents, their bound tools, and conversation storage |
| `app/Settings/` | Typed runtime settings objects backed by the `app_settings` table |
| `app/Cache/Services/` | Per-service external-API caches |
| `app/Jobs/` | Queue jobs (webhook processing, action execution, reconciliation, sweeps, embedding, …) |
| `app/Events/` / `app/Notifications/` | Broadcast events and user notifications (incl. the ntfy channel) |
| `app/Http/Resources/` | Typed API resources (picked up by typefinder) |
| `app/Enums/` | Roles, service types, action/health/AI/subtitle/replacement enums |
| `resources/js/pages/` | Inertia Vue pages, subdirs mirror controller namespaces |
| `resources/js/composables/` | Echo subscriptions + shared client state |
| `resources/js/typefinder/` | **Auto-generated** TS types |
| `resources/js/{actions,routes}/` | **Auto-generated** Wayfinder route helpers |
| `database/fixtures/webhooks/` | JSON fixtures for `webhook:simulate` |

## Contributing

- Follow existing patterns — check sibling files before inventing new structure
- Every change needs a test (Pest feature tests preferred); run the suite before committing
- Run `rector` then `pint --dirty` before committing PHP changes
- Regenerate Wayfinder + Typefinder output after backend changes that touch routes, models, enums, or form requests
- Don't commit changes to `resources/js/actions/`, `resources/js/routes/`, or `resources/js/typefinder/` directly — regenerate them

## License

MIT — see `composer.json`.
