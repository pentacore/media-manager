<?php

declare(strict_types=1);

namespace App\Ai\Decision;

/**
 * What the stuck-import classifier fast path did with an event: left it to the
 * agent, resolved it with nothing to notify about, or resolved it with a
 * summary to notify an admin with.
 */
final readonly class FastPathResolution
{
    private function __construct(public bool $resolved, public ?string $notification) {}

    /**
     * The fast path did not resolve the event: the agent runs as before.
     */
    public static function unresolved(): self
    {
        return new self(false, null);
    }

    /**
     * Resolved and recorded; there is nothing to notify about.
     */
    public static function resolvedQuietly(): self
    {
        return new self(true, null);
    }

    /**
     * Resolved and recorded; the summary is what an admin is notified with.
     */
    public static function resolvedWithSummary(string $summary): self
    {
        return new self(true, $summary);
    }
}
