<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing\Data;

use App\Models\AiModelPrice;

/**
 * An immutable pricing candidate for a single provider/model, produced by a
 * source adapter (Models.dev or first-party) and consumed by the writer.
 *
 * Field keys use the exact {@see AiModelPrice} column names and map
 * to {@see CandidatePriceField} values so missing and explicit-zero prices stay
 * distinguishable through merge.
 */
final readonly class ModelPriceCandidate
{
    /**
     * @param  array<string, CandidatePriceField>  $fields
     */
    public function __construct(
        public string $provider,
        public string $model,
        public array $fields,
        public ?string $sourceUrl = null,
        public ?string $sourceUpdatedAt = null,
        public bool $tiered = false,
    ) {}

    /**
     * Whether BOTH primary rates (`input_per_mtok` and `output_per_mtok`) are
     * explicitly supplied on this candidate. This is the shared predicate that
     * gates verification grade: a write that did not re-read the primary rates
     * can neither stamp `pricing_verified_at` (the writer downgrades
     * `firstPartyVerified` to false) nor count as a verification-grade ledger
     * outcome (the upsert tool records it as unverified).
     */
    public function suppliesPrimaryRates(): bool
    {
        foreach (['input_per_mtok', 'output_per_mtok'] as $column) {
            $field = $this->fields[$column] ?? null;

            if ($field === null || ! $field->supplied) {
                return false;
            }
        }

        return true;
    }
}
