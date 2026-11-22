<?php

declare(strict_types=1);

namespace App\Ai\Middleware;

use RuntimeException;

/**
 * Thrown by StopWhenClientDisconnected to end a structured sub-agent run
 * whose chat browser has gone. Not failoverable, so the SDK records it as the
 * sub-agent's AgentFailed; laravel/ai then fails the parent's AgentTool call
 * with it (ToolFailed, recorded as stopped by RecordToolFailure) and hands
 * the parent a failed tool result. Not reported: a Stop is not an error.
 */
final class ClientDisconnectedException extends RuntimeException
{
    public const string MESSAGE = 'The chat client disconnected; the turn was stopped before its next step.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
