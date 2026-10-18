<?php

declare(strict_types=1);

namespace App\Ai;

use Throwable;

/**
 * Which tier of a task's list ran (1-based) and why every tier above it was
 * skipped. Absent when the task's list has a single tier and inherits none.
 * `$via` is the label of the list the outcome came from when that is not the
 * task's own (e.g. "Chat"); null for the task's own list.
 */
final readonly class TierOutcome
{
    /**
     * What every skip reason starts with before the tier number ("Tier 1: ...");
     * via() swaps it for the followed list's label.
     */
    public const string REASON_PREFIX = 'Tier ';

    /**
     * @param  list<string>  $reasons  one entry per skipped tier, "Tier N: why" or "{List} tier N: why"
     */
    public function __construct(
        public int $position,
        public int $count,
        public array $reasons = [],
        public ?string $via = null,
    ) {}

    /**
     * The same outcome seen from a list that followed this one: reasons are
     * relabelled "{label} tier N: ...". Keeps an existing via, which names the
     * list that actually ran.
     */
    public function via(string $label): self
    {
        if ($this->via !== null) {
            return $this;
        }

        return new self(
            $this->position,
            $this->count,
            array_map(static fn (string $reason): string => preg_replace('/^'.preg_quote(self::REASON_PREFIX, '/').'/', $label.' tier ', $reason) ?? $reason, $this->reasons),
            $label,
        );
    }

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

    /**
     * The label of the list an agent run followed (e.g. "Chat") when its tier
     * came from another task's list; null for its own list or no tiers. Never
     * throws, like positionFor().
     */
    public static function viaFor(object $agent): ?string
    {
        if (! method_exists($agent, 'resolvedSelection')) {
            return null;
        }

        try {
            return $agent->resolvedSelection()->tier?->via;
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
     * @return array{position: int, count: int, reason: string|null, via: string|null}
     */
    public function toArray(): array
    {
        return ['position' => $this->position, 'count' => $this->count, 'reason' => $this->reason(), 'via' => $this->via];
    }
}
