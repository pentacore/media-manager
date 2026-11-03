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
 * disconnect surfaces as a ClientDisconnectedException on its own run, and as
 * an "Agent failed: …" result on the parent's tool call.
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

    /**
     * Whether a sub-agent tool call's result is the one a client disconnect
     * leaves. laravel/ai's AgentTool catches every exception the sub-agent
     * throws and degrades it to an "Agent failed: <message>" result so the
     * parent run survives, so a structured sub-agent's
     * ClientDisconnectedException never reaches the parent as an exception —
     * only this exact string does.
     */
    public static function toolResult(mixed $result): bool
    {
        return $result === 'Agent failed: '.ClientDisconnectedException::MESSAGE;
    }
}
