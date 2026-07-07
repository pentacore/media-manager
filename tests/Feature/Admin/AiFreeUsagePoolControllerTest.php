<?php

declare(strict_types=1);

use App\Models\AiFreeUsagePool;
use App\Models\AiModelPrice;
use App\Models\User;

test('guests and non-admins cannot manage pools', function (): void {
    $this->post(route('admin.ai-free-usage-pools.store'), [])
        ->assertRedirect(route('login'));

    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->post(route('admin.ai-free-usage-pools.store'), [])
        ->assertForbidden();
});

test('admin can create a split pool', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.ai-free-usage-pools.store'), [
            'name' => 'Gemini free tier',
            'period' => 'daily',
            'unified' => false,
            'free_input_tokens' => 1_000_000,
            'free_output_tokens' => 250_000,
            'documentation_url' => 'https://ai.google.dev/pricing',
        ])
        ->assertRedirect(route('admin.ai-prices.index'));

    $this->assertDatabaseHas('ai_free_usage_pools', [
        'name' => 'Gemini free tier',
        'period' => 'daily',
        'unified' => false,
        'free_input_tokens' => 1_000_000,
        'documentation_url' => 'https://ai.google.dev/pricing',
    ]);
});

test('admin can create a unified pool', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.ai-free-usage-pools.store'), [
            'name' => 'Mistral shared',
            'period' => 'monthly',
            'unified' => true,
            'free_total_tokens' => 2_000_000,
        ])
        ->assertRedirect(route('admin.ai-prices.index'));

    $this->assertDatabaseHas('ai_free_usage_pools', [
        'name' => 'Mistral shared',
        'unified' => true,
        'free_total_tokens' => 2_000_000,
    ]);
});

test('unified pools require a total token budget', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.ai-free-usage-pools.store'), [
            'name' => 'Broken pool',
            'period' => 'monthly',
            'unified' => true,
        ])
        ->assertSessionHasErrors('free_total_tokens');
});

test('split pools require at least one of input or output budgets', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.ai-free-usage-pools.store'), [
            'name' => 'Empty pool',
            'period' => 'weekly',
            'unified' => false,
        ])
        ->assertSessionHasErrors('free_input_tokens');
});

test('pool names must be unique', function (): void {
    $admin = User::factory()->admin()->create();
    AiFreeUsagePool::factory()->create(['name' => 'Taken']);

    $this->actingAs($admin)
        ->post(route('admin.ai-free-usage-pools.store'), [
            'name' => 'Taken',
            'period' => 'monthly',
            'unified' => false,
            'free_input_tokens' => 1,
        ])
        ->assertSessionHasErrors('name');
});

test('admin can update a pool keeping its own name', function (): void {
    $admin = User::factory()->admin()->create();
    $pool = AiFreeUsagePool::factory()->create(['name' => 'My pool']);

    $this->actingAs($admin)
        ->put(route('admin.ai-free-usage-pools.update', $pool), [
            'name' => 'My pool',
            'period' => 'weekly',
            'unified' => false,
            'free_input_tokens' => 42,
        ])
        ->assertRedirect(route('admin.ai-prices.index'));

    expect($pool->fresh()->period->value)->toBe('weekly');
    expect($pool->fresh()->free_input_tokens)->toBe(42);
});

test('admin can delete a pool and member prices are detached', function (): void {
    $admin = User::factory()->admin()->create();
    $pool = AiFreeUsagePool::factory()->create();
    $price = AiModelPrice::factory()->create(['free_usage_pool_id' => $pool->id]);

    $this->actingAs($admin)
        ->delete(route('admin.ai-free-usage-pools.destroy', $pool))
        ->assertRedirect(route('admin.ai-prices.index'));

    $this->assertDatabaseMissing('ai_free_usage_pools', ['id' => $pool->id]);
    expect($price->fresh()->free_usage_pool_id)->toBeNull();
});

test('prices index exposes pools with member counts', function (): void {
    $admin = User::factory()->admin()->create();
    $pool = AiFreeUsagePool::factory()->create(['name' => 'Visible pool']);
    AiModelPrice::factory()->create(['free_usage_pool_id' => $pool->id]);

    $this->actingAs($admin)
        ->get(route('admin.ai-prices.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/AiPrices/Index')
            ->has('pools', 1)
            ->where('pools.0.name', 'Visible pool')
            ->where('pools.0.prices_count', 1)
        );
});

test('admin can assign a pool when updating a price', function (): void {
    $admin = User::factory()->admin()->create();
    $pool = AiFreeUsagePool::factory()->create();
    $price = AiModelPrice::factory()->create();

    $this->actingAs($admin)
        ->put(route('admin.ai-prices.update', $price), [
            'input_per_mtok' => 0.50,
            'output_per_mtok' => 2.00,
            'cache_read_per_mtok' => 0,
            'cache_write_per_mtok' => 0,
            'reasoning_per_mtok' => 0,
            'free_usage_pool_id' => $pool->id,
        ])
        ->assertRedirect(route('admin.ai-prices.index'));

    expect($price->fresh()->free_usage_pool_id)->toBe($pool->id);
});
