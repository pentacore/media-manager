<?php

declare(strict_types=1);

use App\Events\AiPriceRefreshStateChanged;
use App\Jobs\RefreshAiPricesJob;
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

    $price = AiModelPrice::query()->where('model', 'gpt-99-test')->firstOrFail();

    expect($price->rateLimits()->count())->toBe(2);
    $this->assertDatabaseHas('ai_model_rate_limits', [
        'ai_model_price_id' => $price->id,
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
