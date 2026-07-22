<?php

declare(strict_types=1);

use App\Enums\PricingSource;
use App\Events\AiPriceRefreshStateChanged;
use App\Jobs\RefreshAiPricesJob;
use App\Models\AiFreeUsagePool;
use App\Models\AiModelPrice;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

test('guests cannot access AI prices', function (): void {
    $this->get(route('admin.ai-prices.index'))
        ->assertRedirect(route('login'));
});

test('non-admin cannot access AI prices', function (): void {
    $user = User::factory()->member()->create();

    $this->actingAs($user)
        ->get(route('admin.ai-prices.index'))
        ->assertForbidden();
});

test('admin sees seeded prices on index', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.ai-prices.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/AiPrices/Index')
            ->has('prices', fn ($prices) => $prices->etc())
        );
});

test('admin can add a new price', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.ai-prices.store'), [
            'provider' => 'openai',
            'model' => 'gpt-99-test',
            'input_per_mtok' => 1.23,
            'output_per_mtok' => 4.56,
            'cache_read_per_mtok' => 0.10,
            'cache_write_per_mtok' => 0.50,
            'reasoning_per_mtok' => 4.56,
        ])
        ->assertRedirect(route('admin.ai-prices.index'));

    $this->assertDatabaseHas('ai_model_prices', [
        'provider' => 'openai',
        'model' => 'gpt-99-test',
    ]);
});

test('store rejects duplicate provider+model', function (): void {
    $admin = User::factory()->admin()->create();
    AiModelPrice::create([
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'input_per_mtok' => 1,
        'output_per_mtok' => 1,
        'cache_read_per_mtok' => 0,
        'cache_write_per_mtok' => 0,
        'reasoning_per_mtok' => 0,
    ]);

    $this->actingAs($admin)
        ->post(route('admin.ai-prices.store'), [
            'provider' => 'openai',
            'model' => 'gpt-5-mini',
            'input_per_mtok' => 9,
            'output_per_mtok' => 9,
            'cache_read_per_mtok' => 0,
            'cache_write_per_mtok' => 0,
            'reasoning_per_mtok' => 0,
        ])
        ->assertSessionHasErrors('provider');
});

test('admin can update a price', function (): void {
    $admin = User::factory()->admin()->create();
    $price = AiModelPrice::create([
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'input_per_mtok' => 0.40,
        'output_per_mtok' => 1.60,
        'cache_read_per_mtok' => 0.10,
        'cache_write_per_mtok' => 0.40,
        'reasoning_per_mtok' => 1.60,
    ]);

    $this->actingAs($admin)
        ->put(route('admin.ai-prices.update', $price), [
            'input_per_mtok' => 0.50,
            'output_per_mtok' => 2.00,
            'cache_read_per_mtok' => 0.15,
            'cache_write_per_mtok' => 0.50,
            'reasoning_per_mtok' => 2.00,
        ])
        ->assertRedirect(route('admin.ai-prices.index'));

    expect((float) $price->fresh()->input_per_mtok)->toBe(0.50);
});

test('refresh queues a job, broadcasts queued state, and sets the running flag', function (): void {
    Bus::fake();
    Event::fake([AiPriceRefreshStateChanged::class]);
    Cache::forget(RefreshAiPricesJob::LOCK_KEY);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.ai-prices.refresh'))
        ->assertRedirect(route('admin.ai-prices.index'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'success');

    Bus::assertDispatched(fn (RefreshAiPricesJob $refreshAiPricesJob) => $refreshAiPricesJob->triggeredBy->is($admin));

    Event::assertDispatched(fn (AiPriceRefreshStateChanged $aiPriceRefreshStateChanged): bool => $aiPriceRefreshStateChanged->state === AiPriceRefreshStateChanged::STATE_QUEUED
        && $aiPriceRefreshStateChanged->triggeredBy?->is($admin));

    expect(RefreshAiPricesJob::isRunning())->toBeTrue();
});

test('refresh refuses to dispatch a second job while one is running', function (): void {
    Bus::fake();
    Event::fake([AiPriceRefreshStateChanged::class]);
    Cache::forget(RefreshAiPricesJob::LOCK_KEY);

    $admin = User::factory()->admin()->create();

    // First call acquires the lock.
    $this->actingAs($admin)
        ->post(route('admin.ai-prices.refresh'))
        ->assertRedirect(route('admin.ai-prices.index'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'success');

    Bus::assertDispatchedTimes(RefreshAiPricesJob::class, 1);

    // Second call hits the lock and is rejected.
    $this->actingAs($admin)
        ->post(route('admin.ai-prices.refresh'))
        ->assertRedirect(route('admin.ai-prices.index'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');

    Bus::assertDispatchedTimes(RefreshAiPricesJob::class, 1);
});

test('index exposes the refresh_running flag', function (): void {
    Cache::forget(RefreshAiPricesJob::LOCK_KEY);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.ai-prices.index'))
        ->assertInertia(fn ($page) => $page->where('refresh_running', false));

    RefreshAiPricesJob::tryLock($admin->id);

    $this->actingAs($admin)
        ->get(route('admin.ai-prices.index'))
        ->assertInertia(fn ($page) => $page->where('refresh_running', true));

    Cache::forget(RefreshAiPricesJob::LOCK_KEY);
});

test('admin can delete a price', function (): void {
    $admin = User::factory()->admin()->create();
    $price = AiModelPrice::create([
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'input_per_mtok' => 0.40,
        'output_per_mtok' => 1.60,
        'cache_read_per_mtok' => 0,
        'cache_write_per_mtok' => 0,
        'reasoning_per_mtok' => 0,
    ]);

    $this->actingAs($admin)
        ->delete(route('admin.ai-prices.destroy', $price))
        ->assertRedirect(route('admin.ai-prices.index'));

    $this->assertDatabaseMissing('ai_model_prices', ['id' => $price->id]);
});

test('store persists nested rate limits', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.ai-prices.store'), [
            'provider' => 'openai',
            'model' => 'gpt-99-test',
            'input_per_mtok' => 1.23,
            'output_per_mtok' => 4.56,
            'cache_read_per_mtok' => 0.10,
            'cache_write_per_mtok' => 0.50,
            'reasoning_per_mtok' => 4.56,
            'rate_limits' => [
                ['metric' => 'requests', 'period' => 'minute', 'limit_value' => 500],
                ['metric' => 'tokens', 'period' => 'day', 'limit_value' => 1_000_000],
            ],
        ])
        ->assertRedirect(route('admin.ai-prices.index'));

    $aiModelPrice = AiModelPrice::query()->where('model', 'gpt-99-test')->firstOrFail();

    expect($aiModelPrice->rateLimits()->count())->toBe(2);
    $this->assertDatabaseHas('ai_model_rate_limits', [
        'ai_model_price_id' => $aiModelPrice->id,
        'metric' => 'requests',
        'period' => 'minute',
        'limit_value' => 500,
    ]);
});

test('update replaces the rate limit set', function (): void {
    $admin = User::factory()->admin()->create();
    $price = AiModelPrice::factory()->create();
    $price->rateLimits()->create(['metric' => 'requests', 'period' => 'minute', 'limit_value' => 100]);

    $this->actingAs($admin)
        ->put(route('admin.ai-prices.update', $price), [
            'input_per_mtok' => 1.00,
            'output_per_mtok' => 2.00,
            'cache_read_per_mtok' => 0.10,
            'cache_write_per_mtok' => 0.20,
            'reasoning_per_mtok' => 2.00,
            'rate_limits' => [
                ['metric' => 'tokens', 'period' => 'hour', 'limit_value' => 250_000],
            ],
        ])
        ->assertRedirect(route('admin.ai-prices.index'));

    expect($price->fresh()->rateLimits()->count())->toBe(1);
    $this->assertDatabaseMissing('ai_model_rate_limits', ['metric' => 'requests', 'period' => 'minute']);
    $this->assertDatabaseHas('ai_model_rate_limits', ['metric' => 'tokens', 'period' => 'hour', 'limit_value' => 250_000]);
});

test('update with no rate_limits key clears the set', function (): void {
    $admin = User::factory()->admin()->create();
    $price = AiModelPrice::factory()->create();
    $price->rateLimits()->create(['metric' => 'requests', 'period' => 'minute', 'limit_value' => 100]);

    $this->actingAs($admin)
        ->put(route('admin.ai-prices.update', $price), [
            'input_per_mtok' => 1.00,
            'output_per_mtok' => 2.00,
            'cache_read_per_mtok' => 0.10,
            'cache_write_per_mtok' => 0.20,
            'reasoning_per_mtok' => 2.00,
        ])
        ->assertRedirect(route('admin.ai-prices.index'));

    expect($price->fresh()->rateLimits()->count())->toBe(0);
});

test('store rejects invalid metric, period, value and duplicate combos', function (): void {
    $admin = User::factory()->admin()->create();

    $base = [
        'provider' => 'openai',
        'model' => 'gpt-99-test',
        'input_per_mtok' => 1.23,
        'output_per_mtok' => 4.56,
        'cache_read_per_mtok' => 0.10,
        'cache_write_per_mtok' => 0.50,
        'reasoning_per_mtok' => 4.56,
    ];

    $this->actingAs($admin)
        ->post(route('admin.ai-prices.store'), $base + [
            'rate_limits' => [['metric' => 'bananas', 'period' => 'minute', 'limit_value' => 10]],
        ])
        ->assertSessionHasErrors('rate_limits.0.metric');

    $this->actingAs($admin)
        ->post(route('admin.ai-prices.store'), $base + [
            'rate_limits' => [['metric' => 'requests', 'period' => 'fortnight', 'limit_value' => 10]],
        ])
        ->assertSessionHasErrors('rate_limits.0.period');

    $this->actingAs($admin)
        ->post(route('admin.ai-prices.store'), $base + [
            'rate_limits' => [['metric' => 'requests', 'period' => 'minute', 'limit_value' => 0]],
        ])
        ->assertSessionHasErrors('rate_limits.0.limit_value');

    $this->actingAs($admin)
        ->post(route('admin.ai-prices.store'), $base + [
            'rate_limits' => [
                ['metric' => 'requests', 'period' => 'minute', 'limit_value' => 10],
                ['metric' => 'requests', 'period' => 'minute', 'limit_value' => 20],
            ],
        ])
        ->assertSessionHasErrors('rate_limits.1.metric');
});

test('manual create locks the price and marks the source manual', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.ai-prices.store'), [
            'provider' => 'openai',
            'model' => 'gpt-lock-test',
            'input_per_mtok' => 1.23,
            'output_per_mtok' => 4.56,
            'cache_read_per_mtok' => 0.10,
            'cache_write_per_mtok' => 0.50,
            'reasoning_per_mtok' => 4.56,
        ])
        ->assertRedirect(route('admin.ai-prices.index'));

    $aiModelPrice = AiModelPrice::query()->where('model', 'gpt-lock-test')->firstOrFail();

    expect($aiModelPrice->is_price_locked)->toBeTrue();
    expect($aiModelPrice->pricing_source)->toBe(PricingSource::Manual);
});

test('manual create with automatic updates enabled leaves the price unlocked', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.ai-prices.store'), [
            'provider' => 'openai',
            'model' => 'gpt-auto-test',
            'input_per_mtok' => 1.23,
            'output_per_mtok' => 4.56,
            'cache_read_per_mtok' => 0.10,
            'cache_write_per_mtok' => 0.50,
            'reasoning_per_mtok' => 4.56,
            'automatic_updates_enabled' => true,
        ])
        ->assertRedirect(route('admin.ai-prices.index'));

    $aiModelPrice = AiModelPrice::query()->where('model', 'gpt-auto-test')->firstOrFail();

    expect($aiModelPrice->is_price_locked)->toBeFalse();
    expect($aiModelPrice->pricing_source)->toBe(PricingSource::Manual);
});

test('editing a price locks it and marks the source manual', function (): void {
    $admin = User::factory()->admin()->create();
    $aiModelPrice = AiModelPrice::factory()->create([
        'input_per_mtok' => 0.40,
        'output_per_mtok' => 1.60,
        'cache_read_per_mtok' => 0.10,
        'cache_write_per_mtok' => 0.40,
        'reasoning_per_mtok' => 1.60,
        'pricing_source' => PricingSource::ModelsDev,
        'is_price_locked' => false,
    ]);

    $this->actingAs($admin)
        ->put(route('admin.ai-prices.update', $aiModelPrice), [
            'input_per_mtok' => 0.50,
            'output_per_mtok' => 1.60,
            'cache_read_per_mtok' => 0.10,
            'cache_write_per_mtok' => 0.40,
            'reasoning_per_mtok' => 1.60,
        ])
        ->assertRedirect(route('admin.ai-prices.index'));

    $fresh = $aiModelPrice->fresh();
    expect($fresh->is_price_locked)->toBeTrue();
    expect($fresh->pricing_source)->toBe(PricingSource::Manual);
});

test('re-enabling automatic updates unlocks the price and leaves the source untouched', function (): void {
    $admin = User::factory()->admin()->create();
    $aiModelPrice = AiModelPrice::factory()->create([
        'input_per_mtok' => 0.40,
        'output_per_mtok' => 1.60,
        'cache_read_per_mtok' => 0.10,
        'cache_write_per_mtok' => 0.40,
        'reasoning_per_mtok' => 1.60,
        'pricing_source' => PricingSource::ModelsDev,
        'is_price_locked' => true,
    ]);

    // Even when a price also changes, an explicit re-enable of automatic
    // updates wins: the row unlocks and the source is left for the next sync.
    $this->actingAs($admin)
        ->put(route('admin.ai-prices.update', $aiModelPrice), [
            'input_per_mtok' => 0.50,
            'output_per_mtok' => 1.60,
            'cache_read_per_mtok' => 0.10,
            'cache_write_per_mtok' => 0.40,
            'reasoning_per_mtok' => 1.60,
            'automatic_updates_enabled' => true,
        ])
        ->assertRedirect(route('admin.ai-prices.index'));

    $fresh = $aiModelPrice->fresh();
    expect($fresh->is_price_locked)->toBeFalse();
    expect($fresh->pricing_source)->toBe(PricingSource::ModelsDev);
});

test('disabling automatic updates without a price change locks the row and leaves the source untouched', function (): void {
    $admin = User::factory()->admin()->create();
    $aiModelPrice = AiModelPrice::factory()->create([
        'input_per_mtok' => 0.40,
        'output_per_mtok' => 1.60,
        'cache_read_per_mtok' => 0.10,
        'cache_write_per_mtok' => 0.40,
        'reasoning_per_mtok' => 1.60,
        'pricing_source' => PricingSource::ModelsDev,
        'is_price_locked' => false,
    ]);

    // Explicitly turning the toggle off must lock the row even when no price
    // field changed. The stored price's origin did not change, so the
    // pricing_source is deliberately left untouched.
    $this->actingAs($admin)
        ->put(route('admin.ai-prices.update', $aiModelPrice), [
            'input_per_mtok' => 0.40,
            'output_per_mtok' => 1.60,
            'cache_read_per_mtok' => 0.10,
            'cache_write_per_mtok' => 0.40,
            'reasoning_per_mtok' => 1.60,
            'automatic_updates_enabled' => false,
        ])
        ->assertRedirect(route('admin.ai-prices.index'));

    $fresh = $aiModelPrice->fresh();
    expect($fresh->is_price_locked)->toBeTrue();
    expect($fresh->pricing_source)->toBe(PricingSource::ModelsDev);
});

test('disabling automatic updates with a price change locks the row and marks the source manual', function (): void {
    $admin = User::factory()->admin()->create();
    $aiModelPrice = AiModelPrice::factory()->create([
        'input_per_mtok' => 0.40,
        'output_per_mtok' => 1.60,
        'cache_read_per_mtok' => 0.10,
        'cache_write_per_mtok' => 0.40,
        'reasoning_per_mtok' => 1.60,
        'pricing_source' => PricingSource::ModelsDev,
        'is_price_locked' => false,
    ]);

    // Explicit off combined with a real price edit keeps the existing behavior:
    // the row locks and takes manual ownership of the price.
    $this->actingAs($admin)
        ->put(route('admin.ai-prices.update', $aiModelPrice), [
            'input_per_mtok' => 0.50,
            'output_per_mtok' => 1.60,
            'cache_read_per_mtok' => 0.10,
            'cache_write_per_mtok' => 0.40,
            'reasoning_per_mtok' => 1.60,
            'automatic_updates_enabled' => false,
        ])
        ->assertRedirect(route('admin.ai-prices.index'));

    $fresh = $aiModelPrice->fresh();
    expect($fresh->is_price_locked)->toBeTrue();
    expect($fresh->pricing_source)->toBe(PricingSource::Manual);
});

test('an edit with no toggle and no price change leaves the row unlocked', function (): void {
    $admin = User::factory()->admin()->create();
    $aiModelPrice = AiModelPrice::factory()->create([
        'input_per_mtok' => 0.40,
        'output_per_mtok' => 1.60,
        'cache_read_per_mtok' => 0.10,
        'cache_write_per_mtok' => 0.40,
        'reasoning_per_mtok' => 1.60,
        'pricing_source' => PricingSource::ModelsDev,
        'is_price_locked' => false,
    ]);

    $this->actingAs($admin)
        ->put(route('admin.ai-prices.update', $aiModelPrice), [
            'input_per_mtok' => 0.40,
            'output_per_mtok' => 1.60,
            'cache_read_per_mtok' => 0.10,
            'cache_write_per_mtok' => 0.40,
            'reasoning_per_mtok' => 1.60,
        ])
        ->assertRedirect(route('admin.ai-prices.index'));

    $fresh = $aiModelPrice->fresh();
    expect($fresh->is_price_locked)->toBeFalse();
    expect($fresh->pricing_source)->toBe(PricingSource::ModelsDev);
});

test('a free-usage-pool-only edit does not lock the price', function (): void {
    $admin = User::factory()->admin()->create();
    $pool = AiFreeUsagePool::factory()->create();
    $aiModelPrice = AiModelPrice::factory()->create([
        'input_per_mtok' => 0.40,
        'output_per_mtok' => 1.60,
        'cache_read_per_mtok' => 0.10,
        'cache_write_per_mtok' => 0.40,
        'reasoning_per_mtok' => 1.60,
        'pricing_source' => PricingSource::ModelsDev,
        'is_price_locked' => false,
    ]);

    $this->actingAs($admin)
        ->put(route('admin.ai-prices.update', $aiModelPrice), [
            'input_per_mtok' => 0.40,
            'output_per_mtok' => 1.60,
            'cache_read_per_mtok' => 0.10,
            'cache_write_per_mtok' => 0.40,
            'reasoning_per_mtok' => 1.60,
            'free_usage_pool_id' => $pool->id,
        ])
        ->assertRedirect(route('admin.ai-prices.index'));

    $fresh = $aiModelPrice->fresh();
    expect($fresh->is_price_locked)->toBeFalse();
    expect($fresh->pricing_source)->toBe(PricingSource::ModelsDev);
    expect($fresh->free_usage_pool_id)->toBe($pool->id);
});

test('a rate-limit-only edit does not lock the price', function (): void {
    $admin = User::factory()->admin()->create();
    $aiModelPrice = AiModelPrice::factory()->create([
        'input_per_mtok' => 0.40,
        'output_per_mtok' => 1.60,
        'cache_read_per_mtok' => 0.10,
        'cache_write_per_mtok' => 0.40,
        'reasoning_per_mtok' => 1.60,
        'pricing_source' => PricingSource::ModelsDev,
        'is_price_locked' => false,
    ]);

    $this->actingAs($admin)
        ->put(route('admin.ai-prices.update', $aiModelPrice), [
            'input_per_mtok' => 0.40,
            'output_per_mtok' => 1.60,
            'cache_read_per_mtok' => 0.10,
            'cache_write_per_mtok' => 0.40,
            'reasoning_per_mtok' => 1.60,
            'rate_limits' => [
                ['metric' => 'requests', 'period' => 'minute', 'limit_value' => 500],
            ],
        ])
        ->assertRedirect(route('admin.ai-prices.index'));

    $fresh = $aiModelPrice->fresh();
    expect($fresh->is_price_locked)->toBeFalse();
    expect($fresh->pricing_source)->toBe(PricingSource::ModelsDev);
    expect($fresh->rateLimits()->count())->toBe(1);
});

test('a batch-price change locks the row and marks the source manual', function (): void {
    $admin = User::factory()->admin()->create();
    $aiModelPrice = AiModelPrice::factory()->create([
        'input_per_mtok' => 0.40,
        'output_per_mtok' => 1.60,
        'cache_read_per_mtok' => 0.10,
        'cache_write_per_mtok' => 0.40,
        'reasoning_per_mtok' => 1.60,
        'batch_input_per_mtok' => 0.20,
        'pricing_source' => PricingSource::ModelsDev,
        'is_price_locked' => false,
    ]);

    $this->actingAs($admin)
        ->put(route('admin.ai-prices.update', $aiModelPrice), [
            'input_per_mtok' => 0.40,
            'output_per_mtok' => 1.60,
            'cache_read_per_mtok' => 0.10,
            'cache_write_per_mtok' => 0.40,
            'reasoning_per_mtok' => 1.60,
            'batch_input_per_mtok' => 0.99,
        ])
        ->assertRedirect(route('admin.ai-prices.index'));

    $fresh = $aiModelPrice->fresh();
    expect($fresh->is_price_locked)->toBeTrue();
    expect($fresh->pricing_source)->toBe(PricingSource::Manual);
});

test('index exposes provenance, lock, and derived automatic updates fields', function (): void {
    $admin = User::factory()->admin()->create();
    AiModelPrice::factory()->create([
        'pricing_source' => PricingSource::ModelsDev,
        'pricing_source_url' => 'https://models.dev/openai',
        'pricing_synced_at' => now(),
        'pricing_verified_at' => now(),
        'is_price_locked' => true,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.ai-prices.index'))
        ->assertInertia(fn ($page) => $page
            ->component('Admin/AiPrices/Index')
            ->where('prices.0.pricing_source', 'models_dev')
            ->where('prices.0.pricing_source_url', 'https://models.dev/openai')
            ->where('prices.0.is_price_locked', true)
            ->where('prices.0.automatic_updates_enabled', false)
            ->has('prices.0.pricing_synced_at')
            ->has('prices.0.pricing_verified_at'));
});

test('index exposes rate limits on price rows', function (): void {
    $admin = User::factory()->admin()->create();
    $price = AiModelPrice::factory()->create();
    $price->rateLimits()->create(['metric' => 'requests', 'period' => 'minute', 'limit_value' => 500]);

    $this->actingAs($admin)
        ->get(route('admin.ai-prices.index'))
        ->assertInertia(fn ($page) => $page
            ->component('Admin/AiPrices/Index')
            ->where('prices.0.rate_limits.0.metric', 'requests')
            ->where('prices.0.rate_limits.0.limit_value', 500));
});
