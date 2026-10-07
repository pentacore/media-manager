<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Models\AiModelPrice;
use App\Models\AiTaskModel;
use App\Models\User;
use App\Settings\AiSettings;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
    config()->set('ai.default', 'openai');
    // A priced chat selection keeps the chat-following tasks out of the list.
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5.6-luna']);
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->create();
    AiTaskModel::factory()->task(AiTask::FileInspector)->selecting('openai', 'mystery-model')->create();
});

test('admins see which selected models the hard cap cannot price', function (): void {
    resolve(AiSettings::class)->setHardBudgetUsd(10.0);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-models.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-unpriced-model-warning]', 'Some selected models have no price')
        ->assertSeeIn('[data-unpriced-model-warning]', 'File inspector')
        ->assertSeeIn('[data-unpriced-model-warning]', 'openai/mystery-model');

    visit(route('admin.ai-usage.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-unpriced-model-warning]', 'openai/mystery-model');
});

test('no warning is shown without a hard cap', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-models.index', absolute: false))
        ->assertNoSmoke()
        ->assertMissing('[data-unpriced-model-warning]');

    visit(route('admin.ai-usage.index', absolute: false))
        ->assertNoSmoke()
        ->assertMissing('[data-unpriced-model-warning]');
});
