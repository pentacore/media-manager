<?php

declare(strict_types=1);

use App\Enums\AiTask;
use App\Enums\FreePoolOverflowBehavior;
use App\Models\AiFreeUsagePool;
use App\Models\AiModelPrice;
use App\Models\AiTaskModel;
use App\Models\AiUsageRecord;
use App\Models\User;
use App\Settings\DecisionAgentSettings;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-test');

    $pool = AiFreeUsagePool::factory()->unified(1_000_000)->overflow(FreePoolOverflowBehavior::Split)->create(['name' => 'Luna free']);
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5.6-luna', 'supports_reasoning' => true, 'free_usage_pool_id' => $pool->id]);
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5-nano', 'supports_reasoning' => true]);
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5-nano')->create();
});

test('admin adds a pool-conditioned tier above the fallback', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-models.index', absolute: false))
        ->assertNoSmoke()
        ->assertMissing('[data-task="chat"] [data-tier-always]')
        ->click('[data-task="chat"] [data-tier-add]')
        ->assertSeeIn('[data-task="chat"] [data-tier-row="1"] [data-tier-always]', 'Always')
        ->assertSeeIn('[data-task="chat"] [data-tier-row="1"] [data-model-select]', 'gpt-5-nano')
        ->click('[data-task="chat"] [data-tier-row="0"] [data-model-select] button')
        ->click('[role="option"][aria-label="gpt-5.6-luna"]')
        ->assertSeeIn('[data-task="chat"] [data-tier-row="0"] [data-tier-pool]', 'Luna free · 100% left')
        ->fill('[data-task="chat"] [data-tier-row="0"] [data-tier-min-percent]', '20')
        ->click('Save models')
        ->assertSee('AI models updated.')
        ->assertSeeIn('[data-task="chat"] [data-tier-live]', 'Live: tier 1 of 2');

    expect(AiTaskModel::query()->forTask(AiTask::Chat)->orderBy('position')->get()->map->only(['position', 'model', 'min_pool_percent'])->all())
        ->toBe([
            ['position' => 0, 'model' => 'gpt-5.6-luna', 'min_pool_percent' => 20],
            ['position' => 1, 'model' => 'gpt-5-nano', 'min_pool_percent' => null],
        ]);
});

test('the live badge shows a fall-through and why', function (): void {
    AiTaskModel::query()->delete();
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->conditions(20)->create();
    AiTaskModel::factory()->task(AiTask::Chat)->position(1)->selecting('openai', 'gpt-5-nano')->create();
    AiUsageRecord::factory()->create(['provider' => 'openai', 'model' => 'gpt-5.6-luna', 'prompt_tokens' => 900_000, 'completion_tokens' => 0]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-models.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-task="chat"] [data-tier-live]', 'Live: tier 2 of 2')
        ->assertAttributeContains('[data-task="chat"] [data-tier-live]', 'title', 'Luna free below 20% (10% left)')
        ->assertSeeIn('[data-task="chat"] [data-tier-row="0"] [data-tier-pool]', 'Luna free · 10% left');
});

test('tiers can be reordered and removed', function (): void {
    AiTaskModel::query()->delete();
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->create();
    AiTaskModel::factory()->task(AiTask::Chat)->position(1)->selecting('openai', 'gpt-5-nano')->create();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-models.index', absolute: false))
        ->assertNoSmoke()
        ->click('[data-task="chat"] [data-tier-row="0"] [data-tier-down]')
        ->assertSeeIn('[data-task="chat"] [data-tier-row="0"] [data-model-select]', 'gpt-5-nano')
        ->assertSeeIn('[data-task="chat"] [data-tier-row="1"] [data-model-select]', 'gpt-5.6-luna')
        ->click('Save models')
        ->assertSee('AI models updated.');

    expect(AiTaskModel::query()->forTask(AiTask::Chat)->orderBy('position')->pluck('model')->all())->toBe(['gpt-5-nano', 'gpt-5.6-luna']);

    visit(route('admin.ai-models.index', absolute: false))
        ->assertNoSmoke()
        ->click('[data-task="chat"] [data-tier-row="1"] [data-tier-remove]')
        ->assertMissing('[data-task="chat"] [data-tier-row="1"]')
        ->assertMissing('[data-task="chat"] [data-tier-remove]')
        ->click('Save models')
        ->assertSee('AI models updated.');

    expect(AiTaskModel::query()->forTask(AiTask::Chat)->pluck('model')->all())->toBe(['gpt-5-nano']);
});

test('a model without a free pool disables the conditions', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-models.index', absolute: false))
        ->assertNoSmoke()
        ->click('[data-task="chat"] [data-tier-add]')
        ->click('[data-task="chat"] [data-tier-row="0"] [data-model-select] button')
        ->click('[role="option"][aria-label="gpt-5-nano"]')
        ->assertSeeIn('[data-task="chat"] [data-tier-row="0"] [data-tier-pool]', 'No free pool — always eligible')
        ->assertDisabled('[data-task="chat"] [data-tier-row="0"] [data-tier-min-percent]')
        ->assertDisabled('[data-task="chat"] [data-tier-row="0"] [data-tier-min-tokens]');
});

test('saving a new tier without a model asks for one', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-models.index', absolute: false))
        ->assertNoSmoke()
        ->click('[data-task="chat"] [data-tier-add]')
        ->click('Save models')
        ->assertSeeIn('[data-task="chat"] [data-tier-row="0"]', 'Pick a model for this tier.');

    expect(AiTaskModel::query()->forTask(AiTask::Chat)->pluck('model')->all())->toBe(['gpt-5-nano']);
});

test('an event override can hold tiers', function (): void {
    resolve(DecisionAgentSettings::class)->setEventAllowlist(['sonarr:Download']);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-models.index', absolute: false))
        ->assertNoSmoke()
        ->click('[data-add-event-override] button')
        ->click('[data-event-option="sonarr:Download"]')
        ->click('[data-event-override="sonarr:Download"] [data-tier-add]')
        ->click('[data-event-override="sonarr:Download"] [data-tier-row="0"] [data-model-select] button')
        ->click('[role="option"][aria-label="gpt-5.6-luna"]')
        ->fill('[data-event-override="sonarr:Download"] [data-tier-row="0"] [data-tier-min-tokens]', '50000')
        ->assertSeeIn('[data-event-override="sonarr:Download"] [data-tier-row="1"] [data-tier-always]', 'Always')
        ->click('Save models')
        ->assertSee('AI models updated.')
        ->assertSeeIn('[data-event-override="sonarr:Download"] [data-override-resolved]', 'openai · gpt-5.6-luna');

    expect(AiTaskModel::query()->where('scope', 'sonarr:Download')->orderBy('position')->get()->map->only(['model', 'min_pool_tokens'])->all())
        ->toBe([
            ['model' => 'gpt-5.6-luna', 'min_pool_tokens' => 50_000],
            ['model' => null, 'min_pool_tokens' => null],
        ]);
});

test('switching to a model without a pool clears the conditions', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-models.index', absolute: false))
        ->assertNoSmoke()
        ->click('[data-task="chat"] [data-tier-add]')
        ->click('[data-task="chat"] [data-tier-row="0"] [data-model-select] button')
        ->click('[role="option"][aria-label="gpt-5.6-luna"]')
        ->fill('[data-task="chat"] [data-tier-row="0"] [data-tier-min-percent]', '20')
        ->click('[data-task="chat"] [data-tier-row="0"] [data-model-select] button')
        ->click('[role="option"][aria-label="gpt-5-nano"]')
        ->assertDisabled('[data-task="chat"] [data-tier-row="0"] [data-tier-min-percent]')
        ->assertValue('[data-task="chat"] [data-tier-row="0"] [data-tier-min-percent]', '')
        ->click('Save models')
        ->assertSee('AI models updated.');

    expect(AiTaskModel::query()->forTask(AiTask::Chat)->orderBy('position')->get()->map->only(['model', 'min_pool_percent'])->all())
        ->toBe([
            ['model' => 'gpt-5-nano', 'min_pool_percent' => null],
            ['model' => 'gpt-5-nano', 'min_pool_percent' => null],
        ]);
});

test('a condition left on a model whose pool was unlinked can be cleared and saved', function (): void {
    AiTaskModel::query()->delete();
    AiModelPrice::query()->where('model', 'gpt-5.6-luna')->update(['free_usage_pool_id' => null]);
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5.6-luna')->conditions(20)->create();
    AiTaskModel::factory()->task(AiTask::Chat)->position(1)->selecting('openai', 'gpt-5-nano')->create();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-models.index', absolute: false))
        ->assertNoSmoke()
        ->assertEnabled('[data-task="chat"] [data-tier-row="0"] [data-tier-min-percent]')
        ->assertValue('[data-task="chat"] [data-tier-row="0"] [data-tier-min-percent]', '20')
        ->fill('[data-task="chat"] [data-tier-row="0"] [data-tier-min-percent]', '')
        ->click('Save models')
        ->assertSee('AI models updated.');

    expect(AiTaskModel::query()->forTask(AiTask::Chat)->orderBy('position')->get()->map->only(['model', 'min_pool_percent'])->all())
        ->toBe([
            ['model' => 'gpt-5.6-luna', 'min_pool_percent' => null],
            ['model' => 'gpt-5-nano', 'min_pool_percent' => null],
        ]);
});
