<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

use App\Services\AiUsage\Pricing\Data\CandidatePriceField;
use App\Services\AiUsage\Pricing\Data\ModelPriceCandidate;
use App\Services\AiUsage\Pricing\Data\PricingRejection;
use App\Services\AiUsage\Pricing\Data\PricingWarning;
use App\Services\AiUsage\Pricing\Data\ProviderPricingResult;
use DateTimeImmutable;

/**
 * Pure translation layer from the decoded Models.dev catalog to provider-scoped
 * pricing candidates.
 *
 * This adapter performs no Eloquent or database access: it reads only the
 * decoded provider map (as produced by {@see ModelsDevPricingClient}) and the
 * per-run {@see RefreshScope}, and emits {@see ProviderPricingResult} objects
 * keyed by canonical (Laravel AI) provider identity. Persistence, lock checks,
 * anomaly detection, and create-vs-update decisions belong to the writer and
 * coordinator, not here.
 *
 * Because it cannot query existing rows, OpenRouter's "update existing only,
 * never create" rule is represented as {@see ProviderPricingResult::$createSuppressed}
 * on the result rather than by consulting the catalog; the writer still enforces
 * the rule through scope.
 */
final class ModelsDevPricingAdapter
{
    /**
     * Models.dev cost keys mapped to their catalog column. Only these keys are
     * consumed; every other cost key (audio, tier signals, provider extras) is
     * ignored for value mapping.
     *
     * @var array<string, string>
     */
    private const array COST_COLUMN_MAP = [
        'input' => 'input_per_mtok',
        'output' => 'output_per_mtok',
        'cache_read' => 'cache_read_per_mtok',
        'cache_write' => 'cache_write_per_mtok',
        'reasoning' => 'reasoning_per_mtok',
    ];

    /**
     * Optional cost keys whose presence must be preserved (supplied vs missing)
     * and never coalesced to zero before merge semantics run.
     *
     * @var list<string>
     */
    private const array OPTIONAL_COST_KEYS = ['cache_read', 'cache_write', 'reasoning'];

    /**
     * Maximum number of whole-number digits a rate may carry, matching the
     * catalog's `0` to `9999.9999` range. Values above this are out of range.
     */
    private const int MAX_WHOLE_DIGITS = 4;

    /**
     * Translate a decoded Models.dev catalog into provider-scoped candidates.
     *
     * @param  array<string, mixed>  $decoded  Top-level provider map keyed by upstream provider id.
     * @return array<string, ProviderPricingResult> Results keyed by canonical provider id.
     */
    public function adapt(array $decoded, RefreshScope $refreshScope): array
    {
        $results = [];

        foreach ($decoded as $upstreamProvider => $providerData) {
            $canonical = RefreshScope::canonicalProvider((string) $upstreamProvider);
            if ($canonical === null) {
                continue;
            }

            if (! $refreshScope->allowsProvider($canonical)) {
                continue;
            }

            $providerResult = $this->adaptProvider($canonical, $providerData, $refreshScope);

            $results[$canonical] = isset($results[$canonical])
                ? $this->mergeProviderResults($results[$canonical], $providerResult)
                : $providerResult;
        }

        return $results;
    }

    /**
     * Merge slices when multiple configured upstream provider identifiers map to
     * the same canonical provider. Catalog order is preserved and create
     * suppression is conservative: either slice may require update-only writes.
     */
    private function mergeProviderResults(
        ProviderPricingResult $first,
        ProviderPricingResult $second,
    ): ProviderPricingResult {
        return new ProviderPricingResult(
            provider: $first->provider,
            candidates: [...$first->candidates, ...$second->candidates],
            rejections: [...$first->rejections, ...$second->rejections],
            warnings: [...$first->warnings, ...$second->warnings],
            createSuppressed: $first->createSuppressed || $second->createSuppressed,
        );
    }

    /**
     * Adapt a single canonical provider's model collection.
     */
    private function adaptProvider(string $provider, mixed $providerData, RefreshScope $refreshScope): ProviderPricingResult
    {
        $createSuppressed = $provider === 'openrouter' && ! $refreshScope->isOpenRouterCreateAllowed();

        $models = is_array($providerData) ? ($providerData['models'] ?? null) : null;

        // The catalog contract is an object map keyed by model id. A JSON list
        // (sequential array) means the provider slice does not follow the
        // schema, so it is malformed rather than a usable model collection.
        if (! is_array($models) || (array_is_list($models) && $models !== [])) {
            return new ProviderPricingResult(
                provider: $provider,
                candidates: [],
                rejections: [new PricingRejection($provider, '', PricingRejection::MALFORMED_PROVIDER)],
                createSuppressed: $createSuppressed,
            );
        }

        $candidates = [];
        $rejections = [];
        $warnings = [];

        $sliceIds = $this->resolvedModelIds($models);

        foreach ($models as $modelKey => $modelData) {
            $modelId = $this->resolveModelId($modelKey, $modelData);

            if ($modelId === null) {
                $rejections[] = new PricingRejection(
                    $provider,
                    is_string($modelKey) ? $modelKey : (string) $modelKey,
                    PricingRejection::INVALID_IDENTIFIER,
                );

                continue;
            }

            if ($this->isDatedVariantOfKnownBase($modelId, $sliceIds)) {
                $rejections[] = new PricingRejection($provider, $modelId, PricingRejection::DATED_VARIANT);

                continue;
            }

            if (! $refreshScope->allowsWrite($provider, $modelId)) {
                continue;
            }

            [$candidate, $rejection, $warning] = $this->adaptModel($provider, $modelId, $modelData);

            if ($rejection instanceof PricingRejection) {
                $rejections[] = $rejection;
            }

            if ($warning instanceof PricingWarning) {
                $warnings[] = $warning;
            }

            if ($candidate instanceof ModelPriceCandidate) {
                $candidates[] = $candidate;
            }
        }

        return new ProviderPricingResult(
            provider: $provider,
            candidates: $candidates,
            rejections: $rejections,
            warnings: $warnings,
            createSuppressed: $createSuppressed,
        );
    }

    /**
     * Adapt one model entry into a candidate, a rejection, and/or a warning.
     *
     * @return array{0: ?ModelPriceCandidate, 1: ?PricingRejection, 2: ?PricingWarning}
     */
    private function adaptModel(string $provider, string $modelId, mixed $modelData): array
    {
        if (! is_array($modelData)) {
            return [null, new PricingRejection($provider, $modelId, PricingRejection::MALFORMED_MODEL), null];
        }

        if ($this->isDeprecated($modelData)) {
            return [null, new PricingRejection($provider, $modelId, PricingRejection::DEPRECATED), null];
        }

        $textOutputRejectionDetail = $this->textOutputRejectionDetail($modelData);

        if ($textOutputRejectionDetail !== null) {
            return [null, new PricingRejection($provider, $modelId, PricingRejection::NON_TEXT_OUTPUT, $textOutputRejectionDetail), null];
        }

        $cost = $modelData['cost'] ?? null;

        if (! is_array($cost)) {
            return [null, new PricingRejection($provider, $modelId, PricingRejection::MISSING_COST), null];
        }

        if (! array_key_exists('input', $cost)) {
            return [null, new PricingRejection($provider, $modelId, PricingRejection::MISSING_INPUT), null];
        }

        if (! array_key_exists('output', $cost)) {
            return [null, new PricingRejection($provider, $modelId, PricingRejection::MISSING_OUTPUT), null];
        }

        $fields = [];

        foreach (['input', 'output'] as $required) {
            $value = $this->normalizeNumber($cost[$required]);

            if ($value === null) {
                return [null, new PricingRejection($provider, $modelId, PricingRejection::INVALID_COST, $required), null];
            }

            $fields[self::COST_COLUMN_MAP[$required]] = CandidatePriceField::of($value);
        }

        foreach (self::OPTIONAL_COST_KEYS as $optional) {
            $column = self::COST_COLUMN_MAP[$optional];

            if (! array_key_exists($optional, $cost)) {
                $fields[$column] = CandidatePriceField::missing();

                continue;
            }

            $value = $this->normalizeNumber($cost[$optional]);

            if ($value === null) {
                return [null, new PricingRejection($provider, $modelId, PricingRejection::INVALID_COST, $optional), null];
            }

            $fields[$column] = CandidatePriceField::of($value);
        }

        $tierSignal = $this->tierSignal($cost);

        $warning = $tierSignal !== null
            ? new PricingWarning($provider, $modelId, PricingWarning::CONTEXT_TIERS, $tierSignal)
            : null;

        $modelPriceCandidate = new ModelPriceCandidate(
            provider: $provider,
            model: $modelId,
            fields: $fields,
            sourceUrl: $this->sourceUrl(),
            sourceUpdatedAt: $this->sourceUpdatedAt($modelData),
            tiered: $tierSignal !== null,
        );

        return [$modelPriceCandidate, null, $warning];
    }

    /**
     * Every resolvable model identifier in a provider slice, keyed for O(1)
     * base-model lookups when deciding whether a dated snapshot is redundant.
     *
     * @param  array<int|string, mixed>  $models
     * @return array<string, true>
     */
    private function resolvedModelIds(array $models): array
    {
        $ids = [];

        foreach ($models as $modelKey => $modelData) {
            $modelId = $this->resolveModelId($modelKey, $modelData);

            if ($modelId !== null) {
                $ids[$modelId] = true;
            }
        }

        return $ids;
    }

    /**
     * Whether the identifier is a date-suffixed snapshot (`-YYYYMMDD` or
     * `-YYYY-MM-DD`) of a base model that also exists in the same provider
     * slice. Only real calendar dates count, so short numeric version suffixes
     * (for example `-0905`) are never treated as dates. A dated model with no
     * base sibling is kept — dropping it would lose its pricing entirely.
     *
     * @param  array<string, true>  $sliceIds
     */
    private function isDatedVariantOfKnownBase(string $modelId, array $sliceIds): bool
    {
        if (preg_match('/^(.+)-(\d{4})-?(\d{2})-?(\d{2})$/D', $modelId, $matches) !== 1) {
            return false;
        }

        if (! checkdate((int) $matches[3], (int) $matches[4], (int) $matches[2])) {
            return false;
        }

        return isset($sliceIds[$matches[1]]);
    }

    /**
     * Resolve the upstream model identifier, preferring an explicit `id` field
     * and falling back to the map key only when `id` is absent. Surrounding
     * whitespace is normalized before storage; empty identifiers, ASCII control
     * characters, and values beyond the database string limit are rejected.
     */
    private function resolveModelId(int|string $key, mixed $modelData): ?string
    {
        if (is_array($modelData) && array_key_exists('id', $modelData)) {
            return is_string($modelData['id'])
                ? $this->normalizeIdentifier($modelData['id'])
                : null;
        }

        return $this->normalizeIdentifier((string) $key);
    }

    /**
     * Normalize an identifier for the `string`/VARCHAR(255) catalog columns.
     */
    private function normalizeIdentifier(string $identifier): ?string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $identifier) === 1) {
            return null;
        }

        $identifier = trim($identifier);

        if ($identifier === '' || strlen($identifier) > 255 || mb_strlen($identifier) > 255) {
            return null;
        }

        return $identifier;
    }

    /**
     * Whether the model is flagged deprecated, accepting either the fixture's
     * boolean `deprecated` flag or the live catalog's `status: "deprecated"`
     * string. Other lifecycle states (for example `preview`) are accepted.
     *
     * @param  array<string, mixed>  $modelData
     */
    private function isDeprecated(array $modelData): bool
    {
        if (($modelData['deprecated'] ?? null) === true) {
            return true;
        }

        $status = $modelData['status'] ?? null;

        return is_string($status) && strtolower(trim($status)) === 'deprecated';
    }

    /**
     * The reason a model fails the text-output requirement, or null when it is
     * text-capable. Per spec §8.1 condition 5 the declared output modalities
     * must contain `text`; a model that omits this metadata is not assumed to be
     * text-capable.
     *
     * Two distinguishing details let audits separate metadata gaps from genuine
     * non-text models:
     *
     * - `missing_modalities`: the `modalities` map is absent or malformed, the
     *   `modalities.output` key is absent, or its value is not a list — the
     *   source never actually declared its output modalities.
     * - `declared_non_text`: a well-formed output modality list was declared but
     *   does not include `text`, so the model is a genuine non-text model.
     *
     * @param  array<string, mixed>  $modelData
     */
    private function textOutputRejectionDetail(array $modelData): ?string
    {
        $modalities = $modelData['modalities'] ?? null;

        if (! is_array($modalities) || ! array_key_exists('output', $modalities)) {
            return 'missing_modalities';
        }

        $output = $modalities['output'];

        if (! is_array($output)) {
            return 'missing_modalities';
        }

        return in_array('text', $output, true) ? null : 'declared_non_text';
    }

    /**
     * Detect context-tier pricing signals without flattening them into the base
     * rate. Returns a comma-separated list of detected signals, or null.
     *
     * @param  array<string, mixed>  $cost
     */
    private function tierSignal(array $cost): ?string
    {
        $signals = [];

        if (isset($cost['tiers']) && is_array($cost['tiers']) && $cost['tiers'] !== []) {
            $signals[] = 'tiers';
        }

        foreach (array_keys($cost) as $key) {
            if (is_string($key) && str_starts_with($key, 'context_over_')) {
                $signals[] = $key;
            }
        }

        return $signals === [] ? null : implode(',', $signals);
    }

    /**
     * Normalize a source cost value to a non-negative decimal string, or null
     * when it is non-numeric, non-finite, negative, or out of range. Integers
     * and strings are preserved exactly; floats are rendered without scientific
     * notation and trailing zeros.
     */
    private function normalizeNumber(mixed $value): ?string
    {
        if (is_int($value)) {
            $normalized = (string) $value;
        } elseif (is_float($value)) {
            if (! is_finite($value)) {
                return null;
            }

            $normalized = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');

            if ($normalized === '' || $normalized === '-0') {
                $normalized = '0';
            }
        } elseif (is_string($value)) {
            $normalized = trim($value);
        } else {
            return null;
        }

        if (preg_match('/^\+?(\d+)(?:\.(\d+))?$/D', $normalized, $matches) !== 1) {
            return null;
        }

        if (strlen(ltrim($matches[1], '0')) > self::MAX_WHOLE_DIGITS) {
            return null;
        }

        return $normalized;
    }

    /**
     * The provenance source URL stamped on every candidate.
     */
    private function sourceUrl(): ?string
    {
        $url = config('mediamanager.ai.pricing.models_dev.url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * The upstream last-updated date when it is a real, strict `Y-m-d` calendar
     * date with a positive year. Invalid metadata is dropped rather than passed
     * to the writer's date cast.
     *
     * @param  array<string, mixed>  $modelData
     */
    private function sourceUpdatedAt(array $modelData): ?string
    {
        $updatedAt = $modelData['last_updated'] ?? null;

        if (! is_string($updatedAt) || preg_match('/^(\d{4})-\d{2}-\d{2}$/D', $updatedAt, $matches) !== 1) {
            return null;
        }

        if ((int) $matches[1] <= 0) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $updatedAt);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date->format('Y-m-d') === $updatedAt ? $updatedAt : null;
    }
}
