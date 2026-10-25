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
use Laravel\Ai\Tools\Request;
use Stringable;

class FindToolsTool extends BaseTool
{
    public function description(): Stringable|string
    {
        return 'Search every tool group, including ones not loaded this turn, for the tools that cover a need. Describe the need in a sentence. Each match says whether its group is already loaded.';
    }

    public function risk(): Risk
    {
        return Risk::Read;
    }

    /**
     * @return array{matches: list<array{group: string, label: string, loaded: bool, tools: list<array{name: string, summary: string}>}>, hint: string}
     */
    protected function execute(Request $request): array
    {
        $validated = $request->validate([
            'need' => ['required', 'string', 'max:300'],
        ]);

        $routed = resolve(ChatToolRunContext::class)->routed;
        $loaded = $routed instanceof RoutedToolGroups ? array_map(static fn (ToolGroup $toolGroup): string => $toolGroup->value, $routed->groups()) : null;

        $matches = array_map(static fn (array $match): array => [
            ...$match,
            'loaded' => $loaded === null || in_array($match['group'], $loaded, true),
        ], resolve(ToolGroupCatalogue::class)->search($validated['need']));

        $hasUnloaded = array_filter($matches, static fn (array $match): bool => ! $match['loaded']) !== [];

        return [
            'matches' => $matches,
            'hint' => match (true) {
                $matches === [] => 'No tool group covers this; tell the user plainly.',
                $hasUnloaded => 'Call RequestToolsTool with the group to ask the user to load it.',
                default => 'Every matching group is already loaded; use its tools directly.',
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'need' => $schema->string()->description('What you need to do, in a sentence, max 300 characters.')->required(),
        ];
    }
}
