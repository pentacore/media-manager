<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

use App\Enums\PricingSource;
use App\Services\AiUsage\Pricing\Data\CandidatePriceField;
use App\Services\AiUsage\Pricing\Data\ModelPriceCandidate;
use App\Services\AiUsage\Pricing\Data\PricingRejection;
use App\Services\AiUsage\Pricing\Data\PricingWarning;
use App\Services\AiUsage\Pricing\Data\ProviderPricingResult;
use App\Services\AiUsage\Pricing\Data\ReasoningCapability;

/**
 * Pure merge of one provider's models.dev and LiteLLM slices.
 *
 * - Both feeds price a model and agree on input and output (at the catalog's
 *   four decimals) → one `FeedConsensus` candidate.
 * - Only one feed prices it → that feed's candidate, unchanged.
 * - Both price it and disagree → no candidate; the model id is returned in
 *   {@see ProviderPricingResult::$conflicts} for the verifier.
 *
 * Optional rates on a consensus candidate come from models.dev when supplied,
 * else LiteLLM; a disagreement on an optional rate only warns.
 */
final class PricingReconciler
{
    /**
     * @var list<string>
     */
    private const array PRIMARY_COLUMNS = ['input_per_mtok', 'output_per_mtok'];

    /**
     * @var list<string>
     */
    private const array OPTIONAL_COLUMNS = ['cache_read_per_mtok', 'cache_write_per_mtok', 'reasoning_per_mtok'];

    public function reconcile(string $provider, ?ProviderPricingResult $modelsDev, ?ProviderPricingResult $liteLlm): ?ProviderPricingResult
    {
        $modelsDevUsable = $modelsDev instanceof ProviderPricingResult && ! $this->isMalformed($modelsDev);
        $liteLlmUsable = $liteLlm instanceof ProviderPricingResult && ! $this->isMalformed($liteLlm);

        if (! $modelsDevUsable && ! $liteLlmUsable) {
            return $modelsDev ?? $liteLlm;
        }

        if (! $liteLlmUsable) {
            return $modelsDev;
        }

        if (! $modelsDevUsable) {
            return $liteLlm;
        }

        return $this->merge($provider, $modelsDev, $liteLlm);
    }

    private function merge(string $provider, ProviderPricingResult $modelsDev, ProviderPricingResult $liteLlm): ProviderPricingResult
    {
        $primary = $this->byModel($modelsDev->candidates);
        $secondary = $this->byModel($liteLlm->candidates);

        $candidates = [];
        $conflicts = [];
        $rateWarnings = [];

        foreach (array_unique([...array_keys($primary), ...array_keys($secondary)]) as $model) {
            $model = (string) $model;
            $first = $primary[$model] ?? null;
            $second = $secondary[$model] ?? null;

            if ($first === null || $second === null) {
                $candidates[] = $first ?? $second;

                continue;
            }

            if (! $this->primaryRatesAgree($first, $second)) {
                $conflicts[] = $model;

                continue;
            }

            $fields = $first->fields;

            foreach (self::OPTIONAL_COLUMNS as $column) {
                $firstField = $first->fields[$column] ?? CandidatePriceField::missing();
                $secondField = $second->fields[$column] ?? CandidatePriceField::missing();

                $fields[$column] = $firstField->supplied ? $firstField : $secondField;

                if ($firstField->supplied && $secondField->supplied && $this->rounded($firstField) !== $this->rounded($secondField)) {
                    $rateWarnings[] = new PricingWarning($provider, $model, PricingWarning::RATE_MISMATCH, $column);
                }
            }

            $candidates[] = new ModelPriceCandidate(
                provider: $first->provider,
                model: $model,
                fields: $fields,
                source: PricingSource::FeedConsensus,
                sourceUrl: $first->sourceUrl,
                sourceUpdatedAt: $first->sourceUpdatedAt,
                tiered: $first->tiered || $second->tiered,
                reasoning: ReasoningCapability::preferring($first->reasoning, $second->reasoning),
            );
        }

        $settled = [];

        foreach ($candidates as $candidate) {
            $settled[$candidate->model] = true;
        }

        return new ProviderPricingResult(
            provider: $provider,
            candidates: $candidates,
            rejections: $this->unsettledRejections([...$modelsDev->rejections, ...$liteLlm->rejections], $settled, $conflicts),
            warnings: $this->settledWarnings([...$modelsDev->warnings, ...$liteLlm->warnings, ...$rateWarnings], $settled),
            createSuppressed: $modelsDev->createSuppressed || $liteLlm->createSuppressed,
            conflicts: $conflicts,
        );
    }

    /**
     * @param  list<ModelPriceCandidate>  $candidates
     * @return array<string, ModelPriceCandidate>
     */
    private function byModel(array $candidates): array
    {
        $indexed = [];

        foreach ($candidates as $candidate) {
            $indexed[$candidate->model] ??= $candidate;
        }

        return $indexed;
    }

    private function primaryRatesAgree(ModelPriceCandidate $first, ModelPriceCandidate $second): bool
    {
        foreach (self::PRIMARY_COLUMNS as $column) {
            $firstValue = $this->rounded($first->fields[$column] ?? CandidatePriceField::missing());

            if ($firstValue === null || $firstValue !== $this->rounded($second->fields[$column] ?? CandidatePriceField::missing())) {
                return false;
            }
        }

        return true;
    }

    private function rounded(CandidatePriceField $candidatePriceField): ?string
    {
        return $candidatePriceField->supplied && $candidatePriceField->value !== null
            ? PriceNumber::roundToColumnScale($candidatePriceField->value)
            : null;
    }

    /**
     * Rejections for models neither written nor conflicted, one per model.
     *
     * @param  list<PricingRejection>  $rejections
     * @param  array<string, true>  $settled
     * @param  list<string>  $conflicts
     * @return list<PricingRejection>
     */
    private function unsettledRejections(array $rejections, array $settled, array $conflicts): array
    {
        $kept = [];
        $seen = array_fill_keys($conflicts, true) + $settled;

        foreach ($rejections as $rejection) {
            if (isset($seen[$rejection->model])) {
                continue;
            }

            $seen[$rejection->model] = true;
            $kept[] = $rejection;
        }

        return $kept;
    }

    /**
     * Warnings for written models only, de-duplicated by model and code.
     *
     * @param  list<PricingWarning>  $warnings
     * @param  array<string, true>  $settled
     * @return list<PricingWarning>
     */
    private function settledWarnings(array $warnings, array $settled): array
    {
        $kept = [];
        $seen = [];

        foreach ($warnings as $warning) {
            $key = sprintf('%s|%s|%s', $warning->model, $warning->code, $warning->detail ?? '');

            if (! isset($settled[$warning->model]) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $kept[] = $warning;
        }

        return $kept;
    }

    private function isMalformed(ProviderPricingResult $providerPricingResult): bool
    {
        return array_any(
            $providerPricingResult->rejections,
            static fn (PricingRejection $pricingRejection): bool => $pricingRejection->code === PricingRejection::MALFORMED_PROVIDER,
        );
    }
}
