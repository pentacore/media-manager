# MediaManager Design Spec

## Purpose

MediaManager is a Laravel-based web application for managing and monitoring a personal media stack (Sonarr, Radarr, Emby, Jellyseerr). It provides a unified dashboard, cross-service automation via webhooks, real-time updates via WebSockets, and an optional AI assistant — all behind SSO authentication.

**Audience:** Small group — one admin and a few trusted users (family/friends) who can view activity and manage media.

---

## Architecture

**Service-Per-Module** — each external service gets its own module with a dedicated Client, WebhookHandler, and Actions class. Sonarr and Radarr share an `ArrClient` base class since they use the same *arr API patterns (~80% shared surface). A central `ActionOrchestrator` routes webhook events into cross-service action requests with configurable approval rules.

All external API calls go through the queue for async processing. Webhook endpoints return 200 immediately after storing the raw event.

---

## Data Model

### users
Extends existing table with: `sso_provider` (nullable string), `sso_id` (nullable string), `role` (UserRole enum: admin/member/viewer). `password` becomes nullable for SSO-only users. First registered user is auto-admin.

### service_connections
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| type | enum | sonarr, radarr, emby, jellyseerr |
| name | string | Display name, e.g. "My Sonarr" |
| url | string | Base URL of the service |
| api_key | encrypted string | Service API key |
| webhook_token | encrypted string | Token for incoming webhook auth |
| is_active | boolean | |
| last_seen_at | timestamp | Last successful health check |
| version | string (nullable) | Detected app version |
| settings | json (nullable) | Service-specific config |
| timestamps | | |

### webhook_events
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| service_connection_id | FK | Which connection sent it |
| event_type | string | e.g. "series.delete", "playback.start" |
| payload | json | Raw webhook body |
| processed_at | timestamp (nullable) | When handler finished |
| timestamps | | |

### action_requests
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| webhook_event_id | FK (nullable) | Trigger source |
| type | string | "delete_series", "cleanup_request", etc. |
| source_service | string | Which service triggered |
| target_service | string | Which service to act on |
| status | enum | pending, approved, executing, completed, failed, rejected |
| requires_approval | boolean | From action type config |
| approved_by | FK users (nullable) | |
| payload | json | Action-specific data |
| result | json (nullable) | Execution result |
| timestamps | | |

### action_type_configs
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| type | string (unique) | Action type key |
| label | string | Human-readable name |
| description | text | |
| requires_approval | boolean | Default for this type |
| is_enabled | boolean | |
| timestamps | | |

### emby_user_links
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| user_id | FK users | App user |
| emby_user_id | string | Emby's user ID |
| emby_username | string | Display name |
| timestamps | | |

### emby_activities
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| emby_user_link_id | FK | |
| media_type | string | movie, episode |
| media_title | string | |
| series_title | string (nullable) | For episodes |
| emby_item_id | string | For deep linking |
| action | string | played, stopped, finished |
| duration_ticks | bigint (nullable) | |
| play_position | bigint (nullable) | |
| timestamps | | |

### activity_logs
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| user_id | FK (nullable) | Null for system actions |
| service_connection_id | FK (nullable) | |
| action | string | |
| subject_type | string (nullable) | Polymorphic |
| subject_id | bigint (nullable) | |
| description | string | |
| metadata | json (nullable) | |
| timestamps | | |

---

## Authentication

Three login methods, Authentik preferred:

1. **Authentik (OIDC)** — Primary. Via Laravel Socialite + `socialiteproviders/authentik`. Standard OIDC authorization code flow. Auto-links by email if existing user matches. New users default to `viewer` role.

2. **Emby** — Via Emby's `POST /Users/AuthenticateByName` API. User enters Emby username/password, server validates against connected Emby instance. Auto-creates `EmbyUserLink` on login. New users default to `viewer` role.

3. **Local email/password** — Existing Fortify auth, shown as collapsible secondary option. Remains as fallback.

Fortify handles session management, CSRF, and 2FA for all auth methods.

### Roles

| Role | Permissions |
|------|------------|
| **admin** | Everything member can do + manage service connections, manage users/roles, configure action type rules, access admin settings, AI chat |
| **member** | View dashboard, browse/edit/delete series and movies, view all Emby activity, approve action requests, unified search, notifications |
| **viewer** | View dashboard (read-only), view own Emby activity only |

Implementation: `UserRole` enum, Gate/Policy checks, `EnsureUserHasRole` middleware, Inertia shared props include `user.role`.

---

## Module Structure

```
app/Services/
├── Arr/ArrClient.php              # Shared base: quality profiles, root folders, commands, system/status
├── Sonarr/
│   ├── SonarrClient.php           # extends ArrClient: series CRUD, episodes, season monitoring
│   ├── SonarrWebhookHandler.php   # Normalizes Sonarr webhook payloads
│   └── SonarrActions.php          # Executable actions for Sonarr
├── Radarr/
│   ├── RadarrClient.php           # extends ArrClient: movie CRUD, collections
│   ├── RadarrWebhookHandler.php
│   └── RadarrActions.php
├── Emby/
│   ├── EmbyClient.php             # Users, activity, library, authentication
│   ├── EmbyWebhookHandler.php     # Playback events, library events, user events
│   └── EmbyActions.php
├── Jellyseerr/
│   ├── JellyseerrClient.php       # Requests, users, media lookup
│   ├── JellyseerrWebhookHandler.php
│   └── JellyseerrActions.php
└── ActionOrchestrator.php         # Routes events → action requests, checks approval config
```

---

## Webhook System

**Endpoint:** `POST /api/webhooks/{service}/{connectionId}`

**Middleware:** `VerifyWebhookToken` — looks up `ServiceConnection` by route params, compares `X-Webhook-Token` header to stored `webhook_token`. Returns 401 on mismatch.

**Flow:**
1. Middleware validates token
2. Controller stores raw payload as `WebhookEvent`, returns 200
3. `ProcessWebhookEvent` job dispatched to queue
4. Job routes to correct `WebhookHandler` by service type
5. Handler normalizes data, updates local state (e.g., `EmbyActivity`)
6. Handler feeds `ActionOrchestrator`
7. Orchestrator checks `action_type_configs`, creates `ActionRequest`
8. Auto-execute actions dispatch `ExecuteActionRequest` job
9. Manual-approval actions broadcast notification via Reverb

### Example Webhook Automations

- **Emby deletes series** → Create ActionRequest to delete from Sonarr + cleanup Jellyseerr requests
- **Sonarr completes download** → Notify users, trigger Emby library scan
- **Jellyseerr request approved** → Log activity, notify members
- **Emby user deletes a season** → Create ActionRequest to unmonitor in Sonarr + cleanup Jellyseerr

---

## Real-time (Reverb + Echo)

**Package:** `laravel/reverb` (server) + `laravel-echo` + `pusher-js` (client)

### Broadcast Channels

| Channel | Events | Auth |
|---------|--------|------|
| `private-App.User.{id}` | ActionRequestCreated, ActionRequestStatusChanged | User-specific |
| `private-services` | WebhookReceived, ServiceHealthChanged | Authenticated |
| `private-emby.activity` | EmbyPlaybackUpdated | Authenticated |
| `private-dashboard` | DashboardStatsUpdated | Authenticated |

### Frontend Composables

- `useWebSocket()` — manages Echo connection lifecycle
- `useNotifications()` — listens to user channel, triggers vue-sonner toasts
- `useEmbyActivity()` — live "Now Playing" data
- `useServiceHealth()` — connection status indicators
- `useDashboardStats()` — live stat counters

---

## Frontend Pages

Built with Vue 3, Inertia v3, shadcn-vue, Tailwind CSS v4.

### Navigation (Sidebar)

**Overview:** Dashboard, Activity Log, Search (Cmd+K)
**Media:** Series (Sonarr), Movies (Radarr), Requests (Jellyseerr)
**Monitoring:** Now Playing, Watch History, Service Health
**Automation:** Action Requests, Action Rules
**AI (Optional):** Chat Assistant
**Admin:** Connections, Users, Settings

### Page Map

```
pages/
├── Auth/Login.vue                 # Updated: Authentik + Emby + local
├── Dashboard.vue                  # Stats cards, recent activity, now playing
├── Sonarr/Series/{Index,Show,Create}.vue
├── Radarr/Movies/{Index,Show,Create}.vue
├── Emby/{NowPlaying,WatchHistory,UserLinks}.vue
├── Jellyseerr/Requests.vue
├── Actions/{Index,Rules}.vue
├── Search.vue                     # Unified Cmd+K search
├── ActivityLog.vue
├── AI/Chat.vue                    # Optional AI assistant
├── Admin/{Connections/{Index,Create,Edit},Users,Settings}.vue
└── Settings/{Profile,Security,Appearance}.vue  # Existing
```

---

## Service Health & Version Monitoring

- **Health checks:** Scheduled job (`CheckServiceHealth`) pings each active service connection's `/api/v3/system/status` (Sonarr/Radarr), `/System/Info` (Emby), or equivalent. Updates `last_seen_at` and broadcasts `ServiceHealthChanged` on status change.
- **Version monitoring:** Scheduled job (`CheckServiceVersions`) checks GitHub releases API for Sonarr, Radarr, Emby, and Jellyseerr. Compares to stored `version` field. Notifies admin when updates are available.
- **Service Health page:** Shows connection status, disk space, queue sizes, system info, and version status for each connected service.

---

## AI Integration (Optional)

Gated behind `config('mediamanager.ai.enabled')`. Uses Laravel AI SDK.

### Agents

- **MediaAdvisorAgent** — Analyzes watch history, recommends what to watch, identifies unwatched series for potential deletion, suggests quality upgrades.
- **CommandAgent** — Natural language control: "Delete all completed series with no plays in 6 months", "What's trending on my server?", "Show storage usage breakdown".

### Tools (used by agents)

- `SearchMediaTool` — Search across all services
- `GetServiceStatusTool` — Service health and stats
- `CreateActionRequestTool` — Create actions with proper approval flow
- `QueryActivityTool` — Query watch history and activity logs

Registered in `AIServiceProvider` only when enabled. AI actions go through the same `ActionRequest` approval flow as webhook-triggered actions.

---

## Infrastructure

### New Packages

**Backend:**
- `laravel/reverb` — WebSocket server
- `laravel/socialite` — OAuth/OIDC
- `socialiteproviders/authentik` — Authentik OIDC provider

**Frontend:**
- `laravel-echo` — WebSocket client
- `pusher-js` — Echo transport (Reverb-compatible)

**Later:**
- `laravel/ai` — AI SDK (when AI features are built)

### Docker/Sail

Reverb runs as a separate process via `artisan reverb:start`, added to the `composer run dev` script alongside the existing app server, queue worker, Pail, and Vite.

---

## Testing Strategy

Pest-based feature and unit tests:

- **Auth:** Authentik OIDC flow (mocked), Emby login (mocked), role-based access
- **Webhooks:** Token middleware validation, per-service webhook payload processing
- **Service clients:** HTTP-mocked tests for each client (Sonarr, Radarr, Emby, Jellyseerr)
- **Action orchestration:** Event→action routing, approval workflow, cross-service execution
- **Admin:** Service connection CRUD, user management
- **Unit:** ArrClient base class logic, webhook payload normalization

---

## Implementation Phases

| Phase | Focus | Key Deliverables |
|-------|-------|-----------------|
| 1 | Foundation | Migrations, models, factories, service connection admin CRUD, webhook endpoint + token middleware, basic event logging |
| 2 | Authentication | Socialite + Authentik, Emby auth, role system, updated login page, user management |
| 3 | Service Clients | ArrClient base, SonarrClient, RadarrClient, EmbyClient, JellyseerrClient |
| 4 | Real-time | Reverb setup, Echo frontend, broadcast events, Vue composables, live dashboard |
| 5 | Media Management UI | Sonarr series pages, Radarr movie pages, unified search, Jellyseerr requests |
| 6 | Emby Monitoring | Webhook handlers for playback, user linking UI, Now Playing, Watch History |
| 7 | Action Orchestration | ActionOrchestrator, action type config UI, approval UI, cross-service rules, activity log |
| 8 | Health & Versions | Health check jobs, version monitoring via GitHub API, health dashboard |
| 9 | AI Integration | Laravel AI SDK, MediaAdvisor agent, Command agent, chat UI |
