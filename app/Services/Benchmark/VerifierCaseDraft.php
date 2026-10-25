<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

/**
 * The case input captured for a recorded second opinion, with the verdict a
 * person ruled (null when none) and what the admin should double-check.
 */
final readonly class VerifierCaseDraft
{
    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $input,
        public ?bool $expectedAgrees,
        public array $warnings,
        public ?string $name,
    ) {}
}
