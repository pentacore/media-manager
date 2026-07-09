<?php

declare(strict_types=1);

use App\Models\AiFreeUsagePool;
use App\Models\User;

test('admin can create a free usage pool from the AI prices page', function (): void {
    config()->set('mediamanager.ai.enabled', true);

    $this->actingAs(User::factory()->admin()->create());

    visit('/admin/ai-prices')
        ->assertNoJavaScriptErrors()
        ->click('Add pool')
        ->fill('name', 'Gemini free tier')
        ->fill('free_input_tokens', '1000000')
        ->click('Save')
        ->assertSee('Free usage pool added.');

    expect(AiFreeUsagePool::query()->where('name', 'Gemini free tier')->exists())->toBeTrue();
});
