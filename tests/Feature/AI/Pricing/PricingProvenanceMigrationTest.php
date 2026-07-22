<?php

declare(strict_types=1);

use App\Enums\PricingSource;
use App\Models\AiModelPrice;
use App\Models\AiPriceRefreshRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AiModelPriceSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Insert a raw legacy (pre-provenance) price row that mimics what the old
 * seeder wrote: no pricing source, unlocked, batch rates present.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertLegacyAiModelPrice(array $overrides = []): int
{
    return DB::table('ai_model_prices')->insertGetId(array_merge([
        'provider' => 'openai',
        'model' => 'gpt-5.5',
        'input_per_mtok' => 5.00,
        'output_per_mtok' => 30.00,
        'cache_read_per_mtok' => 0.50,
        'cache_write_per_mtok' => 5.00,
        'reasoning_per_mtok' => 30.00,
        'batch_input_per_mtok' => 2.50,
        'batch_output_per_mtok' => 15.00,
        'batch_cache_read_per_mtok' => 0.25,
        'batch_cache_write_per_mtok' => 2.50,
        'batch_reasoning_per_mtok' => 15.00,
        'pricing_source' => null,
        'is_price_locked' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/**
 * Run the classification/normalization data migration in isolation against
 * whatever rows the test has seeded into the table.
 */
function runAiModelPriceClassificationMigration(): void
{
    $migration = require database_path(
        'migrations/2026_07_19_085141_classify_and_normalize_ai_model_prices.php',
    );

    $migration->up();
}

/**
 * Run the classification migration's deliberately non-destructive rollback.
 */
function rollbackAiModelPriceClassificationMigration(): void
{
    $migration = require database_path(
        'migrations/2026_07_19_085141_classify_and_normalize_ai_model_prices.php',
    );

    $migration->down();
}

/**
 * Price values for the frozen gemini-2.5-pro seed row, used to construct
 * equal google/gemini collisions.
 *
 * @return array<string, mixed>
 */
function geminiSeedPriceValues(): array
{
    return [
        'model' => 'gemini-2.5-pro',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 10.00,
        'cache_read_per_mtok' => 0.125,
        'cache_write_per_mtok' => 1.25,
        'reasoning_per_mtok' => 10.00,
        'batch_input_per_mtok' => 0.625,
        'batch_output_per_mtok' => 5.00,
        'batch_cache_read_per_mtok' => 0.0625,
        'batch_cache_write_per_mtok' => 0.625,
        'batch_reasoning_per_mtok' => 5.00,
    ];
}

/**
 * Insert a free usage pool row and return its id.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertFreeUsagePool(array $overrides = []): int
{
    return DB::table('ai_free_usage_pools')->insertGetId(array_merge([
        'name' => 'pool '.uniqid('', true),
        'period' => 'monthly',
        'unified' => false,
        'free_input_tokens' => 1_000_000,
        'free_output_tokens' => 1_000_000,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/**
 * Insert a rate limit row bound to a price id and return its id.
 */
function insertAiModelRateLimit(int $priceId, string $metric, string $period, int $limitValue): int
{
    return DB::table('ai_model_rate_limits')->insertGetId([
        'ai_model_price_id' => $priceId,
        'metric' => $metric,
        'period' => $period,
        'limit_value' => $limitValue,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('pricing sources have stable backed values', function (): void {
    expect(PricingSource::cases())->toBe([
        PricingSource::Seed,
        PricingSource::ModelsDev,
        PricingSource::FirstParty,
        PricingSource::Manual,
        PricingSource::Legacy,
    ]);

    expect(PricingSource::values())->toBe([
        'seed',
        'models_dev',
        'first_party',
        'manual',
        'legacy',
    ]);
});

test('the ai_model_prices table gains the pricing provenance columns', function (): void {
    $columns = [
        'pricing_source',
        'pricing_source_url',
        'pricing_source_updated_at',
        'pricing_synced_at',
        'pricing_verified_at',
        'is_price_locked',
    ];

    foreach ($columns as $column) {
        expect(Schema::hasColumn('ai_model_prices', $column))
            ->toBeTrue(sprintf('expected ai_model_prices to have the %s column', $column));
    }
});

test('a price row casts its provenance source enum and lock flag', function (): void {
    $price = AiModelPrice::factory()->create([
        'pricing_source' => PricingSource::ModelsDev,
        'pricing_source_url' => 'https://models.dev/api.json',
        'pricing_source_updated_at' => '2026-07-01',
        'pricing_synced_at' => '2026-07-01 12:00:00',
        'pricing_verified_at' => '2026-07-02 09:30:00',
        'is_price_locked' => true,
    ]);

    $fresh = $price->fresh();

    expect($fresh->pricing_source)->toBe(PricingSource::ModelsDev);
    expect($fresh->pricing_source_url)->toBe('https://models.dev/api.json');
    expect($fresh->pricing_source_updated_at)->toBeInstanceOf(CarbonImmutable::class);
    expect($fresh->pricing_synced_at)->toBeInstanceOf(CarbonImmutable::class);
    expect($fresh->pricing_verified_at)->toBeInstanceOf(CarbonImmutable::class);
    expect($fresh->is_price_locked)->toBeTrue();
});

test('the factory defaults a price row to the legacy source and unlocked', function (): void {
    $price = AiModelPrice::factory()->create();

    expect($price->pricing_source)->toBe(PricingSource::Legacy);
    expect($price->is_price_locked)->toBeFalse();
});

test('the ai_price_refresh_runs table has the audit columns', function (): void {
    $columns = [
        'mode',
        'trigger',
        'triggered_by_user_id',
        'status',
        'models_dev_status',
        'providers_requested',
        'providers_succeeded',
        'providers_failed',
        'models_created',
        'models_updated',
        'models_unchanged',
        'models_locked',
        'models_rejected',
        'models_tiered',
        'fallback_targets',
        'provider_results',
        'error_message',
        'started_at',
        'completed_at',
    ];

    foreach ($columns as $column) {
        expect(Schema::hasColumn('ai_price_refresh_runs', $column))
            ->toBeTrue(sprintf('expected ai_price_refresh_runs to have the %s column', $column));
    }
});

test('a refresh run casts its json summaries and allows a null triggering user', function (): void {
    $run = AiPriceRefreshRun::factory()->create([
        'triggered_by_user_id' => null,
        'fallback_targets' => ['openai', 'anthropic'],
        'provider_results' => [
            'openai' => ['status' => 'ok', 'models' => 12],
        ],
        'started_at' => '2026-07-01 12:00:00',
    ]);

    $fresh = $run->fresh();

    expect($fresh->fallback_targets)->toBeArray()
        ->and($fresh->provider_results)->toBeArray()
        ->and($fresh->triggeredBy)->toBeNull()
        ->and($fresh->started_at)->toBeInstanceOf(CarbonImmutable::class);
});

test('a refresh run resolves its triggering user relation', function (): void {
    $user = User::factory()->create();

    $run = AiPriceRefreshRun::factory()->create([
        'triggered_by_user_id' => $user->id,
    ]);

    expect($run->triggeredBy)->not->toBeNull()
        ->and($run->triggeredBy->is($user))->toBeTrue();
});

test('an existing row that matches the frozen seed exactly is stamped seed and left unlocked', function (): void {
    $id = insertLegacyAiModelPrice();

    runAiModelPriceClassificationMigration();

    $aiModelPrice = AiModelPrice::query()->findOrFail($id);

    expect($aiModelPrice->pricing_source)->toBe(PricingSource::Seed)
        ->and($aiModelPrice->is_price_locked)->toBeFalse();
});

test('the frozen seed match tolerates differing decimal string representations', function (): void {
    // 0.075 -> stored/normalized as 0.0750; a naive string compare would miss it.
    $id = insertLegacyAiModelPrice([
        'provider' => 'openai',
        'model' => 'gpt-5.4-mini',
        'input_per_mtok' => 0.75,
        'output_per_mtok' => 4.50,
        'cache_read_per_mtok' => 0.075,
        'cache_write_per_mtok' => 0.75,
        'reasoning_per_mtok' => 4.50,
        'batch_input_per_mtok' => 0.375,
        'batch_output_per_mtok' => 2.25,
        'batch_cache_read_per_mtok' => 0.0375,
        'batch_cache_write_per_mtok' => 0.375,
        'batch_reasoning_per_mtok' => 2.25,
    ]);

    runAiModelPriceClassificationMigration();

    $aiModelPrice = AiModelPrice::query()->findOrFail($id);

    expect($aiModelPrice->pricing_source)->toBe(PricingSource::Seed)
        ->and($aiModelPrice->is_price_locked)->toBeFalse();
});

test('a modified seed row is locked and marked manual', function (): void {
    $id = insertLegacyAiModelPrice([
        'input_per_mtok' => 4.99, // deviates from the frozen 5.00 seed rate
    ]);

    runAiModelPriceClassificationMigration();

    $aiModelPrice = AiModelPrice::query()->findOrFail($id);

    expect($aiModelPrice->pricing_source)->toBe(PricingSource::Manual)
        ->and($aiModelPrice->is_price_locked)->toBeTrue();
});

test('an unknown row absent from the frozen seed is locked and marked manual', function (): void {
    $id = insertLegacyAiModelPrice([
        'provider' => 'openai',
        'model' => 'gpt-nonexistent-9',
    ]);

    runAiModelPriceClassificationMigration();

    $aiModelPrice = AiModelPrice::query()->findOrFail($id);

    expect($aiModelPrice->pricing_source)->toBe(PricingSource::Manual)
        ->and($aiModelPrice->is_price_locked)->toBeTrue();
});

test('a lone google row is renamed to the canonical gemini provider', function (): void {
    $id = insertLegacyAiModelPrice([
        'provider' => 'google',
        'model' => 'gemini-2.5-pro',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 10.00,
        'cache_read_per_mtok' => 0.125,
        'cache_write_per_mtok' => 1.25,
        'reasoning_per_mtok' => 10.00,
        'batch_input_per_mtok' => 0.625,
        'batch_output_per_mtok' => 5.00,
        'batch_cache_read_per_mtok' => 0.0625,
        'batch_cache_write_per_mtok' => 0.625,
        'batch_reasoning_per_mtok' => 5.00,
    ]);

    runAiModelPriceClassificationMigration();

    $aiModelPrice = AiModelPrice::query()->findOrFail($id);

    expect($aiModelPrice->provider)->toBe('gemini')
        ->and($aiModelPrice->model)->toBe('gemini-2.5-pro')
        ->and(AiModelPrice::query()->where('provider', 'google')->exists())->toBeFalse();

    // A renamed row whose values match the frozen gemini seed is a seed row.
    expect($aiModelPrice->pricing_source)->toBe(PricingSource::Seed)
        ->and($aiModelPrice->is_price_locked)->toBeFalse();
});

test('an equal google/gemini collision deduplicates to a single gemini row', function (): void {
    $geminiValues = [
        'model' => 'gemini-2.5-pro',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 10.00,
        'cache_read_per_mtok' => 0.125,
        'cache_write_per_mtok' => 1.25,
        'reasoning_per_mtok' => 10.00,
        'batch_input_per_mtok' => 0.625,
        'batch_output_per_mtok' => 5.00,
        'batch_cache_read_per_mtok' => 0.0625,
        'batch_cache_write_per_mtok' => 0.625,
        'batch_reasoning_per_mtok' => 5.00,
    ];

    insertLegacyAiModelPrice(array_merge($geminiValues, ['provider' => 'gemini']));
    insertLegacyAiModelPrice(array_merge($geminiValues, ['provider' => 'google']));

    runAiModelPriceClassificationMigration();

    expect(AiModelPrice::query()->where('provider', 'google')->exists())->toBeFalse()
        ->and(AiModelPrice::query()->where('model', 'gemini-2.5-pro')->count())->toBe(1);

    $aiModelPrice = AiModelPrice::query()->where('provider', 'gemini')->firstOrFail();
    expect($aiModelPrice->pricing_source)->toBe(PricingSource::Seed);
});

test('an equal google/gemini collision transfers the google free-usage pool when gemini has none', function (): void {
    $poolId = insertFreeUsagePool();

    $geminiId = insertLegacyAiModelPrice(array_merge(geminiSeedPriceValues(), ['provider' => 'gemini']));
    insertLegacyAiModelPrice(array_merge(geminiSeedPriceValues(), [
        'provider' => 'google',
        'free_usage_pool_id' => $poolId,
    ]));

    runAiModelPriceClassificationMigration();

    expect(AiModelPrice::query()->where('provider', 'google')->exists())->toBeFalse()
        ->and(AiModelPrice::query()->where('model', 'gemini-2.5-pro')->count())->toBe(1);

    $aiModelPrice = AiModelPrice::query()->findOrFail($geminiId);
    expect($aiModelPrice->free_usage_pool_id)->toBe($poolId);
});

test('an equal google/gemini collision moves the google rate limits when gemini has none', function (): void {
    $geminiId = insertLegacyAiModelPrice(array_merge(geminiSeedPriceValues(), ['provider' => 'gemini']));
    $googleId = insertLegacyAiModelPrice(array_merge(geminiSeedPriceValues(), ['provider' => 'google']));

    insertAiModelRateLimit($googleId, 'requests', 'minute', 500);
    insertAiModelRateLimit($googleId, 'tokens', 'minute', 100_000);

    runAiModelPriceClassificationMigration();

    // The google row is gone but its rate limits survive, re-parented onto the
    // surviving gemini row rather than cascade-deleted.
    expect(AiModelPrice::query()->find($googleId))->toBeNull()
        ->and(DB::table('ai_model_rate_limits')->where('ai_model_price_id', $googleId)->count())->toBe(0)
        ->and(DB::table('ai_model_rate_limits')->where('ai_model_price_id', $geminiId)->count())->toBe(2);
});

test('an equal google/gemini collision with conflicting free-usage pools preserves both rows', function (): void {
    $geminiPoolId = insertFreeUsagePool(['name' => 'gemini pool']);
    $googlePoolId = insertFreeUsagePool(['name' => 'google pool']);

    $geminiId = insertLegacyAiModelPrice(array_merge(geminiSeedPriceValues(), [
        'provider' => 'gemini',
        'free_usage_pool_id' => $geminiPoolId,
    ]));
    $googleId = insertLegacyAiModelPrice(array_merge(geminiSeedPriceValues(), [
        'provider' => 'google',
        'free_usage_pool_id' => $googlePoolId,
    ]));

    runAiModelPriceClassificationMigration();

    $aiModelPrice = AiModelPrice::query()->findOrFail($geminiId);
    $google = AiModelPrice::query()->find($googleId);

    // Conflicting local policy: neither row is deleted, the pool assignment is
    // left untouched, and the canonical gemini row is locked for admin review.
    expect($google)->not->toBeNull()
        ->and($google->provider)->toBe('google')
        ->and($aiModelPrice->free_usage_pool_id)->toBe($geminiPoolId)
        ->and($aiModelPrice->is_price_locked)->toBeTrue()
        ->and($aiModelPrice->pricing_source)->toBe(PricingSource::Manual);
});

test('an equal google/gemini collision with rate limits on both rows preserves both rows', function (): void {
    $geminiId = insertLegacyAiModelPrice(array_merge(geminiSeedPriceValues(), ['provider' => 'gemini']));
    $googleId = insertLegacyAiModelPrice(array_merge(geminiSeedPriceValues(), ['provider' => 'google']));

    insertAiModelRateLimit($geminiId, 'requests', 'minute', 100);
    insertAiModelRateLimit($googleId, 'requests', 'minute', 500);

    runAiModelPriceClassificationMigration();

    $aiModelPrice = AiModelPrice::query()->findOrFail($geminiId);
    $google = AiModelPrice::query()->find($googleId);

    // Both rows carry their own rate limits: the collision is unresolved, so
    // both rows and both sets of rate limits survive.
    expect($google)->not->toBeNull()
        ->and($aiModelPrice->is_price_locked)->toBeTrue()
        ->and($aiModelPrice->pricing_source)->toBe(PricingSource::Manual)
        ->and(DB::table('ai_model_rate_limits')->where('ai_model_price_id', $geminiId)->count())->toBe(1)
        ->and(DB::table('ai_model_rate_limits')->where('ai_model_price_id', $googleId)->count())->toBe(1);
});

test('a differing google/gemini collision preserves both rows and locks the gemini row', function (): void {
    $geminiId = insertLegacyAiModelPrice([
        'provider' => 'gemini',
        'model' => 'gemini-2.5-pro',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 10.00,
        'cache_read_per_mtok' => 0.125,
        'cache_write_per_mtok' => 1.25,
        'reasoning_per_mtok' => 10.00,
        'batch_input_per_mtok' => 0.625,
        'batch_output_per_mtok' => 5.00,
        'batch_cache_read_per_mtok' => 0.0625,
        'batch_cache_write_per_mtok' => 0.625,
        'batch_reasoning_per_mtok' => 5.00,
    ]);

    $googleId = insertLegacyAiModelPrice([
        'provider' => 'google',
        'model' => 'gemini-2.5-pro',
        'input_per_mtok' => 99.00, // conflicting value
        'output_per_mtok' => 199.00,
        'cache_read_per_mtok' => 0,
        'cache_write_per_mtok' => 0,
        'reasoning_per_mtok' => 0,
        'batch_input_per_mtok' => null,
        'batch_output_per_mtok' => null,
        'batch_cache_read_per_mtok' => null,
        'batch_cache_write_per_mtok' => null,
        'batch_reasoning_per_mtok' => null,
    ]);

    runAiModelPriceClassificationMigration();

    $aiModelPrice = AiModelPrice::query()->findOrFail($geminiId);
    $google = AiModelPrice::query()->find($googleId);

    // Neither row is lost, and the non-canonical google row is protected from
    // future automatic writes until an administrator resolves the collision.
    expect($google)->not->toBeNull()
        ->and($google->provider)->toBe('google')
        ->and($google->pricing_source)->toBe(PricingSource::Manual)
        ->and($google->is_price_locked)->toBeTrue();

    // The gemini row is force-locked and flagged manual for admin review;
    // its conflicting price is not overwritten.
    expect($aiModelPrice->is_price_locked)->toBeTrue()
        ->and($aiModelPrice->pricing_source)->toBe(PricingSource::Manual)
        ->and($aiModelPrice->input_per_mtok)->toBe('1.2500');
});

test('the seeder stamps the seed source and removes legacy formula-derived batch rates', function (): void {
    $id = insertLegacyAiModelPrice();

    (new AiModelPriceSeeder)->run();

    $aiModelPrice = AiModelPrice::query()->findOrFail($id);

    expect($aiModelPrice->pricing_source)->toBe(PricingSource::Seed)
        // The old formula would have written 2.50 (50% of 5.00 input).
        ->and($aiModelPrice->batch_input_per_mtok)->toBeNull()
        ->and($aiModelPrice->batch_output_per_mtok)->toBeNull()
        ->and($aiModelPrice->batch_cache_read_per_mtok)->toBeNull()
        ->and($aiModelPrice->batch_cache_write_per_mtok)->toBeNull()
        ->and($aiModelPrice->batch_reasoning_per_mtok)->toBeNull();
});

test('the seeder leaves a locked manual row unchanged', function (): void {
    $price = AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-5.5',
        'input_per_mtok' => 123.4567,
        'output_per_mtok' => 765.4321,
        'pricing_source' => PricingSource::Manual,
        'pricing_source_url' => 'https://admin.example/manual-price',
        'is_price_locked' => true,
    ]);

    (new AiModelPriceSeeder)->run();

    $fresh = $price->fresh();

    expect($fresh->input_per_mtok)->toBe('123.4567')
        ->and($fresh->output_per_mtok)->toBe('765.4321')
        ->and($fresh->pricing_source)->toBe(PricingSource::Manual)
        ->and($fresh->pricing_source_url)->toBe('https://admin.example/manual-price')
        ->and($fresh->is_price_locked)->toBeTrue();
});

test('the seeder leaves an unlocked first-party row with explicit batch prices unchanged', function (): void {
    $price = AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-5.5-pro',
        'input_per_mtok' => 33.3333,
        'output_per_mtok' => 188.8888,
        'batch_input_per_mtok' => 11.1111,
        'batch_output_per_mtok' => 22.2222,
        'pricing_source' => PricingSource::FirstParty,
        'pricing_source_url' => 'https://openai.example/verified-pricing',
        'is_price_locked' => false,
    ]);

    (new AiModelPriceSeeder)->run();

    $fresh = $price->fresh();

    expect($fresh->input_per_mtok)->toBe('33.3333')
        ->and($fresh->output_per_mtok)->toBe('188.8888')
        ->and($fresh->batch_input_per_mtok)->toBe('11.1111')
        ->and($fresh->batch_output_per_mtok)->toBe('22.2222')
        ->and($fresh->pricing_source)->toBe(PricingSource::FirstParty)
        ->and($fresh->pricing_source_url)->toBe('https://openai.example/verified-pricing')
        ->and($fresh->is_price_locked)->toBeFalse();
});

test('the classification migration down and reapply preserve post-deploy provenance locks and rates', function (): void {
    $manual = AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-5.5',
        'input_per_mtok' => 51.1111,
        'pricing_source' => PricingSource::Manual,
        'pricing_source_url' => 'https://admin.example/manual-price',
        'is_price_locked' => true,
    ]);

    $firstParty = AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-5.5-pro',
        'input_per_mtok' => 52.2222,
        'batch_input_per_mtok' => 12.2222,
        'pricing_source' => PricingSource::FirstParty,
        'pricing_source_url' => 'https://provider.example/pricing',
        'is_price_locked' => false,
    ]);

    $modelsDev = AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-5.4',
        'input_per_mtok' => 53.3333,
        'pricing_source' => PricingSource::ModelsDev,
        'pricing_source_url' => 'https://models.dev/api.json',
        'is_price_locked' => false,
    ]);

    rollbackAiModelPriceClassificationMigration();
    runAiModelPriceClassificationMigration();

    $manual->refresh();
    $firstParty->refresh();
    $modelsDev->refresh();

    expect($manual->pricing_source)->toBe(PricingSource::Manual)
        ->and($manual->pricing_source_url)->toBe('https://admin.example/manual-price')
        ->and($manual->is_price_locked)->toBeTrue()
        ->and($manual->input_per_mtok)->toBe('51.1111')
        ->and($firstParty->pricing_source)->toBe(PricingSource::FirstParty)
        ->and($firstParty->pricing_source_url)->toBe('https://provider.example/pricing')
        ->and($firstParty->is_price_locked)->toBeFalse()
        ->and($firstParty->input_per_mtok)->toBe('52.2222')
        ->and($firstParty->batch_input_per_mtok)->toBe('12.2222')
        ->and($modelsDev->pricing_source)->toBe(PricingSource::ModelsDev)
        ->and($modelsDev->pricing_source_url)->toBe('https://models.dev/api.json')
        ->and($modelsDev->is_price_locked)->toBeFalse()
        ->and($modelsDev->input_per_mtok)->toBe('53.3333');
});
