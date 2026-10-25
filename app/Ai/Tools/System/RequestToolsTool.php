<?php

declare(strict_types=1);

namespace App\Ai\Tools\System;

use App\Ai\Risk;
use App\Ai\Routing\ChatToolRunContext;
use App\Ai\Routing\RoutedToolGroups;
use App\Ai\Routing\ToolGroup;
use App\Ai\Routing\ToolGroupCatalogue;
use App\Ai\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Ai\Tools\Request;
use Stringable;

class RequestToolsTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Ask the user to load tool groups this turn does not have, found with FindToolsTool. The user sees Load / No thanks buttons; after calling it, end your turn and wait for their answer.';
    }

    public function risk(): Risk
    {
        return Risk::Read;
    }

    /**
     * @return array<string, mixed>
     */
    protected function execute(Request $request): array
    {
        $validated = $request->validate([
            'groups' => ['required', 'array', 'min:1', 'max:3'],
            'groups.*' => ['required', 'string', Rule::enum(ToolGroup::class), 'distinct'],
            'reason' => ['required', 'string', 'max:200'],
        ]);

        $groups = array_map(ToolGroup::from(...), array_values($validated['groups']));
        $chatToolRunContext = resolve(ChatToolRunContext::class);

        if (! $chatToolRunContext->routed instanceof RoutedToolGroups
            || array_filter($groups, static fn (ToolGroup $toolGroup): bool => in_array($toolGroup, $chatToolRunContext->routed->groups(), true)) !== []) {
            return [
                'error' => 'already_loaded',
                'message' => 'Every tool is already loaded, or one of these groups is. Use the tools directly.',
            ];
        }

        $available = resolve(ToolGroupCatalogue::class)->available();

        if (array_filter($groups, static fn (ToolGroup $toolGroup): bool => ! in_array($toolGroup, $available, true)) !== []) {
            return [
                'error' => 'not_available',
                'message' => 'One of these tool groups is not available on this install. Tell the user plainly.',
            ];
        }

        return [
            'tool_request' => [
                'groups' => array_map(static fn (ToolGroup $toolGroup): array => [
                    'group' => $toolGroup->value,
                    'label' => $toolGroup->label(),
                ], $groups),
                'reason' => trim($validated['reason']),
            ],
            'instruction' => 'The user sees Load / No thanks buttons. End your turn now and wait for their answer.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'groups' => $schema->array()->items($schema->string()->enum(array_map(static fn (ToolGroup $toolGroup): string => $toolGroup->value, ToolGroup::cases())))->description('1 to 3 tool groups from FindToolsTool to ask the user to load.')->required(),
            'reason' => $schema->string()->description('Why you need them, shown to the user, max 200 characters.')->required(),
        ];
    }
}
