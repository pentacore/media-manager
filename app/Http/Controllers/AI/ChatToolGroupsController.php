<?php

declare(strict_types=1);

namespace App\Http\Controllers\AI;

use App\Ai\Agents\MediaAgent;
use App\Ai\Routing\ToolGroup;
use App\Http\Controllers\Controller;
use App\Settings\AiSettings;
use Illuminate\Http\JsonResponse;

/**
 * The options behind the chat's tool group strip: every routable group and
 * whether this install has any of its tools.
 */
class ChatToolGroupsController extends Controller
{
    public function __invoke(AiSettings $aiSettings): JsonResponse
    {
        $declared = array_map(
            static fn (object $tool): string => $tool::class,
            [...(new MediaAgent)->tools()],
        );

        return response()->json([
            'routing_enabled' => $aiSettings->chatRoutingEnabled(),
            'groups' => array_map(static fn (ToolGroup $toolGroup): array => [
                'value' => $toolGroup->value,
                'label' => $toolGroup->label(),
                'available' => array_intersect($toolGroup->toolClasses(), $declared) !== [],
            ], ToolGroup::cases()),
        ]);
    }
}
