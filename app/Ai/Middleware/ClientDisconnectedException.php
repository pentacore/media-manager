<?php

declare(strict_types=1);

namespace App\Ai\Middleware;

use RuntimeException;

/**
 * Thrown by StopWhenClientDisconnected to end a chat run whose browser has
 * gone. Not failoverable, so the SDK records it as the run's AgentFailed.
 */
final class ClientDisconnectedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The chat client disconnected; the turn was stopped before its next step.');
    }
}
