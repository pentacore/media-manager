<?php

declare(strict_types=1);

namespace App\Ai\Middleware;

use App\Http\Streaming\ClientConnection;
use Closure;
use Laravel\Ai\PendingStep;

/**
 * Stop a chat run at its next step once the browser behind the stream has
 * gone (the user pressed Stop or left the page), instead of generating and
 * billing up to MaxSteps for nobody. Throwing makes the SDK dispatch
 * AgentFailed, and RecordFailedAgentRun bills every completed step; the step
 * in flight when the user left still finishes. The first step always runs,
 * even for a client already gone: the SDK only stores a failed turn once a
 * step completed, so stopping before it would drop the user's message from
 * the conversation they come back to. One step is the price of keeping it.
 */
final class StopWhenClientDisconnected
{
    public function handle(PendingStep $pendingStep, Closure $next): mixed
    {
        if (! $pendingStep->isFirstStep() && resolve(ClientConnection::class)->disconnected()) {
            throw new ClientDisconnectedException;
        }

        return $next($pendingStep);
    }
}
