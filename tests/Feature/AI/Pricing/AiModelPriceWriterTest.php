<?php

declare(strict_types=1);

use App\Enums\PricingSource;
use App\Enums\RateLimitMetric;
use App\Enums\RateLimitPeriod;
use App\Models\AiFreeUsagePool;
use App\Models\AiModelPrice;
use App\Models\AiModelRateLimit;
use App\Services\AiUsage\Pricing\AiModelPriceWriter;
use App\Services\AiUsage\Pricing\Data\CandidatePriceField;
use App\Services\AiUsage\Pricing\Data\ModelPriceCandidate;
use App\Services\AiUsage\Pricing\Data\WriteOutcome;
use App\Services\AiUsage\Pricing\PricingAnomalyPolicy;
use App\Services\AiUsage\Pricing\RefreshScope;

/**
 * Build a candidate whose field map uses the exact AiModelPrice column names.
 * A null value marks a missing field; any string is an explicitly supplied
 * value (including an explicit zero).
 *
 * @param  array<string, string|null>  $values
 * @param  array{sourceUrl?: string|null, sourceUpdatedAt?: string|null, tiered?: bool}  $meta
 */
function priceCandidate(string $provider, string $model, array $values, array $meta = []): ModelPriceCandidate
{
    $fields = [];

    foreach ($values as $column => $value) {
        $fields[$column] = $value === null
            ? CandidatePriceField::missing()
            : CandidatePriceField::of($value);
    }

    return new ModelPriceCandidate(
        provider: $provider,
        model: $model,
        fields: $fields,
        sourceUrl: $meta['sourceUrl'] ?? null,
        sourceUpdatedAt: $meta['sourceUpdatedAt'] ?? null,
        tiered: $meta['tiered'] ?? false,
    );
}

function priceWriter(): AiModelPriceWriter
{
    return resolve(AiModelPriceWriter::class);
}

describe('CandidatePriceField', function (): void {
    it('represents a missing value distinctly from a supplied value', function (): void {
        expect(CandidatePriceField::missing()->supplied)->toBeFalse()
            ->and(CandidatePriceField::missing()->value)->toBeNull()
            ->and(CandidatePriceField::of('1.2500')->supplied)->toBeTrue()
            ->and(CandidatePriceField::of('1.2500')->value)->toBe('1.2500');
    });

    it('treats an explicit zero as a supplied value, not missing', function (): void {
        $candidatePriceField = CandidatePriceField::of('0.0000');

        expect($candidatePriceField->supplied)->toBeTrue()
            ->and($candidatePriceField->value)->toBe('0.0000');
    });
});

describe('RefreshScope', function (): void {
    it('canonicalizes google to gemini when building a provider allowlist', function (): void {
        $refreshScope = RefreshScope::forProviders(['google']);

        expect($refreshScope->allowsProvider('gemini'))->toBeTrue()
            ->and($refreshScope->allowsProvider('google'))->toBeTrue();
    });

    it('canonicalizes the provider when checking writes', function (): void {
        $refreshScope = RefreshScope::forProviders(['google']);

        expect($refreshScope->allowsWrite('google', 'gemini-2.5-pro'))->toBeTrue()
            ->and($refreshScope->allowsWrite('gemini', 'gemini-2.5-pro'))->toBeTrue();
    });

    it('denies providers outside the allowlist', function (): void {
        $refreshScope = RefreshScope::forProviders(['google']);

        expect($refreshScope->allowsProvider('openai'))->toBeFalse()
            ->and($refreshScope->allowsWrite('openai', 'gpt-5-mini'))->toBeFalse();
    });

    it('denies an unsupported provider even when scoped to it', function (): void {
        $refreshScope = RefreshScope::forProviders(['vertex']);

        expect($refreshScope->allowsProvider('vertex'))->toBeFalse();
    });

    it('allows every supported provider under the all() scope', function (): void {
        $refreshScope = RefreshScope::all();

        expect($refreshScope->allowsProvider('gemini'))->toBeTrue()
            ->and($refreshScope->allowsWrite('google', 'gemini-2.5-pro'))->toBeTrue()
            ->and($refreshScope->allowsProvider('openai'))->toBeTrue();
    });

    it('restricts writes to the named models under a provider-model scope', function (): void {
        $refreshScope = RefreshScope::forProviderModels(['google' => ['gemini-2.5-pro']]);

        expect($refreshScope->allowsProvider('gemini'))->toBeTrue()
            ->and($refreshScope->allowsWrite('google', 'gemini-2.5-pro'))->toBeTrue()
            ->and($refreshScope->allowsWrite('gemini', 'gemini-2.5-flash'))->toBeFalse();
    });

    it('uses the configured provider map from the approved config path', function (): void {
        config([
            'mediamanager.ai.pricing.providers' => [
                'google' => 'google-ai',
                'openai' => 'openai',
            ],
        ]);

        $refreshScope = RefreshScope::forProviders(['google']);

        expect($refreshScope->allowsProvider('google'))->toBeTrue()
            ->and($refreshScope->allowsProvider('google-ai'))->toBeTrue()
            ->and($refreshScope->allowsProvider('gemini'))->toBeFalse();
    });

    it('defaults OpenRouter create to disallowed', function (): void {
        expect(RefreshScope::all()->isOpenRouterCreateAllowed())->toBeFalse()
            ->and(RefreshScope::forProviders(['openrouter'])->isOpenRouterCreateAllowed())->toBeFalse();
    });
});

describe('WriteOutcome', function (): void {
    it('exposes the stable set of outcome values', function (): void {
        expect(WriteOutcome::Created->value)->toBe('created')
            ->and(WriteOutcome::Updated->value)->toBe('updated')
            ->and(WriteOutcome::Unchanged->value)->toBe('unchanged')
            ->and(WriteOutcome::Locked->value)->toBe('locked')
            ->and(WriteOutcome::Rejected->value)->toBe('rejected')
            ->and(WriteOutcome::RejectedAnomalous->value)->toBe('rejected_anomalous')
            ->and(WriteOutcome::WouldCreate->value)->toBe('would_create')
            ->and(WriteOutcome::WouldUpdate->value)->toBe('would_update');
    });
});

describe('PricingAnomalyPolicy', function (): void {
    it('flags increases beyond the configured maximum ratio', function (): void {
        $policy = new PricingAnomalyPolicy;

        expect($policy->isAnomalous('1.0000', '5.0000'))->toBeTrue()
            ->and($policy->isAnomalous('1.0000', '4.0000'))->toBeFalse();
    });

    it('flags decreases beyond the configured minimum ratio', function (): void {
        $policy = new PricingAnomalyPolicy;

        expect($policy->isAnomalous('1.0000', '0.1000'))->toBeTrue()
            ->and($policy->isAnomalous('1.0000', '0.2500'))->toBeFalse();
    });

    it('treats a positive-to-zero change as anomalous', function (): void {
        $policy = new PricingAnomalyPolicy;

        expect($policy->isAnomalous('1.0000', '0.0000'))->toBeTrue();
    });

    it('treats a zero-to-positive change as not anomalous', function (): void {
        $policy = new PricingAnomalyPolicy;

        expect($policy->isAnomalous('0.0000', '1.0000'))->toBeFalse();
    });

    it('compares realistic four-decimal values exactly at and around the boundaries', function (): void {
        $policy = new PricingAnomalyPolicy;

        expect($policy->isAnomalous('0.3333', '1.3332'))->toBeFalse()
            ->and($policy->isAnomalous('0.3333', '1.3333'))->toBeTrue()
            ->and($policy->isAnomalous('0.3333', '0.0834'))->toBeFalse()
            ->and($policy->isAnomalous('0.3333', '0.0833'))->toBeTrue();
    });

    it('honors config overrides for the ratios through the approved config path', function (): void {
        config([
            'mediamanager.ai.pricing.max_increase_ratio' => 2.0,
            'mediamanager.ai.pricing.min_decrease_ratio' => 0.5,
        ]);

        $policy = new PricingAnomalyPolicy;

        expect($policy->isAnomalous('0.3333', '0.6666'))->toBeFalse()
            ->and($policy->isAnomalous('0.3333', '0.6667'))->toBeTrue()
            ->and($policy->isAnomalous('0.3333', '0.1667'))->toBeFalse()
            ->and($policy->isAnomalous('0.3333', '0.1666'))->toBeTrue();
    });
});

describe('AiModelPriceWriter', function (): void {
    it('creates a new row from a complete candidate', function (): void {
        $modelPriceCandidate = priceCandidate('openai', 'gpt-5-mini', [
            'input_per_mtok' => '0.2500',
            'output_per_mtok' => '2.0000',
            'cache_read_per_mtok' => '0.0250',
            'cache_write_per_mtok' => '0.5000',
            'reasoning_per_mtok' => '3.0000',
            'batch_input_per_mtok' => '0.1250',
            'batch_output_per_mtok' => '1.0000',
            'batch_cache_read_per_mtok' => '0.0125',
            'batch_cache_write_per_mtok' => '0.2500',
            'batch_reasoning_per_mtok' => '1.5000',
        ]);

        $writeOutcome = priceWriter()->write($modelPriceCandidate, RefreshScope::all(), PricingSource::ModelsDev);

        expect($writeOutcome)->toBe(WriteOutcome::Created);

        $aiModelPrice = AiModelPrice::query()->where('provider', 'openai')->where('model', 'gpt-5-mini')->sole();

        expect($aiModelPrice->input_per_mtok)->toBe('0.2500')
            ->and($aiModelPrice->output_per_mtok)->toBe('2.0000')
            ->and($aiModelPrice->cache_read_per_mtok)->toBe('0.0250')
            ->and($aiModelPrice->cache_write_per_mtok)->toBe('0.5000')
            ->and($aiModelPrice->reasoning_per_mtok)->toBe('3.0000')
            ->and($aiModelPrice->batch_input_per_mtok)->toBe('0.1250')
            ->and($aiModelPrice->batch_reasoning_per_mtok)->toBe('1.5000')
            ->and($aiModelPrice->pricing_source)->toBe(PricingSource::ModelsDev);
    });

    it('defaults absent standard optional fields to zero on a new row', function (): void {
        $modelPriceCandidate = priceCandidate('openai', 'gpt-5-nano', [
            'input_per_mtok' => '0.1000',
            'output_per_mtok' => '0.4000',
            'cache_read_per_mtok' => null,
            'cache_write_per_mtok' => null,
            'reasoning_per_mtok' => null,
        ]);

        priceWriter()->write($modelPriceCandidate, RefreshScope::all(), PricingSource::ModelsDev);

        $aiModelPrice = AiModelPrice::query()->where('model', 'gpt-5-nano')->sole();

        expect($aiModelPrice->cache_read_per_mtok)->toBe('0.0000')
            ->and($aiModelPrice->cache_write_per_mtok)->toBe('0.0000')
            ->and($aiModelPrice->reasoning_per_mtok)->toBe('0.0000');
    });

    it('leaves absent batch fields null on a new row', function (): void {
        $modelPriceCandidate = priceCandidate('openai', 'gpt-5-batchless', [
            'input_per_mtok' => '0.1000',
            'output_per_mtok' => '0.4000',
        ]);

        priceWriter()->write($modelPriceCandidate, RefreshScope::all(), PricingSource::ModelsDev);

        $aiModelPrice = AiModelPrice::query()->where('model', 'gpt-5-batchless')->sole();

        expect($aiModelPrice->batch_input_per_mtok)->toBeNull()
            ->and($aiModelPrice->batch_output_per_mtok)->toBeNull()
            ->and($aiModelPrice->batch_cache_read_per_mtok)->toBeNull()
            ->and($aiModelPrice->batch_cache_write_per_mtok)->toBeNull()
            ->and($aiModelPrice->batch_reasoning_per_mtok)->toBeNull();
    });

    it('merges only supplied fields on update and preserves absent optional and batch fields', function (): void {
        $row = AiModelPrice::factory()->create([
            'provider' => 'openai',
            'model' => 'gpt-preserve',
            'input_per_mtok' => '1.0000',
            'output_per_mtok' => '2.0000',
            'cache_read_per_mtok' => '0.5000',
            'batch_input_per_mtok' => '0.7500',
        ]);

        $modelPriceCandidate = priceCandidate('openai', 'gpt-preserve', [
            'input_per_mtok' => '1.2000',
        ]);

        $writeOutcome = priceWriter()->write($modelPriceCandidate, RefreshScope::all(), PricingSource::ModelsDev);

        expect($writeOutcome)->toBe(WriteOutcome::Updated);

        $row->refresh();

        expect($row->input_per_mtok)->toBe('1.2000')
            ->and($row->output_per_mtok)->toBe('2.0000')
            ->and($row->cache_read_per_mtok)->toBe('0.5000')
            ->and($row->batch_input_per_mtok)->toBe('0.7500');
    });

    it('writes an explicit zero rather than skipping the field when the existing value is null', function (): void {
        $row = AiModelPrice::factory()->create([
            'provider' => 'openai',
            'model' => 'gpt-zero',
            'input_per_mtok' => '1.0000',
            'output_per_mtok' => '2.0000',
            'batch_input_per_mtok' => null,
        ]);

        $modelPriceCandidate = priceCandidate('openai', 'gpt-zero', [
            'batch_input_per_mtok' => '0.0000',
        ]);

        $writeOutcome = priceWriter()->write($modelPriceCandidate, RefreshScope::all(), PricingSource::ModelsDev);

        expect($writeOutcome)->toBe(WriteOutcome::Updated);

        $row->refresh();

        expect($row->batch_input_per_mtok)->toBe('0.0000');
    });

    it('does not mutate a locked row', function (): void {
        $row = AiModelPrice::factory()->create([
            'provider' => 'openai',
            'model' => 'gpt-locked',
            'input_per_mtok' => '1.0000',
            'output_per_mtok' => '2.0000',
            'is_price_locked' => true,
        ]);

        $modelPriceCandidate = priceCandidate('openai', 'gpt-locked', [
            'input_per_mtok' => '9.0000',
        ]);

        $writeOutcome = priceWriter()->write($modelPriceCandidate, RefreshScope::all(), PricingSource::ModelsDev);

        expect($writeOutcome)->toBe(WriteOutcome::Locked);

        $row->refresh();

        expect($row->input_per_mtok)->toBe('1.0000');
    });

    it('returns unchanged without timestamp or provenance churn for equivalent automatic prices', function (): void {
        $row = AiModelPrice::factory()->create([
            'provider' => 'openai',
            'model' => 'gpt-same',
            'input_per_mtok' => '1.5000',
            'output_per_mtok' => '2.0000',
            'pricing_source' => PricingSource::Seed,
            'pricing_source_url' => 'https://example.com/original',
            'pricing_source_updated_at' => '2026-06-01',
            'pricing_synced_at' => '2026-06-02 12:00:00',
            'pricing_verified_at' => '2026-06-03 12:00:00',
        ]);

        $originalUpdatedAt = $row->updated_at;
        $originalSyncedAt = $row->pricing_synced_at;
        $originalVerifiedAt = $row->pricing_verified_at;

        $this->travel(1)->hours();

        $modelPriceCandidate = priceCandidate('openai', 'gpt-same', [
            'input_per_mtok' => '1.5',
            'output_per_mtok' => '2.0000',
        ], ['sourceUrl' => 'https://models.dev/openai', 'sourceUpdatedAt' => '2026-07-01']);

        $writeOutcome = priceWriter()->write($modelPriceCandidate, RefreshScope::all(), PricingSource::ModelsDev);

        expect($writeOutcome)->toBe(WriteOutcome::Unchanged);

        $row->refresh();

        expect($row->updated_at->equalTo($originalUpdatedAt))->toBeTrue()
            ->and($row->pricing_source)->toBe(PricingSource::Seed)
            ->and($row->pricing_source_url)->toBe('https://example.com/original')
            ->and($row->pricing_source_updated_at?->toDateString())->toBe('2026-06-01')
            ->and($row->pricing_synced_at?->equalTo($originalSyncedAt))->toBeTrue()
            ->and($row->pricing_verified_at?->equalTo($originalVerifiedAt))->toBeTrue();
    });

    it('stamps verification for unchanged first-party prices without replacing source provenance', function (): void {
        $row = AiModelPrice::factory()->create([
            'provider' => 'openai',
            'model' => 'gpt-verified-same',
            'input_per_mtok' => '1.5000',
            'output_per_mtok' => '2.0000',
            'pricing_source' => PricingSource::ModelsDev,
            'pricing_source_url' => 'https://models.dev/openai',
            'pricing_source_updated_at' => '2026-07-01',
            'pricing_synced_at' => '2026-07-02 12:00:00',
            'pricing_verified_at' => null,
        ]);

        $originalSyncedAt = $row->pricing_synced_at;

        $this->travel(1)->hours();

        $modelPriceCandidate = priceCandidate('openai', 'gpt-verified-same', [
            'input_per_mtok' => '1.5',
            'output_per_mtok' => '2.0000',
        ], ['sourceUrl' => 'https://openai.com/pricing', 'sourceUpdatedAt' => '2026-07-18']);

        $writeOutcome = priceWriter()->write(
            $modelPriceCandidate,
            RefreshScope::all(),
            PricingSource::FirstParty,
            firstPartyVerified: true,
        );

        expect($writeOutcome)->toBe(WriteOutcome::Unchanged);

        $row->refresh();

        expect($row->pricing_verified_at)->not->toBeNull()
            ->and($row->pricing_source)->toBe(PricingSource::ModelsDev)
            ->and($row->pricing_source_url)->toBe('https://models.dev/openai')
            ->and($row->pricing_source_updated_at?->toDateString())->toBe('2026-07-01')
            ->and($row->pricing_synced_at?->equalTo($originalSyncedAt))->toBeTrue();
    });

    it('rejects an increase beyond the anomaly ceiling', function (): void {
        $row = AiModelPrice::factory()->create([
            'provider' => 'openai',
            'model' => 'gpt-spike',
            'input_per_mtok' => '1.0000',
            'output_per_mtok' => '2.0000',
        ]);

        $modelPriceCandidate = priceCandidate('openai', 'gpt-spike', ['input_per_mtok' => '5.0000']);

        $writeOutcome = priceWriter()->write($modelPriceCandidate, RefreshScope::all(), PricingSource::ModelsDev);

        expect($writeOutcome)->toBe(WriteOutcome::RejectedAnomalous);

        $row->refresh();

        expect($row->input_per_mtok)->toBe('1.0000');
    });

    it('rejects a decrease beyond the anomaly floor', function (): void {
        $row = AiModelPrice::factory()->create([
            'provider' => 'openai',
            'model' => 'gpt-crash',
            'input_per_mtok' => '1.0000',
            'output_per_mtok' => '2.0000',
        ]);

        $modelPriceCandidate = priceCandidate('openai', 'gpt-crash', ['input_per_mtok' => '0.1000']);

        $writeOutcome = priceWriter()->write($modelPriceCandidate, RefreshScope::all(), PricingSource::ModelsDev);

        expect($writeOutcome)->toBe(WriteOutcome::RejectedAnomalous);

        $row->refresh();

        expect($row->input_per_mtok)->toBe('1.0000');
    });

    it('rejects a positive-to-zero primary rate change', function (): void {
        $row = AiModelPrice::factory()->create([
            'provider' => 'openai',
            'model' => 'gpt-vanish',
            'input_per_mtok' => '1.0000',
            'output_per_mtok' => '2.0000',
        ]);

        $modelPriceCandidate = priceCandidate('openai', 'gpt-vanish', ['input_per_mtok' => '0.0000']);

        $writeOutcome = priceWriter()->write($modelPriceCandidate, RefreshScope::all(), PricingSource::ModelsDev);

        expect($writeOutcome)->toBe(WriteOutcome::RejectedAnomalous);

        $row->refresh();

        expect($row->input_per_mtok)->toBe('1.0000');
    });

    it('rejects an anomalous cache price change', function (): void {
        $row = AiModelPrice::factory()->create([
            'provider' => 'openai',
            'model' => 'gpt-cache-anomaly',
            'cache_read_per_mtok' => '0.5000',
        ]);

        $modelPriceCandidate = priceCandidate('openai', 'gpt-cache-anomaly', ['cache_read_per_mtok' => '0.0000']);

        $writeOutcome = priceWriter()->write($modelPriceCandidate, RefreshScope::all(), PricingSource::ModelsDev);

        expect($writeOutcome)->toBe(WriteOutcome::RejectedAnomalous);

        $row->refresh();

        expect($row->cache_read_per_mtok)->toBe('0.5000');
    });

    it('rejects an anomalous reasoning price change', function (): void {
        $row = AiModelPrice::factory()->create([
            'provider' => 'openai',
            'model' => 'gpt-reasoning-anomaly',
            'reasoning_per_mtok' => '1.0000',
        ]);

        $modelPriceCandidate = priceCandidate('openai', 'gpt-reasoning-anomaly', ['reasoning_per_mtok' => '5.0000']);

        $writeOutcome = priceWriter()->write($modelPriceCandidate, RefreshScope::all(), PricingSource::ModelsDev);

        expect($writeOutcome)->toBe(WriteOutcome::RejectedAnomalous);

        $row->refresh();

        expect($row->reasoning_per_mtok)->toBe('1.0000');
    });

    it('rejects an anomalous batch price change', function (): void {
        $row = AiModelPrice::factory()->create([
            'provider' => 'openai',
            'model' => 'gpt-batch-anomaly',
            'batch_output_per_mtok' => '1.0000',
        ]);

        $modelPriceCandidate = priceCandidate('openai', 'gpt-batch-anomaly', ['batch_output_per_mtok' => '0.1000']);

        $writeOutcome = priceWriter()->write($modelPriceCandidate, RefreshScope::all(), PricingSource::ModelsDev);

        expect($writeOutcome)->toBe(WriteOutcome::RejectedAnomalous);

        $row->refresh();

        expect($row->batch_output_per_mtok)->toBe('1.0000');
    });

    it('allows a zero-to-positive primary rate change', function (): void {
        $row = AiModelPrice::factory()->create([
            'provider' => 'openai',
            'model' => 'gpt-emerge',
            'input_per_mtok' => '0.0000',
            'output_per_mtok' => '2.0000',
        ]);

        $modelPriceCandidate = priceCandidate('openai', 'gpt-emerge', ['input_per_mtok' => '1.0000']);

        $writeOutcome = priceWriter()->write($modelPriceCandidate, RefreshScope::all(), PricingSource::ModelsDev);

        expect($writeOutcome)->toBe(WriteOutcome::Updated);

        $row->refresh();

        expect($row->input_per_mtok)->toBe('1.0000');
    });

    it('accepts an otherwise anomalous change when first-party verified and records verification', function (): void {
        $row = AiModelPrice::factory()->create([
            'provider' => 'openai',
            'model' => 'gpt-verified',
            'input_per_mtok' => '1.0000',
            'output_per_mtok' => '2.0000',
            'pricing_verified_at' => null,
        ]);

        // Verification grade requires BOTH primary rates to be re-read; a
        // full-rate first-party candidate keeps the anomaly bypass and stamp.
        $modelPriceCandidate = priceCandidate('openai', 'gpt-verified', [
            'input_per_mtok' => '9.0000',
            'output_per_mtok' => '2.0000',
        ]);

        $writeOutcome = priceWriter()->write(
            $modelPriceCandidate,
            RefreshScope::all(),
            PricingSource::FirstParty,
            firstPartyVerified: true,
        );

        expect($writeOutcome)->toBe(WriteOutcome::Updated);

        $row->refresh();

        expect($row->input_per_mtok)->toBe('9.0000')
            ->and($row->pricing_source)->toBe(PricingSource::FirstParty)
            ->and($row->pricing_verified_at)->not->toBeNull();
    });

    it('rejects a candidate whose supplied field set is empty', function (): void {
        // An all-null tool call supplies nothing: there is nothing to persist,
        // so it is Rejected outright — no row is touched and no verification is
        // stamped, even when the caller claims first-party verification.
        $row = AiModelPrice::factory()->create([
            'provider' => 'openai',
            'model' => 'gpt-empty',
            'input_per_mtok' => '1.0000',
            'output_per_mtok' => '2.0000',
            'pricing_verified_at' => null,
        ]);

        $writeOutcome = priceWriter()->write(
            priceCandidate('openai', 'gpt-empty', []),
            RefreshScope::all(),
            PricingSource::FirstParty,
            firstPartyVerified: true,
        );

        expect($writeOutcome)->toBe(WriteOutcome::Rejected);

        $row->refresh();

        expect($row->input_per_mtok)->toBe('1.0000')
            ->and($row->pricing_verified_at)->toBeNull();
    });

    it('downgrades verification when both primary rates are not supplied', function (): void {
        // A cache-only update persists under normal merge rules, but a write
        // that did not re-read both primary rates cannot be verification-grade:
        // pricing_verified_at stays null.
        $row = AiModelPrice::factory()->create([
            'provider' => 'openai',
            'model' => 'gpt-cache-only',
            'input_per_mtok' => '1.0000',
            'output_per_mtok' => '2.0000',
            'cache_read_per_mtok' => '0.2000',
            'pricing_verified_at' => null,
        ]);

        $writeOutcome = priceWriter()->write(
            priceCandidate('openai', 'gpt-cache-only', ['cache_read_per_mtok' => '0.2500']),
            RefreshScope::all(),
            PricingSource::FirstParty,
            firstPartyVerified: true,
        );

        expect($writeOutcome)->toBe(WriteOutcome::Updated);

        $row->refresh();

        expect($row->cache_read_per_mtok)->toBe('0.2500')
            ->and($row->pricing_verified_at)->toBeNull();
    });

    it('applies the anomaly guard to a downgraded partial-rate first-party write', function (): void {
        // Without both primary rates the firstPartyVerified bypass is revoked,
        // so an anomalous single-rate jump is rejected like any automatic write.
        $row = AiModelPrice::factory()->create([
            'provider' => 'openai',
            'model' => 'gpt-partial-anom',
            'input_per_mtok' => '1.0000',
            'output_per_mtok' => '2.0000',
        ]);

        $writeOutcome = priceWriter()->write(
            priceCandidate('openai', 'gpt-partial-anom', ['input_per_mtok' => '9.0000']),
            RefreshScope::all(),
            PricingSource::FirstParty,
            firstPartyVerified: true,
        );

        expect($writeOutcome)->toBe(WriteOutcome::RejectedAnomalous);

        $row->refresh();

        expect($row->input_per_mtok)->toBe('1.0000')
            ->and($row->pricing_verified_at)->toBeNull();
    });

    it('downgrades an unchanged partial-rate first-party write to no verification stamp', function (): void {
        $row = AiModelPrice::factory()->create([
            'provider' => 'openai',
            'model' => 'gpt-partial-same',
            'input_per_mtok' => '1.0000',
            'output_per_mtok' => '2.0000',
            'cache_read_per_mtok' => '0.2000',
            'pricing_verified_at' => null,
        ]);

        $writeOutcome = priceWriter()->write(
            priceCandidate('openai', 'gpt-partial-same', ['cache_read_per_mtok' => '0.2000']),
            RefreshScope::all(),
            PricingSource::FirstParty,
            firstPartyVerified: true,
        );

        expect($writeOutcome)->toBe(WriteOutcome::Unchanged);

        $row->refresh();

        expect($row->pricing_verified_at)->toBeNull();
    });

    it('records provenance and source timestamps without verification for automatic sources', function (): void {
        $modelPriceCandidate = priceCandidate('openai', 'gpt-prov', [
            'input_per_mtok' => '1.0000',
            'output_per_mtok' => '2.0000',
        ], ['sourceUrl' => 'https://models.dev/openai', 'sourceUpdatedAt' => '2026-07-01']);

        priceWriter()->write($modelPriceCandidate, RefreshScope::all(), PricingSource::ModelsDev);

        $aiModelPrice = AiModelPrice::query()->where('model', 'gpt-prov')->sole();

        expect($aiModelPrice->pricing_source)->toBe(PricingSource::ModelsDev)
            ->and($aiModelPrice->pricing_source_url)->toBe('https://models.dev/openai')
            ->and($aiModelPrice->pricing_source_updated_at?->toDateString())->toBe('2026-07-01')
            ->and($aiModelPrice->pricing_synced_at)->not->toBeNull()
            ->and($aiModelPrice->pricing_verified_at)->toBeNull();
    });

    it('never mutates free pool, lock, or rate-limit associations', function (): void {
        $pool = AiFreeUsagePool::factory()->create();

        $row = AiModelPrice::factory()->create([
            'provider' => 'openai',
            'model' => 'gpt-assoc',
            'input_per_mtok' => '1.0000',
            'output_per_mtok' => '2.0000',
            'free_usage_pool_id' => $pool->id,
            'is_price_locked' => false,
        ]);

        $rateLimit = AiModelRateLimit::factory()->create([
            'ai_model_price_id' => $row->id,
            'metric' => RateLimitMetric::Tokens,
            'period' => RateLimitPeriod::Day,
            'limit_value' => 5000,
        ]);

        $modelPriceCandidate = priceCandidate('openai', 'gpt-assoc', ['input_per_mtok' => '1.5000']);

        priceWriter()->write($modelPriceCandidate, RefreshScope::all(), PricingSource::ModelsDev);

        $row->refresh();

        expect($row->free_usage_pool_id)->toBe($pool->id)
            ->and($row->is_price_locked)->toBeFalse();

        $rateLimit->refresh();

        expect($rateLimit->limit_value)->toBe(5000)
            ->and($rateLimit->metric)->toBe(RateLimitMetric::Tokens)
            ->and($rateLimit->period)->toBe(RateLimitPeriod::Day);
    });

    it('reports a would-create dry run without writing', function (): void {
        $modelPriceCandidate = priceCandidate('openai', 'gpt-dry-new', [
            'input_per_mtok' => '1.0000',
            'output_per_mtok' => '2.0000',
        ]);

        $writeOutcome = priceWriter()->write($modelPriceCandidate, RefreshScope::all(), PricingSource::ModelsDev, dryRun: true);

        expect($writeOutcome)->toBe(WriteOutcome::WouldCreate)
            ->and(AiModelPrice::query()->where('model', 'gpt-dry-new')->exists())->toBeFalse();
    });

    it('reports a would-update dry run without writing', function (): void {
        $row = AiModelPrice::factory()->create([
            'provider' => 'openai',
            'model' => 'gpt-dry-existing',
            'input_per_mtok' => '1.0000',
            'output_per_mtok' => '2.0000',
        ]);

        $modelPriceCandidate = priceCandidate('openai', 'gpt-dry-existing', ['input_per_mtok' => '1.5000']);

        $writeOutcome = priceWriter()->write($modelPriceCandidate, RefreshScope::all(), PricingSource::ModelsDev, dryRun: true);

        expect($writeOutcome)->toBe(WriteOutcome::WouldUpdate);

        $row->refresh();

        expect($row->input_per_mtok)->toBe('1.0000');
    });

    it('rejects creating a new OpenRouter row but updates an existing one', function (): void {
        $modelPriceCandidate = priceCandidate('openrouter', 'vendor/new-model', [
            'input_per_mtok' => '1.0000',
            'output_per_mtok' => '2.0000',
        ]);

        $writeOutcome = priceWriter()->write($modelPriceCandidate, RefreshScope::all(), PricingSource::ModelsDev);

        expect($writeOutcome)->toBe(WriteOutcome::Rejected)
            ->and(AiModelPrice::query()->where('provider', 'openrouter')->exists())->toBeFalse();

        $existing = AiModelPrice::factory()->create([
            'provider' => 'openrouter',
            'model' => 'vendor/existing-model',
            'input_per_mtok' => '1.0000',
            'output_per_mtok' => '2.0000',
        ]);

        $updateCandidate = priceCandidate('openrouter', 'vendor/existing-model', ['input_per_mtok' => '1.5000']);

        $updateOutcome = priceWriter()->write($updateCandidate, RefreshScope::all(), PricingSource::ModelsDev);

        expect($updateOutcome)->toBe(WriteOutcome::Updated);

        $existing->refresh();

        expect($existing->input_per_mtok)->toBe('1.5000');
    });

    it('canonicalizes the provider identity before persistence', function (): void {
        $modelPriceCandidate = priceCandidate('google', 'gemini-2.5-pro', [
            'input_per_mtok' => '1.2500',
            'output_per_mtok' => '5.0000',
        ]);

        $writeOutcome = priceWriter()->write($modelPriceCandidate, RefreshScope::all(), PricingSource::ModelsDev);

        expect($writeOutcome)->toBe(WriteOutcome::Created)
            ->and(AiModelPrice::query()->where('provider', 'gemini')->where('model', 'gemini-2.5-pro')->exists())->toBeTrue()
            ->and(AiModelPrice::query()->where('provider', 'google')->exists())->toBeFalse();
    });

    it('rejects a candidate whose provider is outside the run scope', function (): void {
        $modelPriceCandidate = priceCandidate('openai', 'gpt-out-of-scope', [
            'input_per_mtok' => '1.0000',
            'output_per_mtok' => '2.0000',
        ]);

        $writeOutcome = priceWriter()->write($modelPriceCandidate, RefreshScope::forProviders(['anthropic']), PricingSource::ModelsDev);

        expect($writeOutcome)->toBe(WriteOutcome::Rejected)
            ->and(AiModelPrice::query()->where('model', 'gpt-out-of-scope')->exists())->toBeFalse();
    });

    it('rejects a new row missing a required primary rate', function (): void {
        $modelPriceCandidate = priceCandidate('openai', 'gpt-incomplete', [
            'input_per_mtok' => '1.0000',
        ]);

        $writeOutcome = priceWriter()->write($modelPriceCandidate, RefreshScope::all(), PricingSource::ModelsDev);

        expect($writeOutcome)->toBe(WriteOutcome::Rejected)
            ->and(AiModelPrice::query()->where('model', 'gpt-incomplete')->exists())->toBeFalse();
    });

    it('rejects a candidate with an invalid or out-of-range decimal', function (): void {
        $modelPriceCandidate = priceCandidate('openai', 'gpt-negative', [
            'input_per_mtok' => '-1.0000',
            'output_per_mtok' => '2.0000',
        ]);

        $huge = priceCandidate('openai', 'gpt-huge', [
            'input_per_mtok' => '1.0000',
            'output_per_mtok' => '100000.0000',
        ]);

        expect(priceWriter()->write($modelPriceCandidate, RefreshScope::all(), PricingSource::ModelsDev))->toBe(WriteOutcome::Rejected)
            ->and(priceWriter()->write($huge, RefreshScope::all(), PricingSource::ModelsDev))->toBe(WriteOutcome::Rejected)
            ->and(AiModelPrice::query()->whereIn('model', ['gpt-negative', 'gpt-huge'])->exists())->toBeFalse();
    });
});

describe('dated variant creates', function (): void {
    test('rejects creating a dated snapshot when its base model row exists', function (string $datedId): void {
        AiModelPrice::factory()->create(['provider' => 'anthropic', 'model' => 'claude-haiku-4-5']);

        $writeOutcome = priceWriter()->write(
            priceCandidate('anthropic', $datedId, ['input_per_mtok' => '1.0000', 'output_per_mtok' => '5.0000']),
            RefreshScope::all(),
            PricingSource::FirstParty,
            firstPartyVerified: true,
        );

        expect($writeOutcome)->toBe(WriteOutcome::Rejected)
            ->and(AiModelPrice::query()->where('model', $datedId)->exists())->toBeFalse();
    })->with([
        'compact' => ['claude-haiku-4-5-20251001'],
        'dashed' => ['claude-haiku-4-5-2025-10-01'],
    ]);

    test('creates a dated model that has no base row', function (): void {
        $writeOutcome = priceWriter()->write(
            priceCandidate('anthropic', 'claude-legacy-20240229', ['input_per_mtok' => '15.0000', 'output_per_mtok' => '75.0000']),
            RefreshScope::all(),
            PricingSource::FirstParty,
        );

        expect($writeOutcome)->toBe(WriteOutcome::Created);
    });

    test('still updates an existing dated row alongside its base', function (): void {
        AiModelPrice::factory()->create(['provider' => 'anthropic', 'model' => 'claude-haiku-4-5']);
        AiModelPrice::factory()->create([
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5-20251001',
            'input_per_mtok' => '1.0000',
            'output_per_mtok' => '5.0000',
        ]);

        $writeOutcome = priceWriter()->write(
            priceCandidate('anthropic', 'claude-haiku-4-5-20251001', ['input_per_mtok' => '2.0000', 'output_per_mtok' => '10.0000']),
            RefreshScope::all(),
            PricingSource::FirstParty,
            firstPartyVerified: true,
        );

        expect($writeOutcome)->toBe(WriteOutcome::Updated);
    });
});

describe('identifier hygiene', function (): void {
    test('rejects a model identifier that exceeds the column limit', function (): void {
        $writeOutcome = priceWriter()->write(
            priceCandidate('openai', str_repeat('m', 256), ['input_per_mtok' => '1.0000', 'output_per_mtok' => '2.0000']),
            RefreshScope::all(),
            PricingSource::FirstParty,
        );

        expect($writeOutcome)->toBe(WriteOutcome::Rejected);
    });

    test('rejects a model identifier containing control characters', function (): void {
        $writeOutcome = priceWriter()->write(
            priceCandidate('openai', "gpt-5\n-mini", ['input_per_mtok' => '1.0000', 'output_per_mtok' => '2.0000']),
            RefreshScope::all(),
            PricingSource::FirstParty,
        );

        expect($writeOutcome)->toBe(WriteOutcome::Rejected)
            ->and(AiModelPrice::query()->count())->toBe(0);
    });

    test('trims surrounding whitespace before persisting the identifier', function (): void {
        $writeOutcome = priceWriter()->write(
            priceCandidate('openai', '  gpt-5-mini  ', ['input_per_mtok' => '1.0000', 'output_per_mtok' => '2.0000']),
            RefreshScope::all(),
            PricingSource::FirstParty,
        );

        expect($writeOutcome)->toBe(WriteOutcome::Created)
            ->and(AiModelPrice::query()->where('model', 'gpt-5-mini')->exists())->toBeTrue();
    });
});
