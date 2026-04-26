<?php

declare(strict_types=1);

use App\Models\AiModelPrice;
use App\Models\User;

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
