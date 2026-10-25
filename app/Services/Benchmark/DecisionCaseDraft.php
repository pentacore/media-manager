<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

/**
 * The case input captured from a recorded decision run, with what the admin
 * should double-check before saving it.
 */
final readonly class DecisionCaseDraft
{
    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $input,
        public array $warnings,
        public bool $truncated,
        public ?string $eventLabel,
    ) {}
}
