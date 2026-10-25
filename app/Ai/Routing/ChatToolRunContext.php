<?php

declare(strict_types=1);

namespace App\Ai\Routing;

/**
 * The routed tool groups of the chat turn in flight. ChatTurnGuard fills it
 * before the agent runs; routingActive is true whenever routing is enabled
 * for the turn, even when it fell back to every tool. Scoped, so it never
 * leaks into the next Octane request or queued job.
 */
final class ChatToolRunContext
{
    public ?RoutedToolGroups $routed = null;

    public bool $routingActive = false;

    /**
     * What the turn records and returns as its tool groups: the routed groups,
     * "all" when routing was active but the turn had the full toolset, or null
     * when routing was off.
     *
     * @return list<array{group: string, label: string, source: string}>|string|null
     */
    public function toolGroups(): array|string|null
    {
        if ($this->routed instanceof RoutedToolGroups) {
            return $this->routed->toArray();
        }

        return $this->routingActive ? RoutedToolGroups::ALL : null;
    }
}
