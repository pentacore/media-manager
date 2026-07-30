<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing\Data;

/**
 * A single candidate pricing value that preserves the distinction between an
 * explicitly supplied value (including an explicit zero) and a missing value.
 *
 * Automatic pricing paths must never coalesce a missing value to zero before
 * merge semantics run; this object carries the "supplied" flag so the writer
 * can decide whether to touch the corresponding column.
 */
final readonly class CandidatePriceField
{
    public function __construct(
        public bool $supplied,
        public ?string $value,
    ) {}

    /**
     * A field with no upstream value; the writer must not alter the column.
     */
    public static function missing(): self
    {
        return new self(supplied: false, value: null);
    }

    /**
     * A field with an explicitly supplied value, including an explicit zero.
     */
    public static function of(string $value): self
    {
        return new self(supplied: true, value: $value);
    }
}
