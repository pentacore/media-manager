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
        $emittedIds = [];

        foreach ($models as $model) {
            $rawId = is_array($model) ? ($model['id'] ?? null) : null;
            $modelId = is_string($rawId) ? PricingModelIds::normalize($rawId) : null;

            if ($modelId === null || ! is_array($model)) {
                $rejections[] = new PricingRejection(self::PROVIDER, is_string($rawId) ? $rawId : '', PricingRejection::INVALID_IDENTIFIER);

                continue;
            }

            if (! $refreshScope->allowsWrite(self::PROVIDER, $modelId)) {
                continue;
            }

            [$candidate, $rejection, $warning] = $this->adaptModel($modelId, $model);

            if ($candidate instanceof ModelPriceCandidate) {
                $candidates[] = $candidate;
                $emittedIds[$modelId] = true;

                foreach ($this->aliasCandidates($candidate, $model, $refreshScope, $emittedIds) as $aliasCandidate) {
                    $candidates[] = $aliasCandidate;
                    $emittedIds[$aliasCandidate->model] = true;
                }
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
     * Emit one candidate per string `aliases` entry, sharing the canonical
     * model's fields, source, and tiered flag exactly (xAI aliases are the
     * same priced model under another name, for example `grok-5-latest` for
     * `grok-5`). Each alias is normalized and subject to the same write scope
     * as the canonical id; an invalid, already-emitted, or scope-excluded
     * alias is skipped silently — never a rejection.
     *
     * @param  array<string, mixed>  $modelData
     * @param  array<string, true>  $emittedIds  Model ids already emitted this run.
     * @return list<ModelPriceCandidate>
     */
    private function aliasCandidates(ModelPriceCandidate $candidate, array $modelData, RefreshScope $refreshScope, array $emittedIds): array
    {
        $aliases = $modelData['aliases'] ?? null;

        if (! is_array($aliases)) {
            return [];
        }

        $aliasCandidates = [];

        foreach ($aliases as $alias) {
            if (! is_string($alias)) {
                continue;
            }

            $aliasId = PricingModelIds::normalize($alias);

            if ($aliasId === null || isset($emittedIds[$aliasId])) {
                continue;
            }

            if (! $refreshScope->allowsWrite(self::PROVIDER, $aliasId)) {
                continue;
            }

            $emittedIds[$aliasId] = true;

            $aliasCandidates[] = new ModelPriceCandidate(
                provider: $candidate->provider,
                model: $aliasId,
                fields: $candidate->fields,
                source: $candidate->source,
                sourceUrl: $candidate->sourceUrl,
                sourceUpdatedAt: $candidate->sourceUpdatedAt,
                tiered: $candidate->tiered,
            );
        }

        return $aliasCandidates;
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
        $thresholdPositive = is_int($threshold) && $threshold > 0;
        $tiered = $thresholdPositive || $this->hasPositiveLongContextRate($modelData);

        $modelPriceCandidate = new ModelPriceCandidate(
            provider: self::PROVIDER,
            model: $modelId,
            fields: $fields,
            source: PricingSource::XaiApi,
            sourceUrl: $this->sourceUrl(),
            tiered: $tiered,
        );

        $warning = $tiered
            ? new PricingWarning(self::PROVIDER, $modelId, PricingWarning::CONTEXT_TIERS, $thresholdPositive ? sprintf('long_context_threshold:%d', $threshold) : 'long_context')
            : null;

        return [$modelPriceCandidate, null, $warning];
    }

    /**
     * Whether the model declares a positive rate under any key ending in
     * `_long_context` (for example `prompt_text_token_price_long_context`),
     * regardless of `long_context_threshold` — xAI sometimes ships a
     * long-context rate without a matching threshold.
     *
     * @param  array<string, mixed>  $modelData
     */
    private function hasPositiveLongContextRate(array $modelData): bool
    {
        foreach ($modelData as $key => $value) {
            if (! str_ends_with($key, '_long_context')) {
                continue;
            }

            if (is_numeric($value) && (float) $value > 0) {
                return true;
            }
        }

        return false;
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
