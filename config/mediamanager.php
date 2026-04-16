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
    */

    'ai' => [
        'enabled' => env('MEDIAMANAGER_AI_ENABLED', false),

        // Which agent responds to the chat UI by default.
        'default_agent' => env('MEDIAMANAGER_AI_DEFAULT_AGENT', 'command'),

        // Model used by all agents. Override per-agent if needed.
        'model' => env('MEDIAMANAGER_AI_MODEL', 'claude-sonnet-4-5'),
    ],
];
