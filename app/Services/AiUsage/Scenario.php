<?php

declare(strict_types=1);

namespace App\Services\AiUsage;

final readonly class Scenario
{
    public function __construct(
        public float $inputPerMtok,
        public float $outputPerMtok,
        public float $cacheReadPerMtok,
        public float $cacheWritePerMtok,
        public float $reasoningPerMtok,
    ) {}

    /**
     * Build a scenario from a set of query-string-style rate values.
     * Returns null if any required value is missing or non-numeric.
     *
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values): ?self
    {
        $keys = ['input', 'output', 'cache_read', 'cache_write', 'reasoning'];
        $rates = [];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $values)) {
                return null;
            }

            $value = $values[$key];

            if (! is_numeric($value) || (float) $value < 0) {
                return null;
            }

            $rates[] = (float) $value;
        }

        return new self(...$rates);
    }

    /**
     * @return array{input: float, output: float, cache_read: float, cache_write: float, reasoning: float}
     */
    public function toArray(): array
    {
        return [
            'input' => $this->inputPerMtok,
            'output' => $this->outputPerMtok,
            'cache_read' => $this->cacheReadPerMtok,
            'cache_write' => $this->cacheWritePerMtok,
            'reasoning' => $this->reasoningPerMtok,
        ];
    }
}
