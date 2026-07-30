<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

use App\Enums\PricingSource;
use App\Models\AiModelPrice;
use App\Services\AiUsage\Pricing\Data\ModelPriceCandidate;
use App\Services\AiUsage\Pricing\Data\WriteOutcome;
use Carbon\CarbonImmutable;

/**
 * The single automatic writer for the `ai_model_prices` catalog.
 *
 * Every automatic pricing path (Models.dev feed and the scoped first-party
 * verifier) persists through this class so that canonical provider identity,
 * missing-vs-explicit-zero merge rules, lock protection, decimal-safe
 * comparison, relative-change anomaly detection, and provenance stamping are
 * enforced in exactly one place. It never touches `free_usage_pool_id`,
 * rate-limit rows, historical usage snapshots, or the manual lock flag from
 * source data.
 */
final readonly class AiModelPriceWriter
{
    /**
     * Primary rates that a new row must supply and that the anomaly policy
     * guards against implausible relative change.
     *
     * @var list<string>
     */
    private const array REQUIRED_COLUMNS = [
        'input_per_mtok',
        'output_per_mtok',
    ];

    /**
     * Standard optional rates that default to `0.0000` on a new row when the
     * source omits them.
     *
     * @var list<string>
     */
    private const array STANDARD_OPTIONAL_COLUMNS = [
        'cache_read_per_mtok',
        'cache_write_per_mtok',
        'reasoning_per_mtok',
    ];

    /**
     * Batch rates that remain null on a new row when the source omits them;
     * batch pricing is never inferred.
     *
     * @var list<string>
     */
    private const array BATCH_COLUMNS = [
        'batch_input_per_mtok',
        'batch_output_per_mtok',
        'batch_cache_read_per_mtok',
        'batch_cache_write_per_mtok',
        'batch_reasoning_per_mtok',
    ];

    /**
     * Upper bound (inclusive) for any single normalized rate, expressed as the
     * value scaled to four decimal places (9999.9999 * 10^4).
     */
    private const int MAX_SCALED_RATE = 99_999_999;

    public function __construct(
        private PricingAnomalyPolicy $pricingAnomalyPolicy,
    ) {}

    /**
     * Persist a single provider/model candidate under the given run scope.
     *
     * @param  bool  $dryRun  When true, compute the outcome but perform no write.
     * @param  bool  $firstPartyVerified  When true, bypass anomaly detection for a
     *                                    human-scoped first-party verification and
     *                                    stamp `pricing_verified_at`.
     */
    public function write(
        ModelPriceCandidate $candidate,
        RefreshScope $scope,
        PricingSource $source,
        bool $dryRun = false,
        bool $firstPartyVerified = false,
    ): WriteOutcome {
        $provider = RefreshScope::canonicalProvider($candidate->provider);
        $model = $this->normalizeModelId($candidate->model);

        if ($provider === null || $model === null || ! $scope->allowsWrite($candidate->provider, $model)) {
            return WriteOutcome::Rejected;
        }

        $supplied = $this->normalizeSuppliedFields($candidate);

        // A null result means a supplied value failed decimal/range validation.
        // An empty result means the tool supplied no rate fields at all (an
        // all-null call): there is nothing to persist, so it is rejected before
        // any row is touched — it must never resolve or stamp a verification.
        if ($supplied === null || $supplied === []) {
            return WriteOutcome::Rejected;
        }

        // A write that did not re-read BOTH primary rates cannot be
        // verification-grade: downgrade here, the single choke point, so no
        // path can stamp `pricing_verified_at` or bypass the anomaly guard from
        // a partial (e.g. cache-only) update. The write itself still proceeds
        // under the normal merge rules below.
        $firstPartyVerified = $firstPartyVerified && $candidate->suppliesPrimaryRates();

        $existing = AiModelPrice::query()
            ->where('provider', $provider)
            ->where('model', $model)
            ->first();

        if ($existing === null) {
            return $this->create($provider, $model, $candidate, $supplied, $scope, $source, $dryRun, $firstPartyVerified);
        }

        return $this->update($existing, $candidate, $supplied, $source, $dryRun, $firstPartyVerified);
    }

    /**
     * Centralized identifier hygiene for every automatic write path: trims
     * surrounding whitespace, then rejects empty identifiers, ASCII control
     * characters, and values beyond the 255-character database column before
     * any Eloquent query runs.
     */
    private function normalizeModelId(string $model): ?string
    {
        $model = trim($model);

        if ($model === ''
            || preg_match('/[\x00-\x1F\x7F]/', $model) === 1
            || strlen($model) > 255
            || mb_strlen($model) > 255) {
            return null;
        }

        return $model;
    }

    /**
     * Normalize every explicitly supplied field to a canonical four-decimal
     * string. Missing fields are omitted. Returns null when any supplied value
     * fails decimal/range validation.
     *
     * @return array<string, string>|null
     */
    private function normalizeSuppliedFields(ModelPriceCandidate $candidate): ?array
    {
        $normalized = [];

        foreach ([...self::REQUIRED_COLUMNS, ...self::STANDARD_OPTIONAL_COLUMNS, ...self::BATCH_COLUMNS] as $column) {
            $field = $candidate->fields[$column] ?? null;
            if ($field === null) {
                continue;
            }

            if (! $field->supplied) {
                continue;
            }

            $value = $this->normalizeDecimal($field->value);

            if ($value === null) {
                return null;
            }

            $normalized[$column] = $value;
        }

        return $normalized;
    }

    /**
     * @param  array<string, string>  $supplied
     */
    private function create(
        string $provider,
        string $model,
        ModelPriceCandidate $candidate,
        array $supplied,
        RefreshScope $scope,
        PricingSource $source,
        bool $dryRun,
        bool $firstPartyVerified,
    ): WriteOutcome {
        if ($provider === 'openrouter' && ! $scope->isOpenRouterCreateAllowed()) {
            return WriteOutcome::Rejected;
        }

        // A date-suffixed snapshot of a model whose base row already exists is
        // catalog noise: the base row carries the pricing every consumer
        // resolves against. The feed adapter skips these within a source
        // slice; this guard closes the same door for every other automatic
        // path (notably the first-party verifier agent). Existing dated rows
        // keep updating so a lingering snapshot never goes stale.
        if ($this->isDatedVariantOfExistingBase($provider, $model)) {
            return WriteOutcome::Rejected;
        }

        foreach (self::REQUIRED_COLUMNS as $column) {
            if (! isset($supplied[$column])) {
                return WriteOutcome::Rejected;
            }
        }

        if ($dryRun) {
            return WriteOutcome::WouldCreate;
        }

        $attributes = [
            'provider' => $provider,
            'model' => $model,
        ];

        foreach ([...self::REQUIRED_COLUMNS, ...self::STANDARD_OPTIONAL_COLUMNS] as $column) {
            $attributes[$column] = $supplied[$column] ?? '0.0000';
        }

        foreach (self::BATCH_COLUMNS as $column) {
            $attributes[$column] = $supplied[$column] ?? null;
        }

        $attributes = [...$attributes, ...$this->provenanceAttributes($candidate, $source, $firstPartyVerified)];

        AiModelPrice::query()->create($attributes);

        return WriteOutcome::Created;
    }

    /**
     * @param  array<string, string>  $supplied
     */
    private function update(
        AiModelPrice $aiModelPrice,
        ModelPriceCandidate $candidate,
        array $supplied,
        PricingSource $source,
        bool $dryRun,
        bool $firstPartyVerified,
    ): WriteOutcome {
        if ($aiModelPrice->is_price_locked) {
            return WriteOutcome::Locked;
        }

        if (! $firstPartyVerified && $this->isAnomalous($aiModelPrice, $supplied)) {
            return WriteOutcome::RejectedAnomalous;
        }

        $changes = [];

        foreach ($supplied as $column => $value) {
            $current = $aiModelPrice->{$column};
            $currentNormalized = $current === null ? null : $this->normalizeDecimal((string) $current);

            if ($currentNormalized !== $value) {
                $changes[$column] = $value;
            }
        }

        if ($changes === []) {
            if ($firstPartyVerified && ! $dryRun) {
                $aiModelPrice->fill(['pricing_verified_at' => CarbonImmutable::now()]);
                $aiModelPrice->save();
            }

            return WriteOutcome::Unchanged;
        }

        if ($dryRun) {
            return WriteOutcome::WouldUpdate;
        }

        $aiModelPrice->fill([...$changes, ...$this->provenanceAttributes($candidate, $source, $firstPartyVerified)]);
        $aiModelPrice->save();

        return WriteOutcome::Updated;
    }

    /**
     * Whether any supplied recognized rate is an implausible jump from its
     * stored positive value under the configured relative-change policy.
     * Missing or currently-null fields have no baseline and are skipped.
     *
     * @param  array<string, string>  $supplied
     */
    private function isAnomalous(AiModelPrice $aiModelPrice, array $supplied): bool
    {
        foreach ($supplied as $column => $candidateValue) {
            $current = $aiModelPrice->{$column};

            if ($current === null) {
                continue;
            }

            $currentNormalized = $this->normalizeDecimal((string) $current);

            if ($currentNormalized !== null && $this->pricingAnomalyPolicy->isAnomalous($currentNormalized, $candidateValue)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Provenance and source metadata written on every create and value-changing
     * update. Lock state and free-usage policy fields are deliberately excluded
     * because they are never source-controlled.
     *
     * @return array<string, mixed>
     */
    private function provenanceAttributes(
        ModelPriceCandidate $candidate,
        PricingSource $source,
        bool $firstPartyVerified,
    ): array {
        $now = CarbonImmutable::now();

        $attributes = [
            'pricing_source' => $source,
            'pricing_source_url' => $candidate->sourceUrl,
            'pricing_source_updated_at' => $candidate->sourceUpdatedAt,
            'pricing_synced_at' => $now,
        ];

        if ($firstPartyVerified) {
            $attributes['pricing_verified_at'] = $now;
        }

        return $attributes;
    }

    /**
     * Whether the identifier is a date-suffixed snapshot (`-YYYYMMDD` or
     * `-YYYY-MM-DD`, real calendar dates only) of a base model that already has
     * a catalog row for the same provider.
     */
    private function isDatedVariantOfExistingBase(string $provider, string $model): bool
    {
        if (preg_match('/^(.+)-(\d{4})-?(\d{2})-?(\d{2})$/D', $model, $matches) !== 1) {
            return false;
        }

        if (! checkdate((int) $matches[3], (int) $matches[4], (int) $matches[2])) {
            return false;
        }

        return AiModelPrice::query()
            ->where('provider', $provider)
            ->where('model', $matches[1])
            ->exists();
    }

    /**
     * Validate a decimal string as finite and within `0` to `9999.9999`, then
     * normalize it to exactly four decimal places (round half-up) using integer
     * string math so no binary float rounding is introduced.
     */
    private function normalizeDecimal(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^\+?(\d+)(?:\.(\d+))?$/D', $value, $matches) !== 1) {
            return null;
        }

        $whole = ltrim($matches[1], '0');
        $whole = $whole === '' ? '0' : $whole;

        if (strlen($whole) > 4) {
            return null;
        }

        $fraction = $matches[2] ?? '';
        $fivePlaces = str_pad(substr($fraction, 0, 5), 5, '0');

        $scaledToFivePlaces = ((int) $whole * 100_000) + (int) $fivePlaces;
        $scaled = intdiv($scaledToFivePlaces + 5, 10);

        if ($scaled < 0 || $scaled > self::MAX_SCALED_RATE) {
            return null;
        }

        $intPart = intdiv($scaled, 10_000);
        $fractionPart = $scaled % 10_000;

        return $intPart.'.'.str_pad((string) $fractionPart, 4, '0', STR_PAD_LEFT);
    }
}
