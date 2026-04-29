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

        // Operating mode: 'executive' (current behavior) or 'advisory'
        // (every ActionRequest queues as Pending regardless of ActionTypeConfig
        // and destructive tools refuse to queue).
        'mode' => env('MEDIAMANAGER_AI_MODE', 'executive'),

        // Default model for MediaAgent. Free-form string — must match a model
        // identifier supported by a configured laravel/ai provider.
        'model' => env('MEDIAMANAGER_AI_MODEL', 'gpt-5-mini'),
    ],

    /*
    |--------------------------------------------------------------------------
    | External API Cache
    |--------------------------------------------------------------------------
    |
    | Slow external-API reads (Sonarr/Radarr/Seerr/Prowlarr/Tmdb/Trakt) are
    | wrapped in tagged Cache stores under app/Cache/Services. TTLs are short
    | by default; webhook handlers and local action executors bust per-service
    | tags when relevant state changes.
    |
    */

    'cache' => [
        // Cache driver to use. Defaults to redis (Valkey-compatible) so tagged
        // flushes work; override per-env (e.g. 'array' in tests).
        'store' => env('MEDIAMANAGER_CACHE_STORE', 'redis'),

        'ttl' => [
            // Paginated lists, searches, summaries.
            'list' => (int) env('MEDIAMANAGER_CACHE_TTL_LIST', 60),
            // Single-entity reads (e.g. one series, one movie, one request).
            'entity' => (int) env('MEDIAMANAGER_CACHE_TTL_ENTITY', 300),
            // Slow-changing third-party metadata (TMDB title/credits, Trakt lists).
            'metadata' => (int) env('MEDIAMANAGER_CACHE_TTL_METADATA', 600),
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
];
