<?php

declare(strict_types=1);

namespace App\Http\Controllers\AI;

use App\Ai\Routing\ToolGroup;
use App\Ai\Routing\ToolGroupCatalogue;
use App\Http\Controllers\Controller;
use App\Settings\AiSettings;
use Illuminate\Http\JsonResponse;

/**
 * The options behind the chat's tool group strip: every routable group and
 * whether this install has any of its tools.
 */
class ChatToolGroupsController extends Controller
{
    public function __invoke(AiSettings $aiSettings, ToolGroupCatalogue $catalogue): JsonResponse
    {
        $available = $catalogue->available();

        return response()->json([
            'routing_enabled' => $aiSettings->chatRoutingEnabled(),
            'groups' => array_map(fn (ToolGroup $toolGroup): array => [
                'value' => $toolGroup->value,
                'label' => $toolGroup->label(),
                'available' => in_array($toolGroup, $available, true),
            ], ToolGroup::cases()),
        ]);
    }
}
