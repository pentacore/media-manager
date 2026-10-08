<?php

declare(strict_types=1);

namespace App\Ai;

use Throwable;

/**
 * Which tier of a task's list ran (1-based) and why every tier above it was
 * skipped. Absent when the task's list has a single tier and inherits none.
 */
final readonly class TierOutcome
{
    /**
     * @param  list<string>  $reasons  one entry per skipped tier, "Tier N: why"
     */
    public function __construct(
        public int $position,
        public int $count,
        public array $reasons = [],
    ) {}

    /**
     * The tier an agent run used, for its usage row; null for agents that do
     * not run as an AI task or ran without tiers. Never throws: losing the
     * tier must not lose the usage row.
     */
    public static function positionFor(object $agent): ?int
    {
        if (! method_exists($agent, 'resolvedSelection')) {
            return null;
        }

        try {
            return $agent->resolvedSelection()->tier?->position;
        } catch (Throwable $throwable) {
            report($throwable);

            return null;
        }
    }

    public function reason(): ?string
    {
        return $this->reasons === [] ? null : implode('; ', $this->reasons);
    }

    /**
     * @return array{position: int, count: int, reason: string|null}
     */
    public function toArray(): array
    {
        return ['position' => $this->position, 'count' => $this->count, 'reason' => $this->reason()];
    }
}
