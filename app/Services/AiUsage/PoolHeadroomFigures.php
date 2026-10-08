<?php

declare(strict_types=1);

namespace App\Services\AiUsage;

/**
 * How much of one free pool is left in its current period, measured on the
 * tightest capped dimension (the unified total, or the lower of input and
 * output for split pools; a dimension without a cap is ignored).
 */
final readonly class PoolHeadroomFigures
{
    public function __construct(
        public string $name,
        public float $percentLeft,
        public int $tokensLeft,
    ) {}

    /**
     * Figures for one FreePoolAccounting::status() row, or null when the pool
     * caps nothing (it can never run dry).
     *
     * @param  array{name: string, unified: bool, free_input: int|null, free_output: int|null, free_total: int|null, used_input: int, used_output: int, used_total: int}  $status
     */
    public static function fromStatus(array $status): ?self
    {
        $dimensions = $status['unified']
            ? [[$status['free_total'], $status['used_total']]]
            : [[$status['free_input'], $status['used_input']], [$status['free_output'], $status['used_output']]];

        $percents = [];
        $remaining = [];

        foreach ($dimensions as [$cap, $used]) {
            if ($cap === null) {
                continue;
            }

            $left = max(0, $cap - $used);
            $remaining[] = $left;
            $percents[] = $cap > 0 ? round($left / $cap * 100, 2) : 0.0;
        }

        if ($remaining === []) {
            return null;
        }

        return new self($status['name'], min($percents), min($remaining));
    }

    /**
     * @param  array{name: string, percent_left: float, tokens_left: int}  $cached
     */
    public static function fromArray(array $cached): self
    {
        return new self($cached['name'], (float) $cached['percent_left'], (int) $cached['tokens_left']);
    }

    /**
     * @return array{name: string, percent_left: float, tokens_left: int}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'percent_left' => $this->percentLeft, 'tokens_left' => $this->tokensLeft];
    }
}
