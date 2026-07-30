<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

use InvalidArgumentException;

/**
 * Configured relative-change policy that decides whether a candidate price is
 * an implausible jump from the existing stored value.
 *
 * The maximum increase and minimum decrease ratios are read from
 * `mediamanager.ai.pricing.max_increase_ratio` and
 * `mediamanager.ai.pricing.min_decrease_ratio`, defaulting to 4x and 0.25x until
 * the pricing configuration is published.
 */
final readonly class PricingAnomalyPolicy
{
    private const string DEFAULT_MAX_INCREASE_RATIO = '4';

    private const string DEFAULT_MIN_DECREASE_RATIO = '0.25';

    private int $maxIncreaseNumerator;

    private int $maxIncreaseDenominator;

    private int $minDecreaseNumerator;

    private int $minDecreaseDenominator;

    public function __construct()
    {
        [$this->maxIncreaseNumerator, $this->maxIncreaseDenominator] = $this->fraction((string) config(
            'mediamanager.ai.pricing.max_increase_ratio',
            self::DEFAULT_MAX_INCREASE_RATIO,
        ));

        [$this->minDecreaseNumerator, $this->minDecreaseDenominator] = $this->fraction((string) config(
            'mediamanager.ai.pricing.min_decrease_ratio',
            self::DEFAULT_MIN_DECREASE_RATIO,
        ));
    }

    /**
     * Whether moving from $existing to $candidate exceeds the configured
     * increase or decrease ratio bounds. A non-positive existing value has no
     * meaningful ratio, so a rise from zero is never treated as anomalous.
     */
    public function isAnomalous(string $existing, string $candidate): bool
    {
        [$existingNumerator, $existingDenominator] = $this->fraction($existing);
        [$candidateNumerator, $candidateDenominator] = $this->fraction($candidate);

        if ($existingNumerator <= 0) {
            return false;
        }

        $candidateSide = $candidateNumerator * $existingDenominator;
        $existingSide = $existingNumerator * $candidateDenominator;

        $exceedsMaximum = $candidateSide * $this->maxIncreaseDenominator
            > $existingSide * $this->maxIncreaseNumerator;
        $fallsBelowMinimum = $candidateSide * $this->minDecreaseDenominator
            < $existingSide * $this->minDecreaseNumerator;

        return $exceedsMaximum || $fallsBelowMinimum;
    }

    /**
     * Parse a plain decimal string as an exact integer fraction.
     *
     * @return array{0: int, 1: int}
     */
    private function fraction(string $decimal): array
    {
        $decimal = trim($decimal);

        throw_if(preg_match('/^(?<sign>[+-]?)(?<whole>\d+)(?:\.(?<fraction>\d+))?$/D', $decimal, $matches) !== 1, InvalidArgumentException::class, sprintf('Invalid decimal value [%s].', $decimal));

        $fraction = $matches['fraction'] ?? '';
        $numerator = (int) ($matches['whole'].$fraction);

        if ($matches['sign'] === '-') {
            $numerator *= -1;
        }

        return [$numerator, 10 ** strlen($fraction)];
    }
}
