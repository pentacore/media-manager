<?php

declare(strict_types=1);

use App\Enums\FreeUsagePeriod;
use App\Models\AiFreeUsagePool;
use App\Models\AiModelPrice;
use App\Models\User;

test('admin can create a free usage pool from the AI prices page', function (): void {
    config()->set('mediamanager.ai.enabled', true);

    $this->actingAs(User::factory()->admin()->create());

    visit('/admin/ai-prices')
        ->assertNoSmoke()
        ->click('Add pool')
        ->fill('name', 'Gemini free tier')
        ->fill('free_input_tokens', '1000000')
        ->click('Save')
        ->assertSee('Free usage pool added.');

    expect(AiFreeUsagePool::query()->where('name', 'Gemini free tier')->exists())->toBeTrue();
});

test('the pools table lists each pool with its period, budget, members and docs link', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    $splitPool = AiFreeUsagePool::factory()->create([
        'name' => 'Gemini free tier',
        'documentation_url' => 'https://ai.google.dev/pricing',
    ]);
    $unifiedPool = AiFreeUsagePool::factory()
        ->unified(2_000_000)
        ->period(FreeUsagePeriod::Weekly)
        ->create(['name' => 'Shared tier']);
    AiModelPrice::factory()->create(['model' => 'gemini-3-flash', 'free_usage_pool_id' => $splitPool->id]);

    $this->actingAs(User::factory()->admin()->create());

    $splitRow = sprintf('[data-pool-row="%d"]', $splitPool->id);
    $unifiedRow = sprintf('[data-pool-row="%d"]', $unifiedPool->id);

    visit('/admin/ai-prices')
        ->assertNoSmoke()
        ->assertSeeIn('[data-pools-card]', 'Free usage pools')
        ->assertSeeIn($splitRow, 'Gemini free tier')
        ->assertSeeIn($splitRow, 'monthly')
        ->assertSeeIn($splitRow, '1,000,000')
        ->assertSeeIn($splitRow, '500,000')
        ->assertScript(sprintf("document.querySelector('%s td:nth-child(4)').textContent.trim()", $splitRow), '1')
        ->assertAttribute($splitRow.' a', 'href', 'https://ai.google.dev/pricing')
        ->assertSeeIn($unifiedRow, 'weekly')
        ->assertSeeIn($unifiedRow, '2,000,000')
        ->assertSeeIn($unifiedRow, 'total')
        ->assertScript(sprintf("document.querySelector('%s td:nth-child(4)').textContent.trim()", $unifiedRow), '0')
        ->assertSeeIn($unifiedRow, '—')
        ->assertMissing($unifiedRow.' a');
});

test('an admin edits a pool from its row', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    $aiFreeUsagePool = AiFreeUsagePool::factory()->create(['name' => 'Gemini free tier']);
    $row = sprintf('[data-pool-row="%d"]', $aiFreeUsagePool->id);

    $this->actingAs(User::factory()->admin()->create());

    visit('/admin/ai-prices')
        ->assertNoSmoke()
        ->click($row.' [data-pool-edit]')
        ->assertSeeIn('[data-edit-pool-dialog]', 'Edit Gemini free tier')
        ->assertValue('#edit_pool_name', 'Gemini free tier')
        ->fill('#edit_pool_name', 'Gemini paid tier')
        ->click('[data-edit-pool-dialog] button[type="submit"]')
        ->assertSee('Free usage pool updated.')
        ->assertMissing('[data-edit-pool-dialog]')
        ->assertSeeIn($row, 'Gemini paid tier');

    expect($aiFreeUsagePool->fresh()->name)->toBe('Gemini paid tier');
});

test('an empty pool list explains what pools are for', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    $this->actingAs(User::factory()->admin()->create());

    visit('/admin/ai-prices')
        ->assertNoSmoke()
        ->assertSeeIn('[data-pools-card]', 'No pools yet. Pools let several models share one free-usage budget.');
});
