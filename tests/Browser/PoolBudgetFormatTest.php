<?php

declare(strict_types=1);

use App\Models\AiFreeUsagePool;
use App\Models\User;

/*
 * Pool budgets render through formatCount() (en-US grouping) on both AI
 * pages, so a German browser reads the same "1,250,000" the server render
 * wrote: a browser-locale formatter wrote "1.250.000" and mismatched SSR.
 */
beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

test('a pool budget on AI Prices is grouped the same in a German browser', function (): void {
    $aiFreeUsagePool = AiFreeUsagePool::factory()->unified(1_250_000)->create(['name' => 'Example pool']);
    $this->actingAs(User::factory()->admin()->create());
    $row = sprintf('[data-pool-row="%d"]', $aiFreeUsagePool->id);

    visit(route('admin.ai-prices.index', absolute: false))
        ->withLocale('de-DE')
        ->assertNoSmoke()
        ->assertSeeIn($row, '1,250,000')
        ->assertDontSeeIn($row, '1.250.000');
});

test('a pool budget on AI Usage is grouped the same in a German browser', function (): void {
    $aiFreeUsagePool = AiFreeUsagePool::factory()->unified(1_250_000)->create(['name' => 'Example pool']);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-usage.index', absolute: false))
        ->withLocale('de-DE')
        ->assertNoSmoke()
        ->assertSeeIn(sprintf('[data-usage-free-pool="%d"]', $aiFreeUsagePool->id), '0 / 1,250,000');
});
