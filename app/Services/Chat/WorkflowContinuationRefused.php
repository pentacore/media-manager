<?php

declare(strict_types=1);

namespace App\Services\Chat;

use RuntimeException;

/**
 * A workflow continuation the chat must refuse. $status is the HTTP status the
 * controller answers with: 404 for an unknown or foreign workflow, 422 once it
 * is no longer pending.
 */
final class WorkflowContinuationRefused extends RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
