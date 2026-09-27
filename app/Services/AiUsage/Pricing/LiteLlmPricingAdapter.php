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
 * Pure translation of the LiteLLM price map into provider-scoped candidates
 * for the direct providers models.dev also covers. LiteLLM quotes USD per
 * token as JSON numbers; the catalog stores USD per million tokens.
 *
 * Only first-party provider entries are read: resellers (openrouter, vertex,
 * bedrock, azure, …) are ignored because they price differently or have their
 * own source. No database access.
 */
final class LiteLlmPricingAdapter
{
    /**
     * LiteLLM provider => canonical provider.
     *
     * @var array<string, string>
     */
    private const array PROVIDER_MAP = [
        'openai' => 'openai',
        'anthropic' => 'anthropic',
        'gemini' => 'gemini',
        'xai' => 'xai',
        'deepseek' => 'deepseek',
        'mistral' => 'mistral',
        'groq' => 'groq',
        'cohere' => 'cohere',
        'cohere_chat' => 'cohere',
    ];

    /**
     * LiteLLM modes whose output is text tokens.
     *
     * @var list<string>
     */
    private const array TEXT_MODES = ['chat', 'completion', 'responses'];

    /**
     * LiteLLM cost keys mapped to their catalog column.
     *
     * @var array<string, string>
     */
    private const array COST_COLUMN_MAP = [
        'input_cost_per_token' => 'input_per_mtok',
        'output_cost_per_token' => 'output_per_mtok',
        'cache_read_input_token_cost' => 'cache_read_per_mtok',
        'cache_creation_input_token_cost' => 'cache_write_per_mtok',
        'output_cost_per_reasoning_token' => 'reasoning_per_mtok',
    ];

    /**
     * @param  array<string, mixed>  $decoded  Model map from {@see LiteLlmPricingClient}.
     * @return array<string, ProviderPricingResult> Results keyed by canonical provider.
     */
    public function adapt(array $decoded, RefreshScope $refreshScope): array
    {
        /** @var array<string, array<string, list<array<string, mixed>>>> $buckets */
        $buckets = [];
        /** @var array<string, list<PricingRejection>> $rejections */
        $rejections = [];

        foreach ($decoded as $key => $entry) {
            $key = (string) $key;

            if ($key === 'sample_spec' || str_starts_with($key, 'ft:') || ! is_array($entry)) {
                continue;
            }

            $upstream = $entry['litellm_provider'] ?? null;
            $provider = is_string($upstream) ? (self::PROVIDER_MAP[$upstream] ?? null) : null;

            if ($provider === null || ! $refreshScope->allowsProvider($provider)) {
                continue;
            }

            $prefix = sprintf('%s/', $upstream);
            $modelId = PricingModelIds::normalize(str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key);

            if ($modelId === null) {
                $rejections[$provider][] = new PricingRejection($provider, $key, PricingRejection::INVALID_IDENTIFIER);

                continue;
            }

            $buckets[$provider][$modelId][] = $entry;
        }

        $results = [];

        foreach ($buckets as $provider => $models) {
            $results[$provider] = $this->adaptProvider($provider, $models, $rejections[$provider] ?? [], $refreshScope);
        }

        return $results;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $models
     * @param  list<PricingRejection>  $rejections
     */
    private function adaptProvider(string $provider, array $models, array $rejections, RefreshScope $refreshScope): ProviderPricingResult
    {
        $sliceIds = [];

        foreach (array_keys($models) as $modelId) {
            $sliceIds[(string) $modelId] = true;
        }

        $candidates = [];
        $warnings = [];

        foreach ($models as $modelId => $entries) {
            $modelId = (string) $modelId;

            if (PricingModelIds::isDatedVariantOfKnownBase($modelId, $sliceIds)) {
                $rejections[] = new PricingRejection($provider, $modelId, PricingRejection::DATED_VARIANT);

                continue;
            }

            if (! $refreshScope->allowsWrite($provider, $modelId)) {
                continue;
            }

            [$candidate, $rejection, $warning] = $this->adaptEntries($provider, $modelId, $entries);

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
            provider: $provider,
            candidates: $candidates,
            rejections: $rejections,
            warnings: $warnings,
            createSuppressed: ! $refreshScope->allowsCreate($provider),
        );
    }

    /**
     * Adapt every duplicate key of one model (for example `deepseek-chat` and
     * `deepseek/deepseek-chat`). They must agree; disagreement rejects the
     * model because the map contradicts itself.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array{0: ?ModelPriceCandidate, 1: ?PricingRejection, 2: ?PricingWarning}
     */
    private function adaptEntries(string $provider, string $modelId, array $entries): array
    {
        $adapted = array_map(fn (array $entry): array => $this->adaptEntry($provider, $modelId, $entry), $entries);

        $accepted = array_values(array_filter($adapted, static fn (array $result): bool => $result[0] instanceof ModelPriceCandidate));

        if ($accepted === []) {
            return $adapted[0];
        }

        $fingerprints = array_unique(array_map(
            fn (array $result): string => $this->fingerprint($result[0]),
            $accepted,
        ));

        if (count($fingerprints) > 1) {
            return [null, new PricingRejection($provider, $modelId, PricingRejection::INVALID_COST, 'duplicate_mismatch'), null];
        }

        return $accepted[0];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{0: ?ModelPriceCandidate, 1: ?PricingRejection, 2: ?PricingWarning}
     */
    private function adaptEntry(string $provider, string $modelId, array $entry): array
    {
        $mode = $entry['mode'] ?? null;

        if (! is_string($mode) || ! in_array($mode, self::TEXT_MODES, true)) {
            return [null, new PricingRejection($provider, $modelId, PricingRejection::NON_TEXT_OUTPUT, is_string($mode) ? sprintf('mode:%s', $mode) : 'missing_mode'), null];
        }

        $deprecationDate = $entry['deprecation_date'] ?? null;

        if (is_string($deprecationDate)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $deprecationDate) === 1
            && $deprecationDate < now()->toDateString()) {
            return [null, new PricingRejection($provider, $modelId, PricingRejection::DEPRECATED), null];
        }

        if (! array_key_exists('input_cost_per_token', $entry)) {
            return [null, new PricingRejection($provider, $modelId, PricingRejection::MISSING_INPUT), null];
        }

        if (! array_key_exists('output_cost_per_token', $entry)) {
            return [null, new PricingRejection($provider, $modelId, PricingRejection::MISSING_OUTPUT), null];
        }

        $fields = [];

        foreach (self::COST_COLUMN_MAP as $key => $column) {
            if (! array_key_exists($key, $entry)) {
                $fields[$column] = CandidatePriceField::missing();

                continue;
            }

            $value = $this->perMillion($entry[$key]);

            if ($value === null) {
                return [null, new PricingRejection($provider, $modelId, PricingRejection::INVALID_COST, $key), null];
            }

            $fields[$column] = CandidatePriceField::of($value);
        }

        $tierKeys = array_values(array_filter(
            array_map(strval(...), array_keys($entry)),
            static fn (string $key): bool => preg_match('/_above_\d+k?_tokens$/D', $key) === 1,
        ));

        $candidate = new ModelPriceCandidate(
            provider: $provider,
            model: $modelId,
            fields: $fields,
            source: PricingSource::LiteLlm,
            sourceUrl: $this->sourceUrl(),
            tiered: $tierKeys !== [],
        );

        $warning = $tierKeys !== []
            ? new PricingWarning($provider, $modelId, PricingWarning::CONTEXT_TIERS, implode(',', $tierKeys))
            : null;

        return [$candidate, null, $warning];
    }

    /**
     * A comparable string of a candidate's supplied rates.
     */
    private function fingerprint(ModelPriceCandidate $modelPriceCandidate): string
    {
        $parts = [];

        foreach ($modelPriceCandidate->fields as $column => $field) {
            $parts[] = sprintf('%s=%s', $column, $field->supplied ? (PriceNumber::roundToColumnScale((string) $field->value) ?? 'invalid') : 'missing');
        }

        return implode('|', $parts);
    }

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
        $url = config('mediamanager.ai.pricing.litellm.url');

        return is_string($url) && $url !== '' ? $url : null;
    }
}
