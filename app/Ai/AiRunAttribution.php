<?php

declare(strict_types=1);

namespace App\Ai;

use App\Models\User;
use Closure;

/**
 * Who triggered the AI run currently executing in this request/job, for
 * usage attribution when the SDK response carries no conversation user
 * (one-shot agents run from queued jobs, where Auth::id() is null).
 * Replaces the 0.10 AttributesToUser middleware: 1.0 middleware wraps each
 * step and can no longer stamp the final response.
 */
final class AiRunAttribution
{
    private ?User $user = null;

    public function attributeTo(?User $user): void
    {
        $this->user = $user;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function during(?User $user, Closure $callback): mixed
    {
        $previous = $this->user;
        $this->user = $user;

        try {
            return $callback();
        } finally {
            $this->user = $previous;
        }
    }
}
