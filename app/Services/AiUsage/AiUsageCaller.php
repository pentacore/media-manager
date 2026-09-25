<?php

declare(strict_types=1);

namespace App\Services\AiUsage;

use Closure;

/**
 * Labels embeddings/reranking usage rows with the service that asked for
 * them — the SDK events carry no caller.
 */
final class AiUsageCaller
{
    private ?string $caller = null;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function during(string $caller, Closure $callback): mixed
    {
        $previous = $this->caller;
        $this->caller = $caller;

        try {
            return $callback();
        } finally {
            $this->caller = $previous;
        }
    }

    public function current(): ?string
    {
        return $this->caller;
    }
}
