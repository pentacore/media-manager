<?php

declare(strict_types=1);

namespace App\Services\Bazarr;

use App\Services\MediaReplacement\LanguageNormalizer;

final readonly class SubtitleCandidateEligibility
{
    public function __construct(private LanguageNormalizer $languageNormalizer) {}

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $requirement
     * @param  array<string, mixed>  $context
     */
    public function classify(array $candidate, array $requirement, array $context): string
    {
        $language = $this->languageNormalizer->normalize($candidate['language'] ?? null);
        $requiredLanguage = $this->languageNormalizer->normalize($requirement['code'] ?? null);
        $provider = $candidate['provider'] ?? null;
        $fingerprint = $candidate['fingerprint'] ?? null;
        $score = $candidate['score'] ?? null;

        if ($language === null
            || $requiredLanguage === null
            || ! is_string($provider)
            || $provider === ''
            || ! is_string($fingerprint)
            || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1
            || ! is_bool($candidate['forced'] ?? null)
            || ! is_bool($candidate['hearing_impaired'] ?? null)) {
            return 'malformed';
        }

        if (($context['threshold_available'] ?? false) !== true
            || ! is_numeric($context['minimum_score'] ?? null)) {
            return 'capability_limited';
        }

        if (! is_numeric($score)) {
            return 'malformed';
        }

        if ($language !== $requiredLanguage) {
            return 'wrong_language';
        }

        if (($candidate['forced'] ?? null) !== ($requirement['forced'] ?? false)
            || ($candidate['hearing_impaired'] ?? null) !== ($requirement['hearing_impaired'] ?? false)) {
            return 'wrong_qualifier';
        }

        $availableProviders = is_array($context['available_providers'] ?? null)
            ? $context['available_providers']
            : [];

        if (! in_array($provider, $availableProviders, true)) {
            return 'provider_unavailable';
        }

        if ((float) $score < (float) $context['minimum_score']) {
            return 'below_threshold';
        }

        return 'eligible';
    }
}
