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
