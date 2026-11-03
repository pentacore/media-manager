<?php

declare(strict_types=1);

namespace App\Ai\Middleware;

use RuntimeException;

/**
 * Thrown by StopWhenClientDisconnected to end a structured sub-agent run
 * whose chat browser has gone. Not failoverable, so the SDK records it as the
 * sub-agent's AgentFailed; laravel/ai's AgentTool then degrades it to an
 * "Agent failed: …" tool result for the parent (StoppedByUser::toolResult()).
 */
final class ClientDisconnectedException extends RuntimeException
{
    public const string MESSAGE = 'The chat client disconnected; the turn was stopped before its next step.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
