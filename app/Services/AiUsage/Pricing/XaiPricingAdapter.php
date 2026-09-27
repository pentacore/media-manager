<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

use App\Enums\PricingSource;
use App\Services\AiUsage\Pricing\Data\CandidatePriceField;
use App\Services\AiUsage\Pricing\Data\ModelPriceCandidate;
use App\Services\AiUsage\Pricing\Data\PricingRejection;
use App\Services\AiUsage\Pricing\Data\PricingWarning;
use App\Services\AiUsage\Pricing\Data\ProviderPricingResult;

/**
 * Pure translation of xAI's language-model list into `xai` candidates. xAI
 * quotes USD cents per 100M tokens as integers; the catalog stores USD per
 * million tokens, i.e. the value divided by 10^4. No database access.
 */
final class XaiPricingAdapter
{
    private const string PROVIDER = 'xai';

    /**
     * xAI price keys mapped to their catalog column. xAI publishes no cache
     * write or reasoning rate, so those columns stay missing.
     *
     * @var array<string, string>
     */
    private const array PRICE_COLUMN_MAP = [
        'prompt_text_token_price' => 'input_per_mtok',
        'completion_text_token_price' => 'output_per_mtok',
        'cached_prompt_text_token_price' => 'cache_read_per_mtok',
    ];

    /**
     * @param  list<mixed>  $models  The `models` list from {@see XaiPricingClient}.
     */
    public function adapt(array $models, RefreshScope $refreshScope): ?ProviderPricingResult
    {
        if (! $refreshScope->allowsProvider(self::PROVIDER)) {
            return null;
        }

        $candidates = [];
        $rejections = [];
        $warnings = [];

        foreach ($models as $modelData) {
            $rawId = is_array($modelData) ? ($modelData['id'] ?? null) : null;
            $modelId = is_string($rawId) ? PricingModelIds::normalize($rawId) : null;

            if ($modelId === null || ! is_array($modelData)) {
                $rejections[] = new PricingRejection(self::PROVIDER, is_string($rawId) ? $rawId : '', PricingRejection::INVALID_IDENTIFIER);

                continue;
            }

            if (! $refreshScope->allowsWrite(self::PROVIDER, $modelId)) {
                continue;
            }

            [$candidate, $rejection, $warning] = $this->adaptModel($modelId, $modelData);

            if ($candidate instanceof ModelPriceCandidate) {
                $candidates[] = $candidate;
            }

            if ($rejection instanceof PricingRejection) {
                $rejections[] = $rejection;
            }

            if ($warning instanceof PricingWarning) {
                $warnings[] = $warning;
            }
        }

        return new ProviderPricingResult(
            provider: self::PROVIDER,
            candidates: $candidates,
            rejections: $rejections,
            warnings: $warnings,
            createSuppressed: ! $refreshScope->allowsCreate(self::PROVIDER),
        );
    }

    /**
     * @param  array<string, mixed>  $modelData
     * @return array{0: ?ModelPriceCandidate, 1: ?PricingRejection, 2: ?PricingWarning}
     */
    private function adaptModel(string $modelId, array $modelData): array
    {
        $outputModalities = $modelData['output_modalities'] ?? null;

        if (! is_array($outputModalities)) {
            return [null, new PricingRejection(self::PROVIDER, $modelId, PricingRejection::NON_TEXT_OUTPUT, 'missing_modalities'), null];
        }

        if (! in_array('text', $outputModalities, true)) {
            return [null, new PricingRejection(self::PROVIDER, $modelId, PricingRejection::NON_TEXT_OUTPUT, 'declared_non_text'), null];
        }

        if (! array_key_exists('prompt_text_token_price', $modelData)) {
            return [null, new PricingRejection(self::PROVIDER, $modelId, PricingRejection::MISSING_INPUT), null];
        }

        if (! array_key_exists('completion_text_token_price', $modelData)) {
            return [null, new PricingRejection(self::PROVIDER, $modelId, PricingRejection::MISSING_OUTPUT), null];
        }

        $fields = [
            'cache_write_per_mtok' => CandidatePriceField::missing(),
            'reasoning_per_mtok' => CandidatePriceField::missing(),
        ];

        foreach (self::PRICE_COLUMN_MAP as $key => $column) {
            if (! array_key_exists($key, $modelData)) {
                $fields[$column] = CandidatePriceField::missing();

                continue;
            }

            $value = $this->usdPerMillion($modelData[$key]);

            if ($value === null) {
                return [null, new PricingRejection(self::PROVIDER, $modelId, PricingRejection::INVALID_COST, $key), null];
            }

            $fields[$column] = CandidatePriceField::of($value);
        }

        $threshold = $modelData['long_context_threshold'] ?? 0;
        $tiered = is_int($threshold) && $threshold > 0;

        $candidate = new ModelPriceCandidate(
            provider: self::PROVIDER,
            model: $modelId,
            fields: $fields,
            source: PricingSource::XaiApi,
            sourceUrl: $this->sourceUrl(),
            tiered: $tiered,
        );

        $warning = $tiered
            ? new PricingWarning(self::PROVIDER, $modelId, PricingWarning::CONTEXT_TIERS, sprintf('long_context_threshold:%d', $threshold))
            : null;

        return [$candidate, null, $warning];
    }

    /**
     * Convert USD cents per 100M tokens to a USD-per-million decimal string.
     */
    private function usdPerMillion(mixed $value): ?string
    {
        $normalized = PriceNumber::normalize($value);

        if ($normalized === null) {
            return null;
        }

        $perMillion = PriceNumber::shiftDecimal($normalized, -4);

        return PriceNumber::withinColumnRange($perMillion) ? $perMillion : null;
    }

    private function sourceUrl(): ?string
    {
        $url = config('mediamanager.ai.pricing.xai.url');

        return is_string($url) && $url !== '' ? $url : null;
    }
}
