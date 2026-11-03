<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Middleware\ClientDisconnectedException;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Throwable;

/**
 * Whether a run ended because the user pressed Stop (or left the chat).
 * MediaAgent's only cancelling middleware is the client-disconnect `Stop`, so
 * a "cancelled" stream means the user left. A structured sub-agent's
 * disconnect surfaces as a ClientDisconnectedException instead.
 */
final class StoppedByUser
{
    public static function response(AgentResponse $agentResponse): bool
    {
        if (! $agentResponse instanceof StreamedAgentResponse) {
            return false;
        }

        $streamEnd = $agentResponse->events->whereInstanceOf(StreamEnd::class)->last();

        return $streamEnd instanceof StreamEnd && $streamEnd->reason === FinishReason::Cancelled->value;
    }

    public static function exception(Throwable $throwable): bool
    {
        for ($current = $throwable; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof ClientDisconnectedException) {
                return true;
            }
        }

        return false;
    }
}
