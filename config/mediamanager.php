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
];
