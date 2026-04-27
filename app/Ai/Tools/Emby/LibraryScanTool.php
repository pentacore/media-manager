<?php

declare(strict_types=1);

namespace App\Ai\Tools\Emby;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class LibraryScanTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Trigger an Emby library scan. Routed through the ActionRequest queue (admin rule decides whether it auto-executes or waits for approval).';
    }

    public function risk(): Risk
    {
        return Risk::Destructive;
    }

    /**
     * @return array{type: string, target_service: string, payload: array<string, mixed>}
     */
    protected function execute(Request $request): array
    {
        return [
            'type' => 'emby_library_scan',
            'target_service' => 'emby',
            'payload' => [],
        ];
    }

    /**
     * @return array{}
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
