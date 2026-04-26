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
    | Models and mode default to the values below but are overridden at runtime
    | by the app_settings table (managed from the admin AI Settings page).
    |
    */

    'ai' => [
        'enabled' => env('MEDIAMANAGER_AI_ENABLED', false),

        // Which agent responds to the chat UI by default.
        'default_agent' => env('MEDIAMANAGER_AI_DEFAULT_AGENT', 'command'),

        // Operating mode: 'executive' (current behavior) or 'advisory'
        // (CommandAgent loses CreateActionRequestTool and every ActionRequest
        // queues as Pending, regardless of ActionTypeConfig).
        'mode' => env('MEDIAMANAGER_AI_MODE', 'executive'),

        // Per-agent default models. Free-form strings — must match a model
        // identifier supported by a configured laravel/ai provider.
        'command_model' => env('MEDIAMANAGER_AI_COMMAND_MODEL', 'gpt-5-mini'),
        'advisor_model' => env('MEDIAMANAGER_AI_ADVISOR_MODEL', 'gpt-5-mini'),
    ],
];
