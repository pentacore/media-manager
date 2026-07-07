<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | AI Assistant
    |--------------------------------------------------------------------------
    |
    | Controls whether the optional AI assistant feature is available. When
    | disabled, the /ai routes and sidebar entry are hidden and no LLM API
    | calls are made. Admin users only when enabled.
    |
    | Model and mode default to the values below but are overridden at runtime
    | by the app_settings table (managed from the admin AI Settings page).
    |
    */

    'ai' => [
        'enabled' => env('MEDIAMANAGER_AI_ENABLED', false),

        // Operating mode: 'executive' (current behavior — ActionTypeConfig and
        // destructive checks gate execution) or 'advisory' (every ActionRequest
        // queues as Pending regardless of config; destructive tools refuse to
        // queue). Default stays 'executive' for backward compat; prod env can
        // override to 'advisory' for safer rollouts.
        'mode' => env('MEDIAMANAGER_AI_MODE', 'executive'),

        // Default model for MediaAgent. Free-form string — must match a model
        // identifier supported by a configured laravel/ai provider.
        'model' => env('MEDIAMANAGER_AI_MODEL', 'gpt-5-mini'),

        // Cheap model used to auto-summarize the first user message of a new
        // conversation into a 4-6 word chat title. Runs in the background
        // queue after the first agent response. Override per env or via the
        // admin AI Settings page.
        'title_model' => env('MEDIAMANAGER_AI_TITLE_MODEL', 'gpt-5.4-nano'),

        // Opt-in: swap the PriceFetcherAgent's custom host-allowlisted HTTP
        // GET tool for the SDK's provider-native WebFetch. Only works on
        // providers that support it (OpenAI/Anthropic); unsupported providers
        // throw a LogicException at prompt time, so this defaults OFF.
        'price_fetcher_provider_webfetch' => env('AI_PRICEFETCHER_PROVIDER_WEBFETCH', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Decision Agent
    |--------------------------------------------------------------------------
    |
    | The autonomous agent that reasons over inbound webhook events and either
    | suggests (queues a Pending ActionRequest) or acts (auto-executes) per the
    | per-type ActionTypeConfig approval flags. Distinct from the interactive
    | chat agent above. All values below are defaults — the app_settings table
    | (managed from the admin Decision Agent settings page) overrides them at
    | runtime. Ships fully off with an empty allowlist: opt-in only.
    |
    */

    'decision_agent' => [
        'enabled' => env('MEDIAMANAGER_DECISION_AGENT_ENABLED', false),

        // Empty = falls back to the chat model at runtime.
        'model' => env('MEDIAMANAGER_DECISION_AGENT_MODEL', ''),

        // List of "service:EventType" keys the agent reacts to. Empty by
        // default; managed from the settings page.
        'event_allowlist' => [],

        // Gates the manual-import resolution capability (Stage 2 tooling).
        'allow_manual_import' => false,

        'notify_on_suggest' => true,
        'notify_on_act' => true,

        // Caps how many ActionRequests one decision may spawn.
        'max_actions_per_run' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | External API Cache
    |--------------------------------------------------------------------------
    |
    | Slow external-API reads (Sonarr/Radarr/Seerr/Prowlarr/Tmdb/Trakt) are
    | wrapped in tagged Cache stores under app/Cache/Services. Webhook
    | handlers and local action executors bust per-service tags whenever
    | upstream state actually changes, so the TTLs below are sized for
    | "between webhooks" — generous on the assumption that the cache will
    | be invalidated as soon as something the user did affects it.
    |
    */

    'cache' => [
        // Cache driver to use. Defaults to redis (Valkey-compatible) so tagged
        // flushes work; override per-env (e.g. 'array' in tests).
        'store' => env('MEDIAMANAGER_CACHE_STORE', 'redis'),

        'ttl' => [
            // Paginated lists, searches, summaries. Long enough that
            // switching between Seerr/Sonarr tabs feels instant; bust on
            // any webhook event keeps it from going stale.
            'list' => (int) env('MEDIAMANAGER_CACHE_TTL_LIST', 300),
            // Single-entity reads (e.g. one series, one movie, one request).
            'entity' => (int) env('MEDIAMANAGER_CACHE_TTL_ENTITY', 600),
            // Slow-changing third-party metadata (TMDB title/credits, Trakt
            // lists) — these aren't bust on our webhooks because we don't
            // own the upstream state, so half an hour is the safety margin.
            'metadata' => (int) env('MEDIAMANAGER_CACHE_TTL_METADATA', 1800),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Defaults for incoming webhook handling. The capture toggle controls
    | whether processed webhook payloads remain in the webhook_events table
    | (useful for the admin Webhook log) or are discarded after handlers run.
    | The persisted setting in app_settings overrides this default.
    |
    */

    'webhooks' => [
        'capture_enabled' => (bool) env('MEDIAMANAGER_WEBHOOKS_CAPTURE_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    |
    | Drives the unified-search and Cmd-K palette. 'typesense' queries the
    | Scout-backed Series/Movie indexes; 'fallback' bypasses Typesense and
    | hits Sonarr/Radarr APIs directly with a substring filter (the original
    | pre-Typesense behavior, kept for emergency rollback).
    |
    */

    'search' => [
        'driver' => env('MEDIAMANAGER_SEARCH_DRIVER', 'typesense'),
        'max_results' => (int) env('MEDIAMANAGER_SEARCH_MAX_RESULTS', 20),
        'instant_max_results' => (int) env('MEDIAMANAGER_SEARCH_INSTANT_MAX_RESULTS', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Presence
    |--------------------------------------------------------------------------
    |
    | Lightweight presence tracker fed by the browser heartbeat — used by
    | the cache warmer (`services:warm-caches`) to skip background upstream
    | calls when nobody is interacting with the app.
    |
    */

    'presence' => [
        // Redis sorted-set key holding active-user ids scored by expiry timestamp.
        // Override per-environment if you run multiple installations against
        // the same Redis instance.
        'key' => env('MEDIAMANAGER_PRESENCE_KEY', 'presence:users'),
        // Each heartbeat extends the user's membership by this many seconds.
        // Browser sends a heartbeat every 30s while interacting, so 90s gives
        // three missed beats of grace before they fall out of "active".
        'heartbeat_ttl' => (int) env('MEDIAMANAGER_PRESENCE_HEARTBEAT_TTL', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Bearer token that gates the Prometheus scrape endpoint (GET /metrics).
    | Left empty by default, which denies all access — set METRICS_TOKEN to a
    | long random secret to expose the endpoint to your Prometheus scraper.
    |
    */

    'metrics' => [
        'token' => env('METRICS_TOKEN'),
    ],
];
