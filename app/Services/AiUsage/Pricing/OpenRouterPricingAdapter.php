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
 * Pure translation of OpenRouter's models list into `openrouter` pricing
 * candidates. OpenRouter quotes USD per token as decimal strings; the catalog
 * stores USD per million tokens. No database access.
 */
final class OpenRouterPricingAdapter
{
    private const string PROVIDER = 'openrouter';

    /**
     * OpenRouter pricing keys mapped to their catalog column.
     *
     * @var array<string, string>
     */
    private const array PRICE_COLUMN_MAP = [
        'prompt' => 'input_per_mtok',
        'completion' => 'output_per_mtok',
        'input_cache_read' => 'cache_read_per_mtok',
        'input_cache_write' => 'cache_write_per_mtok',
    ];

    /**
     * @param  list<mixed>  $models  The `data` list from {@see OpenRouterPricingClient}.
     */
    public function adapt(array $models, RefreshScope $refreshScope): ?ProviderPricingResult
    {
        if (! $refreshScope->allowsProvider(self::PROVIDER)) {
            return null;
        }

        $candidates = [];
        $rejections = [];
        $warnings = [];

        foreach ($models as $model) {
            $rawId = is_array($model) ? ($model['id'] ?? null) : null;
            $modelId = is_string($rawId) ? PricingModelIds::normalize($rawId) : null;

            if ($modelId === null || ! is_array($model)) {
                $rejections[] = new PricingRejection(self::PROVIDER, is_string($rawId) ? $rawId : '', PricingRejection::INVALID_IDENTIFIER);

                continue;
            }

            if (str_contains($modelId, ':')) {
                $rejections[] = new PricingRejection(self::PROVIDER, $modelId, PricingRejection::VARIANT);

                continue;
            }

            if (! $refreshScope->allowsWrite(self::PROVIDER, $modelId)) {
                continue;
            }

            [$candidate, $rejection, $warning] = $this->adaptModel($modelId, $model);

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
        $outputModalities = is_array($modelData['architecture'] ?? null)
            ? ($modelData['architecture']['output_modalities'] ?? null)
            : null;

        if (! is_array($outputModalities)) {
            return [null, new PricingRejection(self::PROVIDER, $modelId, PricingRejection::NON_TEXT_OUTPUT, 'missing_modalities'), null];
        }

        if (! in_array('text', $outputModalities, true)) {
            return [null, new PricingRejection(self::PROVIDER, $modelId, PricingRejection::NON_TEXT_OUTPUT, 'declared_non_text'), null];
        }

        $pricing = $modelData['pricing'] ?? null;

        if (! is_array($pricing)) {
            return [null, new PricingRejection(self::PROVIDER, $modelId, PricingRejection::MISSING_COST), null];
        }

        if (! array_key_exists('prompt', $pricing)) {
            return [null, new PricingRejection(self::PROVIDER, $modelId, PricingRejection::MISSING_INPUT), null];
        }

        if (! array_key_exists('completion', $pricing)) {
            return [null, new PricingRejection(self::PROVIDER, $modelId, PricingRejection::MISSING_OUTPUT), null];
        }

        $fields = [];

        foreach (self::PRICE_COLUMN_MAP as $key => $column) {
            if (! array_key_exists($key, $pricing)) {
                $fields[$column] = CandidatePriceField::missing();

                continue;
            }

            $value = $this->perMillion($pricing[$key]);

            if ($value === null) {
                return [null, new PricingRejection(self::PROVIDER, $modelId, PricingRejection::INVALID_COST, $key), null];
            }

            $fields[$column] = CandidatePriceField::of($value);
        }

        $reasoningField = $this->reasoningField($pricing, $fields['output_per_mtok']);

        if ($reasoningField === null) {
            return [null, new PricingRejection(self::PROVIDER, $modelId, PricingRejection::INVALID_COST, 'internal_reasoning'), null];
        }

        $fields['reasoning_per_mtok'] = $reasoningField;

        $tiered = is_array($pricing['overrides'] ?? null) && $pricing['overrides'] !== [];

        $modelPriceCandidate = new ModelPriceCandidate(
            provider: self::PROVIDER,
            model: $modelId,
            fields: $fields,
            source: PricingSource::OpenRouter,
            sourceUrl: $this->sourceUrl(),
            tiered: $tiered,
        );

        $warning = $tiered
            ? new PricingWarning(self::PROVIDER, $modelId, PricingWarning::CONTEXT_TIERS, 'overrides')
            : null;

        return [$modelPriceCandidate, null, $warning];
    }

    /**
     * `reasoning_per_mtok` reads OpenRouter's `internal_reasoning` rate when it
     * is supplied and greater than zero. OpenRouter reports it as `0` (or omits
     * it) for most models because reasoning tokens are billed at the
     * completion rate, so a missing or explicit-zero value falls back to the
     * already-resolved completion (output) rate instead of writing zero.
     * Returns null when a supplied value is invalid.
     *
     * @param  array<string, mixed>  $pricing
     */
    private function reasoningField(array $pricing, CandidatePriceField $outputField): ?CandidatePriceField
    {
        if (! array_key_exists('internal_reasoning', $pricing)) {
            return $outputField;
        }

        $value = $this->perMillion($pricing['internal_reasoning']);

        if ($value === null) {
            return null;
        }

        return $value === '0' ? $outputField : CandidatePriceField::of($value);
    }

    /**
     * Convert a USD-per-token value to a USD-per-million decimal string, or
     * null when it is invalid, negative, or beyond the catalog range.
     */
    private function perMillion(mixed $value): ?string
    {
        $normalized = PriceNumber::normalize($value);

        if ($normalized === null) {
            return null;
        }

        $perMillion = PriceNumber::shiftDecimal($normalized, 6);

        return PriceNumber::withinColumnRange($perMillion) ? $perMillion : null;
    }

    private function sourceUrl(): ?string
    {
        $url = config('mediamanager.ai.pricing.openrouter.url');

        return is_string($url) && $url !== '' ? $url : null;
    }
}
