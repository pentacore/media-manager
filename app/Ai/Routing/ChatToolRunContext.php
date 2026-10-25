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
}
